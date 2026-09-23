<?php

declare(strict_types=1);

namespace App\App;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\Invitation;
use App\Service\Mail\FreigabeBenachrichtigung;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Accepting an invitation (issue #20/M3-7, docs/spec/01-sicherheit.md
 * section 2 "Einladung"): the invited person sets a password, which creates
 * the key pair and activates the account.
 *
 * Public route with a session, like /anmelden/passwort-neu: nobody is
 * logged in, but the form needs a CSRF token. The token rides in the URL
 * of the GET and in a hidden field of the POST; the page sends
 * `Referrer-Policy: no-referrer` for the same reason as the reset page.
 *
 * Afterwards: to the login - the second factor is set up there (the guard
 * forces it), and the admins who can grant are told that somebody waits.
 */
final readonly class InvitationController
{
    public function __construct(
        private View $view,
        private Session $session,
        private Invitation $einladung,
        private FreigabeBenachrichtigung $freigabeHinweis,
        private MailSettingsRepository $mailSettings,
    ) {
    }

    public function form(Request $request): ResponseInterface
    {
        $this->session->start();
        $token = self::token($request->query['token'] ?? null);
        if ($token === '' || !$this->einladung->isUsable($token)) {
            return $this->seite('', [Invitation::linkUngueltig()], ungueltig: true);
        }

        return $this->seite($token);
    }

    public function submit(Request $request): ResponseInterface
    {
        $this->session->start();
        $token = self::token($request->post['token'] ?? null);
        if (!$this->session->checkCsrf($request)) {
            return $this->seite($token, ['Die Sitzung ist abgelaufen – bitte erneut versuchen.']);
        }

        $ergebnis = $this->einladung->complete(
            $token,
            (string) ($request->post['passwort'] ?? ''),
            (string) ($request->post['passwort_wiederholung'] ?? ''),
        );
        if (!$ergebnis->istErfolg()) {
            $ungueltig = $ergebnis->fehler === [Invitation::linkUngueltig()];

            return $this->seite($ungueltig ? '' : $token, $ergebnis->fehler, $ungueltig);
        }

        $this->freigabeHinweis->senden(
            PublicUrl::resolve($this->mailSettings->get(), $request->header('host') ?? '', Request::httpsFromGlobals()),
        );

        $this->session->flash(
            'Ihr Passwort ist gesetzt. Melden Sie sich jetzt an – danach richten Sie den zweiten Faktor ein. '
            . 'Belege sehen Sie, sobald ein Administrator Ihren Zugang für den Tresor freigegeben hat.',
            FlashArt::Ok,
        );

        return Response::redirect('/anmelden');
    }

    /**
     * @param list<string> $fehler
     */
    private function seite(string $token, array $fehler = [], bool $ungueltig = false): ResponseInterface
    {
        $seite = Response::html($this->view->render('einladung', [
            'title' => 'Einladung annehmen',
            'csrf' => $this->session->csrfToken(),
            'token' => $token,
            'fehler' => $fehler,
            'ungueltig' => $ungueltig,
        ], Area::Oeffentlich));

        // The token sits in this page's URL - no Referer, not even to our
        // own stylesheet.
        return new Response(
            $seite->status,
            [...$seite->headers, 'Referrer-Policy' => 'no-referrer'],
            $seite->body,
        );
    }

    /**
     * Only the exact shape Invitation hands out; anything else is treated as
     * no token at all rather than echoed back into a form.
     */
    private static function token(mixed $wert): string
    {
        return is_string($wert) && preg_match('/^[0-9a-f]{64}$/', $wert) === 1 ? $wert : '';
    }
}
