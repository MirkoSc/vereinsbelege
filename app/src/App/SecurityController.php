<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\UserRepository;
use App\Service\Account\MfaEnrollment;
use App\Service\Account\MfaService;
use App\Service\Account\Neuanmeldung;
use App\Service\Account\NeuanmeldungErgebnis;
use App\Service\Account\PasswordChange;
use App\Service\Account\Totp;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\Mailer;
use App\Support\QrCode;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Managing the second factor of an already logged-in account (issue
 * #17/M3-4, docs/spec/01-sicherheit.md section 3): setup, switching method,
 * regenerating backup codes, revoking a remembered device.
 *
 * LOGIN REQUIRED (App\Http\LoginGuard, app/src/routes.php) - and, since
 * M3-4, the guard also sends here (specifically to `einrichten()`) whenever
 * `mfa_required` is set but nothing is configured yet
 * (App\Domain\User::mfaEingerichtet()), before any other page in `/app` or
 * `/admin` is reachable. No right beyond the login (M3-6): every account
 * manages its own second factor here and nothing else.
 *
 * The setup flow is one view (`app/sicherheit-einrichten`) with several
 * steps rendered directly from a POST response rather than a redirect -
 * the same "resume from where the browser already is" choice
 * app/views/install.php makes for its own multi-step forms. A lost QR code
 * (a closed tab, a reload) is not a state worth resuming: starting over is
 * one click, and a secret nobody has scanned yet is not worth keeping
 * (App\Service\Account\MfaEnrollment::startTotp()).
 *
 * Changing a second factor that is already in place - another method, a
 * new authenticator, new backup codes - first asks for the password and
 * the current factor again (security review N-08, issue #246, ASVS 3.7.1;
 * App\Service\Account\Neuanmeldung, `/app/sicherheit/bestaetigen`), valid
 * for Neuanmeldung::GUELTIG_SEKUNDEN and spent by the change it allowed.
 * The very first setup does not: there is no factor yet to ask for, and
 * the login has just checked the password.
 */
final readonly class SecurityController
{
    public function __construct(
        private View $view,
        private Session $session,
        private MfaService $mfa,
        private MfaEnrollment $enrollment,
        private UserRepository $users,
        private Mailer $mailer,
        private ServerCrypto $crypto,
        private PasswordChange $passwordChange,
        private AuditLog $audit,
        private Neuanmeldung $neuanmeldung,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();
        $userId = $this->requireUserId();

        return Response::html($this->view->render('app/sicherheit', [
            'title' => 'Sicherheit',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'mfaMethod' => $this->users->findById($userId)?->mfaMethod,
            'backupCodesUnused' => $this->mfa->countUnusedBackupCodes($userId),
            'geraete' => $this->mfa->listDevices($userId),
        ], Area::App));
    }

    public function einrichten(Request $request): ResponseInterface
    {
        $this->session->start();
        $userId = $this->requireUserId();

        return $this->ohneNeuanmeldung($userId, self::EINRICHTEN) ?? $this->seite('waehlen');
    }

    /**
     * The re-authentication form (N-08): password plus the current factor.
     * `weiter` is where the confirmed change continues - only one of the
     * account's own security pages (WEITER_ERLAUBT), never a URL from
     * outside.
     */
    public function bestaetigen(Request $request): ResponseInterface
    {
        $this->session->start();
        $userId = $this->requireUserId();

        return $this->bestaetigenSeite($userId, self::weiter($request->query['weiter'] ?? null));
    }

    public function bestaetigenPruefen(Request $request): ResponseInterface
    {
        $this->session->start();
        $userId = $this->requireUserId();
        $weiter = self::weiter($request->post['weiter'] ?? null);
        if (!$this->session->checkCsrf($request)) {
            return $this->bestaetigenSeite($userId, $weiter, fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }

        $ergebnis = $this->neuanmeldung->pruefe(
            $userId,
            (string) ($request->post['passwort'] ?? ''),
            (string) ($request->post['code'] ?? ''),
            $request->ip,
        );
        if ($ergebnis !== NeuanmeldungErgebnis::Bestaetigt) {
            if ($ergebnis === NeuanmeldungErgebnis::CodeFalsch) {
                $this->audit->record(AuditAction::ZweiterFaktorFehlgeschlagen, $userId, $request->ip, $userId, ['art' => 'neuanmeldung']);
            }

            return $this->bestaetigenSeite($userId, $weiter, fehler: $ergebnis->meldung());
        }

        $this->session->bestaetigeNeuanmeldung();
        if ($weiter === self::UEBERSICHT) {
            $this->session->flash('Bestätigt – Sie können die Backup-Codes jetzt neu erzeugen.', FlashArt::Info);
        }

        return Response::redirect($weiter);
    }

    /** The e-mail code for the re-authentication - for either method, as on the login page. */
    public function bestaetigenCodeSenden(Request $request): ResponseInterface
    {
        $this->session->start();
        $userId = $this->requireUserId();
        $weiter = self::weiter($request->post['weiter'] ?? null);
        if (!$this->session->checkCsrf($request)) {
            return $this->bestaetigenSeite($userId, $weiter, fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        // Every mail counts against the second factor's limit, so the page
        // cannot be used to flood the account's mailbox.
        if (!$this->mfa->versuch($request->ip, $userId)) {
            return $this->bestaetigenSeite($userId, $weiter, fehler: NeuanmeldungErgebnis::ZuVieleVersuche->meldung());
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return $this->bestaetigenSeite($userId, $weiter);
        }
        $ergebnis = $this->mailer->sendeMfaCode(
            $this->crypto->decrypt($user->emailEnc),
            $this->mfa->requestEmailCode($userId),
            intdiv(MfaService::EMAIL_CODE_TTL_SECONDS, 60),
        );

        return $this->bestaetigenSeite($userId, $weiter, hinweis: $ergebnis->erfolg
            ? 'Ein Code wurde per E-Mail versendet.'
            : 'Der Code konnte nicht versendet werden. Bitte versuchen Sie es erneut.');
    }

    public function totpStarten(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seite('waehlen', fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        $userId = $this->requireUserId();
        $umweg = $this->ohneNeuanmeldung($userId, self::EINRICHTEN);
        if ($umweg !== null) {
            return $umweg;
        }

        $secret = $this->enrollment->startTotp();
        $this->session->merkeTotpEinrichtung($this->crypto->encrypt($secret));

        return $this->totpSeite($userId, $secret);
    }

    public function totpBestaetigen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seite('waehlen', fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        $userId = $this->requireUserId();

        // A setup started within the window may be finished even if the
        // re-authentication itself has run out meanwhile: starting it
        // already required one (totpStarten()), and scanning the code takes
        // its time. The setup has its own window of the same length.
        $secretEnc = $this->session->totpEinrichtung(Neuanmeldung::GUELTIG_SEKUNDEN);
        if ($secretEnc === null) {
            return $this->ohneNeuanmeldung($userId, self::EINRICHTEN)
                ?? $this->seite('waehlen', fehler: 'Die Einrichtung ist abgelaufen – bitte erneut starten.');
        }
        $secret = $this->crypto->decrypt($secretEnc);

        $code = trim((string) ($request->post['code'] ?? ''));
        $codes = $this->enrollment->confirmTotp($userId, $secret, $code);

        if ($codes === null) {
            return $this->totpSeite($userId, $secret, fehler: 'Der Code stimmt nicht. Bitte erneut versuchen.');
        }

        $this->session->verwirfTotpEinrichtung();
        $this->session->verwirfNeuanmeldung();
        $this->audit->record(AuditAction::TotpEingerichtet, $userId, $request->ip, $userId);
        $this->hinweisSenden($userId, 'Die Anmeldung mit Authenticator-App (TOTP) wurde eingerichtet.');

        return $this->seite('codes', backupCodes: $codes);
    }

    public function emailStarten(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seite('waehlen', fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        $userId = $this->requireUserId();
        $umweg = $this->ohneNeuanmeldung($userId, self::EINRICHTEN);
        if ($umweg !== null) {
            return $umweg;
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $this->seite('waehlen');
        }

        $code = $this->mfa->requestEmailCode($userId);
        $ergebnis = $this->mailer->sendeMfaCode(
            $this->crypto->decrypt($user->emailEnc),
            $code,
            intdiv(MfaService::EMAIL_CODE_TTL_SECONDS, 60),
        );

        return $this->seite('email', hinweis: $ergebnis->erfolg
            ? 'Ein Code wurde per E-Mail versendet.'
            : 'Der Code konnte nicht versendet werden. Bitte versuchen Sie es erneut.');
    }

    public function emailBestaetigen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seite('waehlen', fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        $userId = $this->requireUserId();
        $umweg = $this->ohneNeuanmeldung($userId, self::EINRICHTEN);
        if ($umweg !== null) {
            return $umweg;
        }

        $code = trim((string) ($request->post['code'] ?? ''));
        if (!$this->mfa->verifyEmailCode($userId, $code)) {
            return $this->seite('email', fehler: 'Der Code stimmt nicht oder ist abgelaufen.');
        }

        $codes = $this->enrollment->commitEmailMethod($userId);
        $this->session->verwirfTotpEinrichtung();
        $this->session->verwirfNeuanmeldung();
        $this->audit->record(AuditAction::EmailCodeEingerichtet, $userId, $request->ip, $userId);
        $this->hinweisSenden($userId, 'Die Anmeldung mit Code per E-Mail wurde eingerichtet.');

        return $this->seite('codes', backupCodes: $codes);
    }

    public function backupCodesNeu(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::redirect('/app/sicherheit');
        }
        $userId = $this->requireUserId();
        $umweg = $this->ohneNeuanmeldung($userId, self::UEBERSICHT);
        if ($umweg !== null) {
            return $umweg;
        }

        $codes = $this->enrollment->regenerateBackupCodes($userId);
        $this->session->verwirfNeuanmeldung();
        $this->audit->record(AuditAction::BackupCodesNeu, $userId, $request->ip, $userId);
        $this->hinweisSenden($userId, 'Die Backup-Codes wurden neu erzeugt; die vorherigen zehn gelten nicht mehr.');

        return $this->seite('codes', backupCodes: $codes);
    }

    /**
     * @param array<string, string> $params
     */
    public function geraetWiderrufen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::redirect('/app/sicherheit');
        }
        $userId = $this->requireUserId();

        $geraet = (int) ($params['id'] ?? 0);
        $this->mfa->revokeDevice($geraet, $userId);
        $this->audit->record(AuditAction::GeraetWiderrufen, $userId, $request->ip, $geraet);
        $this->session->flash('Das Gerät wurde entfernt.', FlashArt::Ok);

        return Response::redirect('/app/sicherheit');
    }

    /**
     * Password change with the old password known (issue #18/M3-5,
     * docs/spec/01-sicherheit.md section 2): the key is re-wrapped, the
     * vault grant stays, the account's other sessions end.
     */
    public function passwort(Request $request): ResponseInterface
    {
        $this->session->start();
        $this->requireUserId();

        return $this->passwortSeite();
    }

    public function passwortAendern(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->passwortSeite(['Die Sitzung ist abgelaufen – bitte erneut versuchen.']);
        }
        $userId = $this->requireUserId();

        $ergebnis = $this->passwordChange->change(
            $userId,
            (string) ($request->post['passwort_alt'] ?? ''),
            (string) ($request->post['passwort'] ?? ''),
            (string) ($request->post['passwort_wiederholung'] ?? ''),
        );

        if (!$ergebnis->istErfolg()) {
            return $this->passwortSeite($ergebnis->fehler);
        }

        // Every other session of the account ends at its next request
        // (App\Http\LoginGuard); this one takes the new value and stays.
        assert($ergebnis->sessionEpoch !== null);
        $this->session->adoptEpoch($ergebnis->sessionEpoch);

        $this->audit->record(AuditAction::PasswortGeaendert, $userId, $request->ip, $userId);
        $this->hinweisSenden($userId, 'Ihr Passwort wurde geändert. Andere angemeldete Sitzungen wurden beendet.');
        $this->session->flash('Ihr Passwort wurde geändert. Andere angemeldete Sitzungen wurden beendet.', FlashArt::Ok);

        return Response::redirect('/app/sicherheit');
    }

    private const string UEBERSICHT = '/app/sicherheit';

    private const string EINRICHTEN = '/app/sicherheit/einrichten';

    /** Where a confirmed re-authentication may continue. */
    private const array WEITER_ERLAUBT = [self::UEBERSICHT, self::EINRICHTEN];

    private static function weiter(mixed $wert): string
    {
        return is_string($wert) && in_array($wert, self::WEITER_ERLAUBT, true) ? $wert : self::EINRICHTEN;
    }

    /**
     * The detour to the re-authentication, or null when none is needed: no
     * factor set up yet (the first setup), or one confirmed within
     * Neuanmeldung::GUELTIG_SEKUNDEN.
     */
    private function ohneNeuanmeldung(int $userId, string $weiter): ?ResponseInterface
    {
        if ($this->users->findById($userId)?->mfaMethod === null
            || $this->session->neuanmeldungGueltig(Neuanmeldung::GUELTIG_SEKUNDEN)) {
            return null;
        }

        return Response::redirect('/app/sicherheit/bestaetigen?' . http_build_query(['weiter' => $weiter]));
    }

    private function bestaetigenSeite(int $userId, string $weiter, ?string $fehler = null, ?string $hinweis = null): ResponseInterface
    {
        return Response::html($this->view->render('app/sicherheit-bestaetigen', [
            'title' => 'Mit zweitem Faktor bestätigen',
            'csrf' => $this->session->csrfToken(),
            'weiter' => $weiter,
            'mfaMethod' => $this->users->findById($userId)?->mfaMethod,
            'fehler' => $fehler,
            'hinweis' => $hinweis,
        ], Area::App));
    }

    private function totpSeite(int $userId, #[\SensitiveParameter] string $secret, ?string $fehler = null): ResponseInterface
    {
        $user = $this->users->findById($userId);
        $email = $user === null ? '' : $this->crypto->decrypt($user->emailEnc);

        return $this->seite(
            'totp',
            fehler: $fehler,
            totpSecretBase32: Totp::base32Encode($secret),
            totpUri: Totp::uri($secret, $email, 'Vereinsbelege'),
        );
    }

    /**
     * @param list<string> $fehler
     */
    private function passwortSeite(array $fehler = []): ResponseInterface
    {
        return Response::html($this->view->render('app/sicherheit-passwort', [
            'title' => 'Passwort ändern',
            'csrf' => $this->session->csrfToken(),
            'fehler' => $fehler,
        ], Area::App));
    }

    private function hinweisSenden(int $userId, string $ereignis): void
    {
        $user = $this->users->findById($userId);
        if ($user !== null) {
            $this->mailer->sendeSicherheitshinweis($this->crypto->decrypt($user->emailEnc), $ereignis);
        }
    }

    private function seite(
        string $schritt,
        ?string $fehler = null,
        ?string $hinweis = null,
        ?string $totpSecretBase32 = null,
        ?string $totpUri = null,
        ?array $backupCodes = null,
    ): ResponseInterface {
        return Response::html($this->view->render('app/sicherheit-einrichten', [
            'title' => 'Zwei-Faktor-Anmeldung einrichten',
            'csrf' => $this->session->csrfToken(),
            'scripts' => ['/js/sicherheit.js'],
            'schritt' => $schritt,
            'fehler' => $fehler,
            'hinweis' => $hinweis,
            'totpSecretBase32' => $totpSecretBase32,
            'totpQrSvg' => $totpUri === null ? null : QrCode::svg($totpUri, label: 'QR-Code zur Einrichtung des Authenticators'),
            'backupCodes' => $backupCodes,
        ], Area::App));
    }

    /**
     * The guard already guaranteed a session id exists for every route this
     * controller serves (App\Http\LoginGuard) - this only gives that id a
     * type SecurityController's own code can rely on.
     */
    private function requireUserId(): int
    {
        $userId = $this->session->userId();
        assert($userId !== null);

        return $userId;
    }
}
