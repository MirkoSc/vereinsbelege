<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\MailQueueRepository;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
use App\Service\Mail\SmtpSecurity;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page for mail (M3-1, issue #14): SMTP settings, a test mail, and the
 * retry queue (docs/spec/06-betrieb.md section 3).
 *
 * The controller and the cron task (App\Service\Cron\MailQueueTask) share the
 * same Mailer: settings and queue rows carry only server-key ciphertext, so
 * neither needs a vault (CLAUDE.md section 4) - this page works exactly like
 * StorageController and UpdateController in that respect.
 *
 * ---------------------------------------------------------------------
 * LOGIN REQUIRED since M3-3, exactly like those two: the routes are wrapped
 * in App\Http\LoginGuard (app/src/routes.php). Roles and the Permission
 * enum are still M3-6 (docs/spec/01-sicherheit.md section 4), so until then
 * every account that can log in can send mail through the club's SMTP
 * account from here. CSRF is enforced regardless.
 * ---------------------------------------------------------------------
 */
final readonly class MailController
{
    public function __construct(
        private View $view,
        private Session $session,
        private MailSettingsRepository $settingsRepo,
        private Mailer $mailer,
        private MailQueueRepository $queue,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/mail', [
            'title' => 'Mail',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'einstellungen' => $this->settingsRepo->get(),
            'sicherheiten' => SmtpSecurity::cases(),
            'warteschlange' => $this->queue->recent(),
        ], Area::Admin));
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $transport = (string) ($request->post['transport'] ?? 'smtp');
        if (!in_array($transport, ['smtp', 'php_mail'], true)) {
            $this->session->flash('Unbekannter Versandweg.', FlashArt::Fehler);

            return Response::redirect('/admin/mail');
        }

        $host = trim((string) ($request->post['host'] ?? ''));
        $port = (int) ($request->post['port'] ?? 587);
        $sicherheit = SmtpSecurity::tryFrom((string) ($request->post['sicherheit'] ?? ''));
        $benutzer = trim((string) ($request->post['benutzer'] ?? ''));
        $absender = trim((string) ($request->post['absender'] ?? ''));
        $antwortAn = trim((string) ($request->post['antwort_an'] ?? ''));
        $vereinsname = trim((string) ($request->post['vereinsname'] ?? ''));
        $oeffentlicheUrlEingabe = trim((string) ($request->post['oeffentliche_url'] ?? ''));

        $fehler = $this->validate($transport, $host, $port, $sicherheit, $absender, $antwortAn);
        // The base of links in mails (M3-5, issue #18): only scheme, host and
        // port - App\Service\Mail\PublicUrl explains why the request's own
        // Host header is not good enough.
        $oeffentlicheUrl = $oeffentlicheUrlEingabe === '' ? '' : PublicUrl::normalize($oeffentlicheUrlEingabe);
        if ($fehler === null && $oeffentlicheUrl === null) {
            $fehler = 'Die Adresse der Installation muss mit https:// beginnen und darf nur aus Schema, Host und ggf. Port bestehen.';
        }
        if ($fehler !== null) {
            $this->session->flash($fehler, FlashArt::Fehler);

            return Response::redirect('/admin/mail');
        }

        // The form never carries the stored password back (page() never
        // fills it in), so there is no other way to say "leave it as is"
        // than an empty field, with an explicit checkbox for "clear it".
        $neuesPasswort = match (true) {
            (string) ($request->post['passwort_loeschen'] ?? '') === '1' => '',
            trim((string) ($request->post['passwort'] ?? '')) !== '' => (string) $request->post['passwort'],
            default => null,
        };

        $this->settingsRepo->save(
            transport: $transport,
            host: $host,
            port: $port,
            sicherheit: $sicherheit ?? SmtpSecurity::Starttls,
            benutzer: $benutzer,
            neuesPasswort: $neuesPasswort,
            absender: $absender,
            antwortAn: $antwortAn,
            vereinsname: $vereinsname,
            oeffentlicheUrl: $oeffentlicheUrl,
        );
        $this->session->flash('Mail-Einstellungen gespeichert.');

        return Response::redirect('/admin/mail');
    }

    public function test(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $empfaenger = trim((string) ($request->post['empfaenger'] ?? ''));
        if (filter_var($empfaenger, FILTER_VALIDATE_EMAIL) === false) {
            $this->session->flash('Bitte eine gültige Empfängeradresse angeben.', FlashArt::Fehler);

            return Response::redirect('/admin/mail');
        }

        $ergebnis = $this->mailer->sendeTestmail($empfaenger);
        $this->session->flash(
            $ergebnis->erfolg
                ? 'Testmail wurde versendet.'
                : 'Testmail konnte nicht versendet werden: ' . ($ergebnis->fehlermeldung ?? 'unbekannter Fehler.'),
            $ergebnis->erfolg ? FlashArt::Ok : FlashArt::Fehler,
        );

        return Response::redirect('/admin/mail');
    }

    private function validate(
        string $transport,
        string $host,
        int $port,
        ?SmtpSecurity $sicherheit,
        string $absender,
        string $antwortAn,
    ): ?string {
        if (filter_var($absender, FILTER_VALIDATE_EMAIL) === false) {
            return 'Bitte eine gültige Absenderadresse angeben.';
        }
        if ($antwortAn !== '' && filter_var($antwortAn, FILTER_VALIDATE_EMAIL) === false) {
            return 'Die Antwort-an-Adresse ist keine gültige E-Mail-Adresse.';
        }
        if ($transport === 'smtp') {
            if ($host === '') {
                return 'Bitte einen SMTP-Server angeben.';
            }
            if ($port < 1 || $port > 65535) {
                return 'Der Port muss zwischen 1 und 65535 liegen.';
            }
            if ($sicherheit === null) {
                return 'Bitte eine Verschlüsselung auswählen.';
            }
        }

        return null;
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect('/admin/mail');
    }
}
