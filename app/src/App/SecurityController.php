<?php

declare(strict_types=1);

namespace App\App;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\UserRepository;
use App\Service\Account\MfaEnrollment;
use App\Service\Account\MfaService;
use App\Service\Account\PasswordChange;
use App\Service\Account\Totp;
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
 * `/admin` is reachable. Roles and the `Permission` enum are still M3-6, so
 * for now every logged-in account manages its own second factor here and
 * nothing else.
 *
 * The setup flow is one view (`app/sicherheit-einrichten`) with several
 * steps rendered directly from a POST response rather than a redirect -
 * the same "resume from where the browser already is" choice
 * app/views/install.php makes for its own multi-step forms. A lost QR code
 * (a closed tab, a reload) is not a state worth resuming: starting over is
 * one click, and a secret nobody has scanned yet is not worth keeping
 * (App\Service\Account\MfaEnrollment::startTotp()).
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
        $this->requireUserId();

        return $this->seite('waehlen');
    }

    public function totpStarten(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seite('waehlen', fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        $userId = $this->requireUserId();

        $secret = $this->enrollment->startTotp($userId);
        $user = $this->users->findById($userId);
        $email = $user === null ? '' : $this->crypto->decrypt($user->emailEnc);

        return $this->seite('totp', totpSecretBase32: Totp::base32Encode($secret), totpUri: Totp::uri($secret, $email, 'Vereinsbelege'));
    }

    public function totpBestaetigen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->seite('waehlen', fehler: 'Die Sitzung ist abgelaufen – bitte erneut versuchen.');
        }
        $userId = $this->requireUserId();

        $code = trim((string) ($request->post['code'] ?? ''));
        $codes = $this->enrollment->confirmTotp($userId, $code);

        if ($codes === null) {
            return $this->seite('totp', fehler: 'Der Code stimmt nicht. Bitte erneut versuchen.');
        }

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

        $code = trim((string) ($request->post['code'] ?? ''));
        if (!$this->mfa->verifyEmailCode($userId, $code)) {
            return $this->seite('email', fehler: 'Der Code stimmt nicht oder ist abgelaufen.');
        }

        $codes = $this->enrollment->commitEmailMethod($userId);
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

        $codes = $this->enrollment->regenerateBackupCodes($userId);
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

        $this->mfa->revokeDevice((int) ($params['id'] ?? 0), $userId);
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

        $this->hinweisSenden($userId, 'Ihr Passwort wurde geändert. Andere angemeldete Sitzungen wurden beendet.');
        $this->session->flash('Ihr Passwort wurde geändert. Andere angemeldete Sitzungen wurden beendet.', FlashArt::Ok);

        return Response::redirect('/app/sicherheit');
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
            'totpQrSvg' => $totpUri === null ? null : QrCode::svg($totpUri),
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
