<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Paths;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\MailQueueRepository;
use App\Repository\SettingRepository;
use App\Service\Account\PasswordPolicy;
use App\Service\Backup\RestoreService;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettings;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\MailTemplates;
use App\Service\Mail\MailTransport;
use App\Service\Mail\PublicUrl;
use App\Service\Mail\SmtpSecurity;
use App\Service\Migration\Migrator;
use App\Service\Update\DenyHtaccess;
use App\Service\Update\UpdateService;
use App\View\View;

/**
 * /install - reachable only while shared/config.php is missing (the
 * bootstrap registers these routes in install mode only). Takes the
 * database credentials, tests the connection and then either installs fresh
 * (every migration from 0, first admin, vault, recovery key) or restores an
 * uploaded backup ZIP. Writing the config is the last thing either path
 * does, which closes the installer for good.
 *
 * Steps 3 and 4 of the flow in docs/spec/06-betrieb.md section 1 - first
 * admin, vault plus recovery key - are M3-2 (issue #15) and live in
 * App\Installer\FirstAdminSetup, which this controller only orchestrates.
 * The server key is created here (ConfigWriter), because only the installer
 * can - or taken over from a backup that carried its config.php.
 *
 * The fresh-install path is itself a short step chain of three requests, for
 * the same reason as the restore below: VK_priv must never touch a session
 * file in the clear (docs/spec/01-sicherheit.md section 2), so the vault and
 * the admin are written to the database right away, and only the recovery
 * key's confirmation - proof that it was written down - releases config.php.
 *
 *   submit()     modus=frisch: migrate, create vault + admin, show the
 *                recovery key once, remember its hash in the session as
 *                $_SESSION['install_admin'].
 *   confirmKey() the last group typed back; right hash -> on to the mail
 *                step; wrong hash -> ask again, key never re-shown.
 *   mail()       step 5 of the spec (issue #249): SMTP settings plus a test
 *                mail, or skipped; either way config.php is written now,
 *                install closed. The settings go straight to the database
 *                (password under the server key), never into the session.
 *   restart()    "Neu beginnen": browser was closed before confirming: the
 *                half-finished vault/admin are removed so the form can be
 *                submitted again.
 *
 * A restore is the same kind of step chain: submit stores the dump - and,
 * for a backup with blobs, the ZIP itself - in shared/var/ and the progress
 * in the session, the page's JavaScript then calls restoreStep() until it
 * reports "fertig".
 *
 * A backup WITHOUT config.php (the default of bin/backup.php and of the
 * update chain) adds one more step before config.php is written (issue
 * #154, docs/spec/06-betrieb.md section 2): with a fresh server key no
 * account could be found at the login - `user.email_bi` is keyed from the
 * server key - so the admin either hands in the old key (the separately
 * kept config.php) or re-links their account with the recovery key and
 * their password (App\Installer\RestoreAccess). Until then
 * $_SESSION['install_restore'] stays in phase `zugang`, the installer stays
 * open, and neither the recovery key nor the password ever enters the
 * session: both are used within the request that receives them.
 *
 *   serverKey()  POST /install/serverschluessel - old key pasted or
 *                config.php uploaded; accepted only if it opens the data.
 *   access()     POST /install/zugang - recovery key + address, name and
 *                password of the admin's own account.
 */
