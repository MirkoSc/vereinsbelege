<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\PasswordReset;
use App\Service\Account\SessionVault;
use App\Service\Mail\PublicUrl;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * "Passwort vergessen" (issue #18/M3-5, docs/spec/01-sicherheit.md
 * sections 2 and 3): asking for a link, and setting a new password with it.
 *
 * Public routes with a session, like /anmelden: nobody here is logged in,
 * but both forms need a CSRF token. The checking and the key handling are
 * App\Service\Account\PasswordReset's; this class only turns them into pages
 * and a mail.
 *
 * Both pages explain up front what a reset costs - a new key pair, and no
 * receipts until an admin grants the vault again - because afterwards it
 * is too late to change one's mind ("UI erklärt das vorab").
 */
final readonly class PasswordController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $vaultSession,
        /** @var \Closure(): PasswordToolbox built lazily - see PasswordToolbox. */
        private \Closure $tools,
    ) {
    }

    public function vergessenForm(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->vergessenSeite();
    }

    public function vergessenSubmit(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->vergessenSeite(fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }

        $email = trim((string) ($request->post['email'] ?? ''));

        /** @var PasswordToolbox $tools */
        $tools = ($this->tools)();
        $anfrage = $tools->reset->request($email, $request->ip);

        if ($anfrage->zuVieleVersuche) {
            // Same for an address with and without an account: the limit is
            // keyed by the address's blind index either way.
            return $this->vergessenSeite(
                fehler: 'Zu viele Anfragen. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
                email: $email,
            );
        }

        if ($anfrage->userId !== null) {
            // Nobody is logged in: no actor, only the account the link is for.
            $tools->audit->record(AuditAction::PasswortResetAngefordert, null, $request->ip, $anfrage->userId);
        }
        if ($anfrage->token !== null && $anfrage->emailEnc !== null) {
            $basis = PublicUrl::resolve(
                $tools->mailSettings->get(),
                $request->header('host') ?? '',
                Request::httpsFromGlobals(),
            );

            if ($basis === null) {
                // No trustworthy base for the link (see PublicUrl). Better no
                // mail than one pointing anywhere a Host header says. The log
                // line names the reason, never the address or the token.
                $tools->logger?->append('password reset: no public URL configured and request host does not match the sender domain - no mail sent');
            } else {
                $tools->mailer->sendePasswortReset(
                    $tools->crypto->decrypt($anfrage->emailEnc),
                    $basis . '/anmelden/passwort-neu?token=' . $anfrage->token,
                    intdiv(PasswordReset::TTL_SECONDS, 60),
                );
            }
        }

        // One answer for every case - unknown address, locked account, mail
        // sent or not (docs/spec/01-sicherheit.md section 3: no user
        // enumeration, "auch bei 'Passwort vergessen'").
        return $this->vergessenSeite(gesendet: true);
    }

    public function neuForm(Request $request): ResponseInterface
    {
        $this->session->start();
        $token = self::token($request->query['token'] ?? null);

        /** @var PasswordToolbox $tools */
        $tools = ($this->tools)();
        if ($token === '' || !$tools->reset->isUsable($token)) {
            return $this->neuSeite('', fehler: [PasswordReset::linkUngueltig()], ungueltig: true);
        }

        return $this->neuSeite($token);
    }

    public function neuSubmit(Request $request): ResponseInterface
    {
        $this->session->start();
        $token = self::token($request->post['token'] ?? null);
        if (!$this->session->checkCsrf($request)) {
            return $this->neuSeite($token, fehler: ['Die Sitzung ist abgelaufen – bitte erneut versuchen.']);
        }

        if (($request->post['verstanden'] ?? '') !== '1') {
            return $this->neuSeite(
                $token,
                fehler: ['Bitte bestätigen Sie, dass Sie die Folgen für den Tresor-Zugang verstanden haben.'],
            );
        }

        /** @var PasswordToolbox $tools */
        $tools = ($this->tools)();
        $ergebnis = $tools->reset->complete(
            $token,
            (string) ($request->post['passwort'] ?? ''),
            (string) ($request->post['passwort_wiederholung'] ?? ''),
        );

        if (!$ergebnis->istErfolg()) {
            $ungueltig = $ergebnis->fehler === [PasswordReset::linkUngueltig()];

            return $this->neuSeite($ungueltig ? '' : $token, fehler: $ergebnis->fehler, ungueltig: $ungueltig);
        }

        assert($ergebnis->userId !== null);
        $tools->audit->record(AuditAction::PasswortResetAbgeschlossen, $ergebnis->userId, $request->ip, $ergebnis->userId);
        $user = $tools->users->findById($ergebnis->userId);
        if ($user !== null) {
            $tools->mailer->sendeSicherheitshinweis(
                $tools->crypto->decrypt($user->emailEnc),
                'Ihr Passwort wurde über den Link „Passwort vergessen“ zurückgesetzt. '
                . 'Ihr Zugang zum Tresor muss von einem Administrator erneut freigegeben werden.',
            );
        }

        // The account now waits for a new grant - "Admins bekommen dazu eine
        // Mail" (docs/spec/01-sicherheit.md section 2, M3-7).
        $tools->freigabeHinweis?->senden(
            PublicUrl::resolve($tools->mailSettings->get(), $request->header('host') ?? '', Request::httpsFromGlobals()),
        );

        // Whatever session this browser still had is over - the reset ended
        // all of them (`user.session_epoch`), this one included. Starting a
        // fresh one here, instead of leaving it to the guard, keeps the
        // flash below from being thrown away with it.
        $antwort = Response::redirect('/anmelden');
        if ($this->session->userId() !== null) {
            $this->vaultSession->clear();
            $this->session->destroy();
            $this->session->start();
            $antwort = $antwort->withCookie(Cookie::vaultKey('', Request::httpsFromGlobals())->expired());
        }

        $this->session->flash(
            'Ihr neues Passwort ist gesetzt. Sie können sich damit anmelden – Belege sehen Sie aber erst wieder, '
            . 'wenn ein Administrator Ihren Zugang für den Tresor erneut freigegeben hat.',
            FlashArt::Warnung,
        );

        return $antwort;
    }

    private function vergessenSeite(?string $fehler = null, string $email = '', bool $gesendet = false): ResponseInterface
    {
        return Response::html($this->view->render('passwort-vergessen', [
            'title' => 'Passwort vergessen',
            'csrf' => $this->session->csrfToken(),
            'fehler' => $fehler,
            'email' => $email,
            'gesendet' => $gesendet,
        ], Area::Oeffentlich));
    }

    /**
     * @param list<string> $fehler
     */
    private function neuSeite(string $token, array $fehler = [], bool $ungueltig = false): ResponseInterface
    {
        $seite = Response::html($this->view->render('passwort-neu', [
            'title' => 'Neues Passwort festlegen',
            'csrf' => $this->session->csrfToken(),
            'token' => $token,
            'fehler' => $fehler,
            'ungueltig' => $ungueltig,
        ], Area::Oeffentlich));

        // The token sits in this page's URL. No Referer, not even to our own
        // stylesheet, so it lands in no other access log line than the one
        // request that carried it.
        return new Response(
            $seite->status,
            [...$seite->headers, 'Referrer-Policy' => 'no-referrer'],
            $seite->body,
        );
    }

    /**
     * Only the exact shape PasswordReset hands out; anything else is treated
     * as no token at all rather than echoed back into a form.
     */
    private static function token(mixed $wert): string
    {
        return is_string($wert) && preg_match('/^[0-9a-f]{64}$/', $wert) === 1 ? $wert : '';
    }
}
