<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\MfaMethod;
use App\Service\Account\MfaService;
use App\Service\Account\PendingLogin;
use App\Service\Account\PendingLoginData;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The second factor of the login itself (issue #17/M3-4,
 * docs/spec/01-sicherheit.md section 3): the confirmation page between
 * "password correct" (App\App\AuthController::submit()) and a finished
 * session, and its three ways through - a TOTP or e-mailed code, or a backup
 * code.
 *
 * Public routes, the same as /anmelden itself: nobody here is logged in yet
 * in the App\Http\Session sense (that only happens once this controller
 * hands off to App\App\LoginCompleter). What stands in for a session is
 * App\Service\Account\PendingLogin, sealed to the `__Host-2fa` cookie the
 * same way the vault itself is sealed to `__Host-vk`.
 */
final readonly class MfaController
{
    public function __construct(
        private View $view,
        private Session $session,
        private PendingLogin $pendingLogin,
        private LoginCompleter $completer,
        /** @var \Closure(): MfaToolbox built lazily - see MfaToolbox. */
        private \Closure $tools,
    ) {
    }

    public function form(Request $request): ResponseInterface
    {
        $this->session->start();
        if ($this->session->userId() !== null) {
            return Response::redirect('/app');
        }

        $pending = $this->pendingLogin->open(Cookie::pendingLoginKeyFrom($request));
        if ($pending === null) {
            return Response::redirect('/anmelden');
        }

        return $this->seite($pending->mfaMethod);
    }

    /**
     * The main form: a TOTP or (for either method) e-mailed code, plus the
     * optional "Gerät merken" checkbox.
     */
    public function submitCode(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seiteOhnePending('Die Sitzung ist abgelaufen – bitte erneut anmelden.');
        }

        $pending = $this->pendingLogin->open(Cookie::pendingLoginKeyFrom($request));
        if ($pending === null) {
            return $this->seiteOhnePending();
        }

        /** @var MfaToolbox $tools */
        $tools = ($this->tools)();
        $ip = $request->ip;

        if ($tools->mfa->isBlocked($ip, $pending->userId)) {
            return $this->seite(
                $pending->mfaMethod,
                fehler: 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
            );
        }

        $code = trim((string) ($request->post['code'] ?? ''));
        $verified = $tools->mfa->verifyTotp($pending->userId, $code)
            || $tools->mfa->verifyEmailCode($pending->userId, $code);

        if (!$verified) {
            $tools->mfa->registerFailure($ip, $pending->userId);
            $tools->audit->record(AuditAction::ZweiterFaktorFehlgeschlagen, null, $ip, $pending->userId, ['art' => 'code']);

            return $this->seite($pending->mfaMethod, fehler: 'Der Code ist ungültig oder abgelaufen.');
        }

        return $this->abschliessen($request, $pending, $tools);
    }

    /**
     * Sends (or resends) the e-mail code - the account's own method, or a
     * TOTP account's fallback (docs/spec/01-sicherheit.md section 3:
     * "E-Mail-Code als Alternative").
     */
    public function sendEmailCode(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seiteOhnePending('Die Sitzung ist abgelaufen – bitte erneut anmelden.');
        }

        $pending = $this->pendingLogin->open(Cookie::pendingLoginKeyFrom($request));
        if ($pending === null) {
            return $this->seiteOhnePending();
        }

        /** @var MfaToolbox $tools */
        $tools = ($this->tools)();

        if ($tools->mfa->isBlocked($request->ip, $pending->userId)) {
            return $this->seite(
                $pending->mfaMethod,
                fehler: 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
            );
        }

        $user = $tools->users->findById($pending->userId);
        if ($user === null) {
            return $this->seiteOhnePending();
        }

        $code = $tools->mfa->requestEmailCode($pending->userId);
        $ergebnis = $tools->mailer->sendeMfaCode(
            $tools->crypto->decrypt($user->emailEnc),
            $code,
            intdiv(MfaService::EMAIL_CODE_TTL_SECONDS, 60),
        );

        return $this->seite(
            $pending->mfaMethod,
            hinweis: $ergebnis->erfolg
                ? 'Ein Code wurde per E-Mail versendet.'
                : 'Der Code konnte nicht versendet werden. Bitte versuchen Sie es erneut.',
        );
    }

    /** The backup code, for a lost or unavailable device/mailbox. */
    public function submitBackupCode(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seiteOhnePending('Die Sitzung ist abgelaufen – bitte erneut anmelden.');
        }

        $pending = $this->pendingLogin->open(Cookie::pendingLoginKeyFrom($request));
        if ($pending === null) {
            return $this->seiteOhnePending();
        }

        /** @var MfaToolbox $tools */
        $tools = ($this->tools)();
        $ip = $request->ip;

        if ($tools->mfa->isBlocked($ip, $pending->userId)) {
            return $this->seite(
                $pending->mfaMethod,
                fehler: 'Zu viele Versuche. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
            );
        }

        $code = (string) ($request->post['code'] ?? '');
        if (!$tools->mfa->verifyBackupCode($pending->userId, $code)) {
            $tools->mfa->registerFailure($ip, $pending->userId);
            $tools->audit->record(AuditAction::ZweiterFaktorFehlgeschlagen, null, $ip, $pending->userId, ['art' => 'backup_code']);

            return $this->seite($pending->mfaMethod, fehler: 'Dieser Backup-Code ist ungültig oder bereits verbraucht.');
        }

        return $this->abschliessen($request, $pending, $tools, backupCodeVerwendet: true);
    }

    private function abschliessen(
        Request $request,
        PendingLoginData $pending,
        MfaToolbox $tools,
        bool $backupCodeVerwendet = false,
    ): ResponseInterface {
        $tools->mfa->resetLimit($request->ip, $pending->userId);
        $tools->users->touchLastLogin($pending->userId);
        $this->pendingLogin->clear();
        $geraetMerken = isset($request->post['geraet_merken']) && !$backupCodeVerwendet;
        $tools->audit->record(AuditAction::LoginErfolg, $pending->userId, $request->ip, $pending->userId, [
            'zweiter_faktor' => $backupCodeVerwendet ? 'backup_code' : 'code',
            'geraet_gemerkt' => $geraetMerken,
        ]);

        $antwort = $this->completer->complete($pending->userId, $pending->vault, $pending->vaultAccess, $pending->weiter, $pending->sessionEpoch)
            ->withCookie(Cookie::pendingLoginKey('', Request::httpsFromGlobals())->expired());

        if ($geraetMerken) {
            $tage = MfaService::rememberDaysFromSettings($tools->settings);
            $label = 'Browser · ' . new \DateTimeImmutable()->format('d.m.Y');
            $token = $tools->mfa->rememberDevice($pending->userId, $label, $tage);

            $antwort = $antwort->withCookie(Cookie::trustedDevice($token, Request::httpsFromGlobals(), $tage));

            $user = $tools->users->findById($pending->userId);
            if ($user !== null) {
                $tools->mailer->sendeSicherheitshinweis(
                    $tools->crypto->decrypt($user->emailEnc),
                    'Ein neues Gerät wurde für die Anmeldung ohne zweiten Faktor gemerkt (30 Tage).',
                );
            }
        }

        if ($backupCodeVerwendet) {
            $this->session->flash(
                'Anmeldung mit Backup-Code erfolgt. In den Kontoeinstellungen finden Sie, wie viele Codes noch übrig sind.',
                FlashArt::Info,
            );
        }

        return $antwort;
    }

    private function seite(MfaMethod $mfaMethod, ?string $fehler = null, ?string $hinweis = null): ResponseInterface
    {
        return Response::html($this->view->render('anmelden-bestaetigen', [
            'title' => 'Anmeldung bestätigen',
            'csrf' => $this->session->csrfToken(),
            'mfaMethod' => $mfaMethod,
            'fehler' => $fehler,
            'hinweis' => $hinweis,
        ], Area::Oeffentlich));
    }

    private function seiteOhnePending(?string $hinweis = null): ResponseInterface
    {
        $antwort = Response::redirect('/anmelden');
        if ($hinweis !== null) {
            $this->session->flash($hinweis, FlashArt::Fehler);
        }

        return $antwort;
    }
}