final readonly class InstallController
{
    /** $_SESSION['install_restore']['phase'] while the access step is open. */
    public const string PHASE_ZUGANG = 'zugang';

    /** $_SESSION['install_admin']['phase'] once the recovery key is confirmed (issue #249). */
    public const string PHASE_MAIL = 'mail';

    public function __construct(
        private View $view,
        private Paths $paths,
        private Session $session,
        /**
         * Replaces is_uploaded_file() in tests: PHP only answers true for a
         * real multipart upload, which a test cannot produce.
         *
         * @var (\Closure(string): bool)|null
         */
        private ?\Closure $istUpload = null,
        /** Test seam for the mail step's test mail, like Mailer's own. */
        private ?MailTransport $mailTransport = null,
    ) {
    }

    public function form(Request $request): ResponseInterface
    {
        $this->session->start();

        if (is_array($_SESSION['install_admin'] ?? null)) {
            return $this->renderPending();
        }
        if ($this->pendingAccess() !== null) {
            return $this->renderAccess();
        }

        // Deny-all .htaccess in shared/, releases/ and current/ (issue #101).
        // setup.php already wrote them; the form repeats it so an
        // installation whose setup.php could not write them gets a second
        // try - and a visible warning instead of a silently unprotected
        // config.php. Idempotent, never overwrites.
        $ungeschuetzt = DenyHtaccess::ensureAll(dirname($this->paths->releaseRoot));

        return $this->render([
            'errors' => [],
            'values' => ['kanal' => $this->channelFromSetup()],
            'ungeschuetzt' => $ungeschuetzt === [] ? '' : DenyHtaccess::describe($ungeschuetzt),
        ]);
    }

    public function submit(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->render([
                'errors' => ['csrf' => 'Die Sitzung ist abgelaufen. Bitte das Formular erneut absenden.'],
                'values' => ['kanal' => $this->channelFromSetup()],
            ], 403);
        }

        // A resubmission (double click, browser back button) must not create
        // a second vault/admin - the pending confirmation step is shown
        // again instead of processing the form a second time.
        if (is_array($_SESSION['install_admin'] ?? null)) {
            return $this->renderPending();
        }
        // Likewise a restore that only waits for its access step: the data
        // is in, submitting the form again must not start another one.
        if ($this->pendingAccess() !== null) {
            return $this->renderAccess();
        }

        $values = [
            'db_host' => trim((string) ($request->post['db_host'] ?? 'localhost')),
            'db_port' => trim((string) ($request->post['db_port'] ?? '3306')),
            'db_name' => trim((string) ($request->post['db_name'] ?? '')),
            'db_user' => trim((string) ($request->post['db_user'] ?? '')),
            'db_password' => (string) ($request->post['db_password'] ?? ''),
            'kanal' => ((string) ($request->post['kanal'] ?? '')) === 'beta' ? 'beta' : 'stable',
            'modus' => ((string) ($request->post['modus'] ?? '')) === 'restore' ? 'restore' : 'frisch',
            // Kept for re-display only - never the password (see below).
            'admin_email' => trim((string) ($request->post['admin_email'] ?? '')),
            'admin_name' => trim((string) ($request->post['admin_name'] ?? '')),
        ];
        $adminPassword = (string) ($request->post['admin_password'] ?? '');
        $adminPasswordWiederholung = (string) ($request->post['admin_password_wiederholung'] ?? '');

        $errors = [];
        if ($values['db_host'] === '' || $values['db_name'] === '' || $values['db_user'] === '') {
            $errors['db'] = 'Bitte Host, Datenbankname und Benutzer angeben.';
        }

        if ($values['modus'] === 'frisch') {
            $errors = [...$errors, ...$this->adminErrors($values, $adminPassword, $adminPasswordWiederholung)];
        }

        $pdo = null;
        if ($errors === []) {
            try {
                $pdo = $this->connect($values);
            } catch (\PDOException $e) {
                // The PDO message names host and database, never the
                // password - and there is no business data here yet.
                $errors['db'] = 'Verbindung fehlgeschlagen: ' . $e->getMessage();
            }
        }

        $upload = null;
        if ($values['modus'] === 'restore' && $errors === []) {
            $upload = $this->uploadedBackup($request);
            if ($upload === null) {
                $errors['backup'] = 'Bitte ein Backup-ZIP hochladen.';
            }
        }

        if ($errors !== []) {
            return $this->render(['errors' => $errors, 'values' => $values], 422);
        }
        assert($pdo instanceof \PDO);

        if ($upload !== null) {
            return $this->startRestore($values, $upload);
        }

        // Fresh install: every migration from 0. The channel setting is
        // written before the vault, so a failure here leaves the installer
        // open instead of a half-configured installation behind.
        new Migrator($pdo, $this->paths->migrationsDir())->migrate();
        new SettingRepository($pdo)->set(UpdateService::SETTING_CHANNEL, $values['kanal']);

        $setup = new FirstAdminSetup($pdo);
        if ($setup->vaultExists()) {
            // Every migration from 0 ran above regardless (harmless, they
            // are idempotent) - but a vault already exists, so this is not
            // an empty database: refuse rather than seal a second one nobody
            // could ever be granted access to.
            return $this->render([
                'errors' => ['db' => 'Diese Datenbank enthält bereits eine Installation (ein Tresor ist schon vorhanden). Bitte eine leere Datenbank verwenden oder ein Backup einspielen.'],
                'values' => $values,
            ], 422);
        }

        $serverKey = ConfigWriter::generateServerKey();
        $ergebnis = $setup->create($values['admin_email'], $values['admin_name'], $adminPassword, $serverKey);
        $recoveryKey = $ergebnis['recoveryKey'];

        $_SESSION['install_admin'] = [
            'db' => $values,
            'server_key' => $serverKey,
            'user_id' => $ergebnis['userId'],
            'vault_version' => $ergebnis['vaultVersion'],
            // Only the hash of the last group is kept - the recovery key
            // itself is never stored anywhere (docs/spec/01-sicherheit.md
            // section 2).
            'confirm_hash' => hash('sha256', RecoveryKey::normalizeGroup($recoveryKey->lastGroup())),
        ];

        return $this->render([
            'schritt' => 'schluessel',
            'schluesselGruppen' => $recoveryKey->groups(),
            'errors' => [],
            'values' => $values,
        ]);
    }

    /**
     * Step 2 of the fresh-install chain: the admin types back the last group
     * of the printed recovery key - proof that it was written down
     * (docs/spec/01-sicherheit.md section 2). Only then is config.php
     * written, which is what closes /install for good.
     */
    public function confirmKey(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->render([
                'schritt' => 'schluessel_offen',
                'errors' => ['csrf' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'],
                'values' => [],
            ], 403);
        }

        $stand = $_SESSION['install_admin'] ?? null;
        if (!is_array($stand) || !is_array($stand['db'] ?? null)) {
            return $this->render([
                'errors' => [],
                'values' => ['kanal' => $this->channelFromSetup()],
            ], 409);
        }

        if (($stand['phase'] ?? null) === self::PHASE_MAIL) {
            // Confirmed already (back button): the mail step is next.
            return $this->renderMail($stand);
        }

        $eingabe = RecoveryKey::normalizeGroup((string) ($request->post['schluessel_letzte_gruppe'] ?? ''));
        $stimmtUeberein = hash_equals((string) $stand['confirm_hash'], hash('sha256', $eingabe));

        if (!$stimmtUeberein) {
            return $this->render([
                'schritt' => 'schluessel_offen',
                'errors' => ['schluessel' => 'Die eingegebene Gruppe stimmt nicht. Bitte die letzte Gruppe des ausgedruckten Wiederherstellungsschlüssels genau eingeben.'],
                'values' => [],
            ], 422);
        }

        $_SESSION['install_admin']['phase'] = self::PHASE_MAIL;
        // The hash has done its job; nothing else may confirm with it.
        unset($_SESSION['install_admin']['confirm_hash']);

        return $this->renderMail($_SESSION['install_admin']);
    }

    /**
     * Step 5 of docs/spec/06-betrieb.md section 1 (issue #249): mail
     * settings plus a test mail - skippable. `aktion` is `testen` (save,
     * send the test mail, stay), `abschliessen` (save, finish) or
     * `ueberspringen` (finish without mail settings). Finishing writes
     * config.php, which closes /install.
     *
     * The settings land in the database right away, the password encrypted
     * with the server key that config.php gets at the end - the same row
     * /admin/mail writes later. Nothing of it goes into the session.
     */
    public function mail(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->render([
                'schritt' => 'mail',
                'errors' => ['csrf' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'],
                'values' => [],
                'mail' => null,
            ], 403);
        }

        $stand = $_SESSION['install_admin'] ?? null;
        if (!is_array($stand) || ($stand['phase'] ?? null) !== self::PHASE_MAIL || !is_array($stand['db'] ?? null)) {
            return $this->render([
                'errors' => [],
                'values' => ['kanal' => $this->channelFromSetup()],
            ], 409);
        }

        $aktion = (string) ($request->post['aktion'] ?? '');
        if ($aktion === 'ueberspringen') {
            return $this->finishFresh($stand, false);
        }
        if ($aktion !== 'testen' && $aktion !== 'abschliessen') {
            return $this->renderMail($stand, ['mail' => 'Unbekannte Aktion.'], 422);
        }

        $transport = (string) ($request->post['transport'] ?? 'smtp');
        $transport = $transport === 'php_mail' ? 'php_mail' : 'smtp';
        $host = trim((string) ($request->post['host'] ?? ''));
        $port = (int) ($request->post['port'] ?? 587);
        $sicherheit = SmtpSecurity::tryFrom((string) ($request->post['sicherheit'] ?? ''));
        $benutzer = trim((string) ($request->post['benutzer'] ?? ''));
        $passwort = (string) ($request->post['passwort'] ?? '');
        $absender = trim((string) ($request->post['absender'] ?? ''));
        $antwortAn = trim((string) ($request->post['antwort_an'] ?? ''));
        $vereinsname = trim((string) ($request->post['vereinsname'] ?? ''));
        $urlEingabe = trim((string) ($request->post['oeffentliche_url'] ?? ''));
        $empfaenger = trim((string) ($request->post['empfaenger'] ?? ''));

        $fehler = MailSettings::eingabeFehler($transport, $host, $port, $sicherheit, $absender, $antwortAn);
        $url = $urlEingabe === '' ? '' : PublicUrl::normalize($urlEingabe);
        if ($fehler === null && $url === null) {
            $fehler = 'Die Adresse der Installation muss mit https:// beginnen und darf nur aus Schema, Host und ggf. Port bestehen.';
        }
        if ($fehler === null && $aktion === 'testen' && filter_var($empfaenger, FILTER_VALIDATE_EMAIL) === false) {
            $fehler = 'Bitte eine gültige Empfängeradresse für die Testmail angeben.';
        }

        $pdo = $this->connect($stand['db']);
        $repo = $this->mailSettings($pdo, (string) $stand['server_key']);
        if ($fehler !== null) {
            // What was typed comes back - never the password.
            $gespeichert = $repo->get();

            return $this->renderMail($stand, ['mail' => $fehler], 422, new MailSettings(
                transport: $transport,
                host: $host,
                port: $port,
                sicherheit: $sicherheit ?? $gespeichert->sicherheit,
                benutzer: $benutzer,
                passwort: '',
                passwortGesetzt: $gespeichert->passwortGesetzt,
                absender: $absender,
                antwortAn: $antwortAn,
                vereinsname: $vereinsname,
                oeffentlicheUrl: $urlEingabe,
            ), $empfaenger);
        }

        $repo->save(
            transport: $transport,
            host: $host,
            port: $port,
            sicherheit: $sicherheit ?? SmtpSecurity::Starttls,
            benutzer: $benutzer,
            // Empty keeps a password saved by an earlier test in this step.
            neuesPasswort: $passwort === '' ? null : $passwort,
            absender: $absender,
            antwortAn: $antwortAn,
            vereinsname: $vereinsname,
            oeffentlicheUrl: $url,
        );

        if ($aktion === 'abschliessen') {
            return $this->finishFresh($stand, true);
        }

        $ergebnis = new Mailer(
            new MailQueueRepository($pdo, $this->serverCrypto((string) $stand['server_key'])),
            $repo,
            new MailTemplates($this->paths->viewsDir() . '/mail'),
            $this->mailTransport,
        )->sendeTestmail($empfaenger);

        return $this->renderMail($stand, [], 200, null, $empfaenger, $ergebnis->erfolg
            ? ['ok' => true, 'text' => 'Testmail wurde versendet. Bitte den Posteingang prüfen und dann abschließen.']
            : ['ok' => false, 'text' => 'Testmail konnte nicht versendet werden: ' . ($ergebnis->fehlermeldung ?? 'unbekannter Fehler.')]);
    }

    /**
     * "Neu beginnen": the browser was closed (or the group was mistyped too
     * often) before the recovery key was confirmed. Removes the half-finished
     * vault and admin (App\Installer\FirstAdminSetup::remove()) so the form
     * can be submitted again - nothing was ever written to config.php, so
     * nothing else is affected.
     */
    public function restart(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->render([
                'schritt' => 'schluessel_offen',
                'errors' => ['csrf' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'],
                'values' => [],
            ], 403);
        }

        // A restore waiting for its access step: nothing to remove - the
        // restored tables stay (the next restore drops them anyway, and a
        // fresh install refuses a database with a vault) - only the state
        // goes, so the form can be submitted again.
        if ($this->pendingAccess() !== null) {
            $kanal = (string) ($_SESSION['install_restore']['values']['kanal'] ?? $this->channelFromSetup());
            unset($_SESSION['install_restore']);

            return $this->render(['errors' => [], 'values' => ['kanal' => $kanal === 'beta' ? 'beta' : 'stable']]);
        }

        $stand = $_SESSION['install_admin'] ?? null;
        if (!is_array($stand) || !is_array($stand['db'] ?? null)) {
            return $this->render([
                'errors' => [],
                'values' => ['kanal' => $this->channelFromSetup()],
            ], 409);
        }

        /** @var array<string, string> $dbValues */
        $dbValues = $stand['db'];

        try {
            $pdo = $this->connect($dbValues);
        } catch (\PDOException $e) {
            // The PDO message names host and database, never the password.
            return $this->render([
                'schritt' => 'schluessel_offen',
                'errors' => ['db' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()],
                'values' => [],
            ], 422);
        }

        new FirstAdminSetup($pdo)->remove((int) $stand['user_id'], (int) $stand['vault_version']);
        unset($_SESSION['install_admin']);

        return $this->render(['errors' => [], 'values' => ['kanal' => $dbValues['kanal'] ?? $this->channelFromSetup()]]);
    }

    /**
     * One block of the restore, called by install.js until "fertig". State
     * (credentials, phase, offset) lives in the session, the dump and - if
     * the backup carries blobs - the ZIP in shared/var/.
     *
     * Two phases: first the statements of dump.sql, then the encrypted blob
     * files of storage backend `fs` (M2-6). Migrations and config.php come
     * last, so a restore that breaks off in the blob phase leaves the
     * installer open instead of a half-filled installation that calls itself
     * finished.
     */
    public function restoreStep(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen - bitte die Seite neu laden.'], 403);
        }

        $stand = $_SESSION['install_restore'] ?? null;
        if (!is_array($stand) || !is_array($stand['values'] ?? null) || ($stand['phase'] ?? null) === self::PHASE_ZUGANG) {
            return Response::json(['fehler' => 'Keine Wiederherstellung aktiv.'], 409);
        }
        /** @var array<string, string> $values */
        $values = $stand['values'];
        $dumpDatei = (string) ($stand['dump_datei'] ?? '');
        $zipDatei = is_string($stand['zip_datei'] ?? null) ? $stand['zip_datei'] : null;
        $phase = ($stand['phase'] ?? 'sql') === 'blobs' ? 'blobs' : 'sql';

        try {
            $pdo = $this->connect($values);
            $restore = new RestoreService();

            if ($phase === 'sql') {
                // Byte offset into dump.sql (issue #120). A session of an
                // older version counts statements under `offset` instead:
                // it is converted once, by reading up to that statement.
                if (!is_int($stand['dump_offset'] ?? null)) {
                    $umgerechnet = $restore->byteOffsetNachAnweisungen($dumpDatei, (int) ($stand['offset'] ?? 0));
                    $_SESSION['install_restore']['dump_offset'] = $umgerechnet['offset'];
                    $_SESSION['install_restore']['anweisungen'] = $umgerechnet['anweisungen'];
                }
                $start = (int) $_SESSION['install_restore']['dump_offset'];

                // A step that is still marked running broke off hard (time
                // limit or a fatal error - PHP still writes the session
                // then): its last statement may have run without its
                // boundary being kept. A killed process writes no session
                // at all; that case stays a restore from the start (E-34).
                // The mark is set for this
                // step and cleared when it ends - also with an error, since
                // then no statement is left half-confirmed.
                $abgebrochen = ($_SESSION['install_restore']['schritt_laeuft'] ?? false) === true;
                $_SESSION['install_restore']['schritt_laeuft'] = true;

                // Kept after every statement, not once per block: a request
                // that breaks off resumes behind the last statement that ran.
                try {
                    $fortschritt = $restore->anwenden(
                        $pdo,
                        $dumpDatei,
                        $start,
                        static function (int $grenze): void {
                            $_SESSION['install_restore']['dump_offset'] = $grenze;
                            $_SESSION['install_restore']['anweisungen'] = (int) ($_SESSION['install_restore']['anweisungen'] ?? 0) + 1;
                        },
                        $abgebrochen,
                    );
                } finally {
                    unset($_SESSION['install_restore']['schritt_laeuft']);
                }
                $_SESSION['install_restore']['dump_offset'] = $fortschritt['offset'];
                $anweisungen = (int) ($_SESSION['install_restore']['anweisungen'] ?? 0);

                if ($fortschritt['offset'] < $fortschritt['gesamt']) {
                    return Response::json([
                        'fertig' => false,
                        'phase' => 'sql',
                        'offset' => $fortschritt['offset'],
                        'gesamt' => $fortschritt['gesamt'],
                        'anweisungen' => $anweisungen,
                    ]);
                }

                // Dump through: the blob files follow, if there are any. The
                // switch is its own answer so that neither phase has to share
                // a request budget with the other.
                if ($zipDatei !== null) {
                    $_SESSION['install_restore']['phase'] = 'blobs';
                    $_SESSION['install_restore']['offset'] = 0;

                    return Response::json([
                        'fertig' => false,
                        'phase' => 'blobs',
                        'offset' => 0,
                        'gesamt' => $restore->blobAnzahlImZip($zipDatei),
                    ]);
                }
                $gesamt = $fortschritt['gesamt'];
            } elseif ($zipDatei === null) {
                // Cannot happen - the blob phase is only entered with a ZIP.
                $gesamt = 0;
            } else {
                $blobs = $restore->blobsAusZip($zipDatei, $this->paths->blobDir(), (int) ($stand['offset'] ?? 0));
                $_SESSION['install_restore']['offset'] = $blobs['offset'];

                if ($blobs['offset'] < $blobs['gesamt']) {
                    return Response::json([
                        'fertig' => false,
                        'phase' => 'blobs',
                        'offset' => $blobs['offset'],
                        'gesamt' => $blobs['gesamt'],
                    ]);
                }
                $gesamt = $blobs['gesamt'];
            }

            // Everything is in: only migrations newer than the backup remain.
            $migriert = new Migrator($pdo, $this->paths->migrationsDir())->migrate();
            new SettingRepository($pdo)->set(UpdateService::SETTING_CHANNEL, $values['kanal']);

            @unlink($dumpDatei);
            if ($zipDatei !== null) {
                @unlink($zipDatei);
            }

            $antwort = [
                'fertig' => true,
                'phase' => $phase,
                'offset' => $gesamt,
                'gesamt' => $gesamt,
                'migrationen' => count($migriert->applied),
            ];

            $serverSchluessel = is_string($stand['server_key'] ?? null) ? $stand['server_key'] : null;
            if ($serverSchluessel === null) {
                // No key in the backup: config.php waits for the access step
                // (serverKey()/access()), the page moves on to its form.
                $_SESSION['install_restore'] = ['values' => $values, 'phase' => self::PHASE_ZUGANG];

                return Response::json([...$antwort, 'weiter' => '/install']);
            }

            $this->finishRestore($values, $serverSchluessel);

            return Response::json($antwort);
        } catch (\PDOException $e) {
            // Deliberately not getMessage(): a failed INSERT quotes a part
            // of the statement, and that is the row data of the backup.
            return Response::json([
                'fehler' => sprintf(
                    'Datenbankfehler beim Einspielen (SQLSTATE %s, Code %s).',
                    (string) $e->getCode(),
                    (string) ($e->errorInfo[1] ?? '?'),
                ),
            ], 500);
        } catch (\Throwable $e) {
            return Response::json([
                'fehler' => $e instanceof \RuntimeException ? $e->getMessage() : 'Unerwarteter Fehler beim Einspielen.',
            ], 500);
        }
    }

    /**
     * Access step, alternative 1: the old server key - pasted, or as the
     * separately kept config.php (read with the same regular expression as
     * a config.php inside the backup, never included). Taken only when it
     * opens the restored accounts: a key that merely looks right would lock
     * the installation exactly like a missing one.
     */
    public function serverKey(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->renderAccess(['csrf' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'], 403);
        }
        $values = $this->pendingAccess();
        if ($values === null) {
            return $this->render(['errors' => [], 'values' => ['kanal' => $this->channelFromSetup()]], 409);
        }

        $schluessel = null;
        $datei = $this->uploadedFile($request, 'config');
        if ($datei !== null) {
            // A config.php is a few hundred bytes; whatever is bigger than
            // this is not one, and is not read into memory any further.
            $inhalt = file_get_contents($datei['tmp_name'], false, null, 0, 64 * 1024);
            $schluessel = $inhalt === false ? null : RestoreService::serverSchluesselAusConfig($inhalt);
            if ($schluessel === null) {
                return $this->renderAccess(['server_key' => 'In der hochgeladenen Datei steht kein gültiger Server-Schlüssel (erwartet wird die config.php des Installers).'], 422);
            }
        } else {
            $eingabe = trim((string) ($request->post['server_key'] ?? ''));
            if ($eingabe === '') {
                return $this->renderAccess(['server_key' => 'Bitte den Server-Schlüssel eintragen oder die config.php hochladen.'], 422);
            }
            if (!RestoreService::gueltigerServerSchluessel($eingabe)) {
                return $this->renderAccess(['server_key' => 'Das ist kein Server-Schlüssel (erwartet werden 44 Zeichen Base64 aus der config.php).'], 422);
            }
            $schluessel = $eingabe;
        }

        try {
            $passt = new RestoreAccess($this->connect($values))->serverKeyFits($schluessel);
        } catch (\PDOException $e) {
            // The PDO message names host and database, never the password.
            return $this->renderAccess(['db' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()], 422);
        }
        if (!$passt) {
            return $this->renderAccess(['server_key' => 'Dieser Schlüssel passt nicht zu den eingespielten Daten: kein Benutzerkonto lässt sich damit lesen. Bitte die config.php der Installation verwenden, von der das Backup stammt.'], 422);
        }

        $this->finishRestore($values, $schluessel);

        return $this->render(['fertig' => true, 'restore' => true, 'errors' => [], 'values' => []]);
    }

    /**
     * Access step, alternative 2: recovery key plus the admin's own address,
     * name and password (App\Installer\RestoreAccess::relink()). A new
     * server key is generated here, used for the re-linked row and written
     * to config.php in the same request; the recovery key and the password
     * live only in this request.
     */
    public function access(Request $request): ResponseInterface
    {
        $this->session->start();

        if (!$this->session->checkCsrf($request)) {
            return $this->renderAccess(['csrf' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.'], 403);
        }
        $values = $this->pendingAccess();
        if ($values === null) {
            return $this->render(['errors' => [], 'values' => ['kanal' => $this->channelFromSetup()]], 409);
        }

        $eingabe = [
            'admin_email' => trim((string) ($request->post['admin_email'] ?? '')),
            'admin_name' => trim((string) ($request->post['admin_name'] ?? '')),
        ];
        $neuerSchluessel = ConfigWriter::generateServerKey();

        try {
            $zugang = new RestoreAccess($this->connect($values));
            $ergebnis = $zugang->relink(
                (string) ($request->post['schluessel'] ?? ''),
                $eingabe['admin_email'],
                $eingabe['admin_name'],
                (string) ($request->post['admin_password'] ?? ''),
                $neuerSchluessel,
                $request->ip,
                // Inside relink()'s transaction: a config.php that cannot be
                // written rolls the re-link back instead of leaving the one
                // readable account under a key that exists nowhere.
                fn() => ConfigWriter::write($this->paths->configFile(), $this->dbConfig($values), $neuerSchluessel),
            );
            $unlesbar = $zugang->unreadableAccounts($neuerSchluessel, $ergebnis->userId);
        } catch (RestoreAccessException $e) {
            return $this->renderAccess(['zugang' => $e->getMessage()], 422, $eingabe);
        } catch (\PDOException $e) {
            return $this->renderAccess(['db' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()], 422, $eingabe);
        }

        $this->closeInstaller();

        return $this->render([
            'fertig' => true,
            'restore' => true,
            'zugangVerknuepft' => [
                'email' => $eingabe['admin_email'],
                'freigabeErteilt' => $ergebnis->freigabeErteilt,
                'mfaZurueckgesetzt' => $ergebnis->mfaZurueckgesetzt,
                'unlesbareKonten' => $unlesbar,
            ],
            'errors' => [],
            'values' => [],
        ]);
    }

    /**
     * The last request of a fresh install: config.php (which closes
     * /install), the leftovers of setup.php, the session state.
     *
     * @param array<string, mixed> $stand $_SESSION['install_admin']
     */
    private function finishFresh(array $stand, bool $mailEingerichtet): Response
    {
        /** @var array<string, string> $dbValues */
        $dbValues = $stand['db'];
        ConfigWriter::write($this->paths->configFile(), $this->dbConfig($dbValues), (string) $stand['server_key']);

        unset($_SESSION['install_admin']);
        $this->cleanUpSetupLeftovers();

        return $this->render(['fertig' => true, 'mailEingerichtet' => $mailEingerichtet, 'errors' => [], 'values' => []]);
    }

    /** The open step of a fresh install: confirming the key, or the mail step after it. */
    private function renderPending(): Response
    {
        $stand = $_SESSION['install_admin'] ?? null;
        if (is_array($stand) && ($stand['phase'] ?? null) === self::PHASE_MAIL) {
            return $this->renderMail($stand);
        }

        return $this->render(['schritt' => 'schluessel_offen', 'errors' => [], 'values' => []]);
    }

    /**
     * @param array<string, mixed> $stand $_SESSION['install_admin']
     * @param array<string, string> $errors
     * @param MailSettings|null $eingabe what the form shows; null = what is saved
     * @param array{ok: bool, text: string}|null $testergebnis
     */
    private function renderMail(
        array $stand,
        array $errors = [],
        int $status = 200,
        ?MailSettings $eingabe = null,
        ?string $empfaenger = null,
        ?array $testergebnis = null,
    ): Response {
        /** @var array<string, string> $db */
        $db = $stand['db'];
        $adminEmail = (string) ($db['admin_email'] ?? '');
        if ($eingabe === null) {
            $eingabe = $this->mailSettings($this->connect($db), (string) $stand['server_key'])->get();
            if ($eingabe->absender === '' && !$eingabe->passwortGesetzt && $eingabe->host === '') {
                // Nothing saved yet: the admin's address is the likeliest sender.
                $eingabe = new MailSettings(
                    transport: $eingabe->transport,
                    host: '',
                    port: $eingabe->port,
                    sicherheit: $eingabe->sicherheit,
                    benutzer: '',
                    passwort: '',
                    passwortGesetzt: false,
                    absender: $adminEmail,
                    antwortAn: '',
                    vereinsname: '',
                );
            }
        }

        return $this->render([
            'schritt' => 'mail',
            'errors' => $errors,
            'values' => [],
            'mail' => $eingabe,
            'sicherheiten' => SmtpSecurity::cases(),
            'empfaenger' => $empfaenger ?? $adminEmail,
            'testergebnis' => $testergebnis,
        ], $status);
    }

    private function mailSettings(\PDO $pdo, string $serverKeyBase64): MailSettingsRepository
    {
        return new MailSettingsRepository(new SettingRepository($pdo), $this->serverCrypto($serverKeyBase64));
    }

    private function serverCrypto(#[\SensitiveParameter] string $serverKeyBase64): ServerCrypto
    {
        return new ServerCrypto((string) base64_decode($serverKeyBase64, true));
    }

    /**
     * The last request of every restore: config.php (which closes
     * /install), the leftovers of setup.php, the session state.
     *
     * @param array<string, string> $values
     */
    private function finishRestore(array $values, string $serverSchluessel): void
    {
        ConfigWriter::write($this->paths->configFile(), $this->dbConfig($values), $serverSchluessel);
        $this->closeInstaller();
    }

    /** What follows a written config.php: the leftovers of setup.php, the session state. */
    private function closeInstaller(): void
    {
        unset($_SESSION['install_restore']);
        $this->cleanUpSetupLeftovers();
    }

    /**
     * The form values of a restore that waits for its access step, or null
     * when none does.
     *
     * @return array<string, string>|null
     */
    private function pendingAccess(): ?array
    {
        $stand = $_SESSION['install_restore'] ?? null;
        if (!is_array($stand) || ($stand['phase'] ?? null) !== self::PHASE_ZUGANG || !is_array($stand['values'] ?? null)) {
            return null;
        }

        /** @var array<string, string> $values */
        $values = $stand['values'];

        return $values;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $eingabe address and name to show again - never the password or the key
     */
    private function renderAccess(array $errors = [], int $status = 200, array $eingabe = []): Response
    {
        return $this->render(['schritt' => 'zugang', 'errors' => $errors, 'values' => $eingabe], $status);
    }

    /**
     * Validates the "erster Zugang" fieldset (docs/spec/01-sicherheit.md
     * section 3): a plausible email, a non-empty display name, and a
     * password that satisfies App\Service\Account\PasswordPolicy and matches
     * its repetition. The password itself never ends up in the returned
     * error/value arrays.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function adminErrors(array $values, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $repeat): array
    {
        $errors = [];

        if ($values['admin_email'] === '' || filter_var($values['admin_email'], \FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if ($values['admin_name'] === '') {
            $errors['admin_name'] = 'Bitte einen Namen angeben.';
        }

        $policy = new PasswordPolicy($this->paths->dataDir() . '/haeufige-passwoerter.txt');
        $violations = $policy->violations($password);
        if ($violations !== []) {
            $errors['admin_password'] = implode(' ', $violations);
        } elseif ($password !== $repeat) {
            $errors['admin_password_wiederholung'] = 'Die Passwörter stimmen nicht überein.';
        }

        return $errors;
    }

    /**
     * Unpacks dump.sql into shared/var/ and hands the page over to
     * install.js.
     *
     * @param array<string, string> $values
     * @param array{tmp_name: string} $upload
     */
    private function startRestore(array $values, array $upload): ResponseInterface
    {
        $varDir = $this->paths->varDir();
        if (!is_dir($varDir)) {
            mkdir($varDir, 0775, true);
        }
        $kennung = bin2hex(random_bytes(8));
        $dumpDatei = $varDir . '/install_restore_' . $kennung . '.sql';
        $zipDatei = null;

        $restore = new RestoreService();
        try {
            $restore->dumpAusZip($upload['tmp_name'], $dumpDatei);
            $serverSchluessel = $restore->serverSchluesselAusZip($upload['tmp_name']);
            $manifest = $restore->manifestAusZip($upload['tmp_name']);

            // The upload is gone at the end of this request, so a backup with
            // blobs has to keep its ZIP until the blob phase is through. Only
            // then - otherwise every restore would double the disk it needs.
            if ($restore->blobAnzahlImZip($upload['tmp_name']) > 0) {
                $zipDatei = $varDir . '/install_restore_' . $kennung . '.zip';
                if (!copy($upload['tmp_name'], $zipDatei)) {
                    throw new \RuntimeException('Das Backup konnte nicht zwischengespeichert werden.');
                }
                @chmod($zipDatei, 0600);
            }
        } catch (\RuntimeException $e) {
            @unlink($dumpDatei);
            if ($zipDatei !== null) {
                @unlink($zipDatei);
            }

            return $this->render(['errors' => ['backup' => $e->getMessage()], 'values' => $values], 422);
        }

        $_SESSION['install_restore'] = [
            'values' => $values,
            'dump_datei' => $dumpDatei,
            'zip_datei' => $zipDatei,
            'phase' => 'sql',
            // dump phase: byte offset into dump.sql and statements run so
            // far (issue #120); `offset` counts the files of the blob phase
            'dump_offset' => 0,
            'anweisungen' => 0,
            'offset' => 0,
            'server_key' => $serverSchluessel,
        ];

        return $this->render([
            'restore' => true,
            'serverKeyUebernommen' => $serverSchluessel !== null,
            'backupVersion' => is_string($manifest['app_version'] ?? null) ? $manifest['app_version'] : null,
            'errors' => [],
            'values' => $values,
        ]);
    }

    /**
     * @return array{tmp_name: string}|null
     */
    private function uploadedBackup(Request $request): ?array
    {
        return $this->uploadedFile($request, 'backup');
    }

    /**
     * @return array{tmp_name: string}|null
     */
    private function uploadedFile(Request $request, string $feld): ?array
    {
        $datei = $request->files[$feld] ?? null;
        if (!is_array($datei) || ($datei['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            return null;
        }
        $pfad = (string) ($datei['tmp_name'] ?? '');
        $istUpload = $this->istUpload ?? is_uploaded_file(...);

        // The path is opened as a ZIP later; make sure it is a file PHP
        // received as an upload and not a path the client made up.
        return $pfad !== '' && $istUpload($pfad) ? ['tmp_name' => $pfad] : null;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function dbConfig(array $values): array
    {
        return [
            'host' => $values['db_host'],
            'port' => (int) ($values['db_port'] ?: 3306),
            'name' => $values['db_name'],
            'user' => $values['db_user'],
            'password' => $values['db_password'],
        ];
    }

    /**
     * The channel the admin picked in setup.php. setup.php runs before there
     * is a database, so it leaves the choice in shared/ and the installer
     * carries it into the setting - otherwise an instance installed from a
     * pre-release would silently look for updates on stable afterwards.
     */
    private function channelFromSetup(): string
    {
        $file = $this->paths->setupChannelFile();
        if (!is_file($file)) {
            return 'stable';
        }

        return trim((string) file_get_contents($file)) === 'beta' ? 'beta' : 'stable';
    }

    /**
     * @param array<string, string> $values
     */
    private function connect(array $values): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $values['db_host'],
                (int) ($values['db_port'] ?: 3306),
                $values['db_name'],
            ),
            $values['db_user'],
            $values['db_password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }

    /**
     * setup.php has served its purpose and would otherwise stay in the
     * docroot as a second, unauthenticated entry point. Best effort: on some
     * hosts the FTP user owns it and PHP may not delete it, which the
     * finished page points out.
     */
    private function cleanUpSetupLeftovers(): void
    {
        foreach ([$this->paths->webDir() . '/setup.php', $this->paths->setupChannelFile()] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data, int $status = 200): Response
    {
        return Response::html(
            $this->view->render('install', [
                'title' => 'Installation',
                'csrf' => $this->session->csrfToken(),
                'scripts' => ['/js/install.js'],
                'setupUebrig' => is_file($this->paths->webDir() . '/setup.php'),
                ...$data,
            ]),
            $status,
        );
    }
}
