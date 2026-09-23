<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Account\UserAdministration;
use App\Service\Account\VaultRecovery;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\Mailer;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The paper recovery key (issue #22/M3-9, docs/spec/01-sicherheit.md
 * section 2 "Letzter Admin hat Passwort vergessen"): behind
 * `admin.vault_grant` like `/admin/tresor`, because it is the same act -
 * unlocking the vault for an account - the last-resort path for when a
 * password reset left that account with no grant and nobody left to grant
 * one.
 *
 * Deliberately self-service: `App\Service\Account\VaultRecovery` seals to the
 * caller's own key pair, and this controller immediately unlocks the vault in
 * *this* session (`SessionVault` + `__Host-vk`, the same step
 * `App\App\LoginCompleter` takes after a login) - the point is to get working
 * again without a second admin, not to grant somebody else's account.
 *
 * Every attempt is audited, successful or not, and a security mail goes to
 * every admin who can grant the vault - a wrong or misused key is meant to be
 * noticed by more than the one session that typed it.
 */
final readonly class VaultRecoveryController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private VaultRecovery $recovery,
        private UserAdministration $verwaltung,
        private Mailer $mailer,
        private ServerCrypto $crypto,
        private AuditLog $audit,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(entsperrt: $this->istEntsperrt($request));
    }

    public function absenden(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->formular(entsperrt: $this->istEntsperrt($request), fehler: [
                'Die Sitzung ist abgelaufen – bitte erneut versuchen.',
            ]);
        }

        $userId = (int) $this->session->userId();
        $eingabe = (string) ($request->post['schluessel'] ?? '');

        $ergebnis = $this->recovery->wiederherstellen($userId, $eingabe);

        if (!$ergebnis->istErfolg()) {
            $this->audit->record(AuditAction::TresorWiederherstellungFehlgeschlagen, $userId, $request->ip, $userId, [
                'grund' => $ergebnis->grund,
            ]);

            return $this->formular(entsperrt: false, fehler: [$ergebnis->fehler ?? 'Unbekannter Fehler.']);
        }

        $vault = $ergebnis->vault;
        assert($vault !== null);

        $this->audit->record(AuditAction::TresorWiederhergestellt, $userId, $request->ip, $userId, [
            'tresor_version' => $vault->version,
            'freigabe' => $ergebnis->freigabeErteilt,
        ]);
        $this->benachrichtigen();

        $this->session->flash('Der Tresor wurde mit dem Wiederherstellungsschlüssel entsperrt.', FlashArt::Ok);

        return Response::redirect('/admin/tresor')->withCookie(
            Cookie::vaultKey($this->sessionVault->store($vault), Request::httpsFromGlobals()),
        );
    }

    /**
     * @param list<string> $fehler
     */
    private function formular(bool $entsperrt, array $fehler = []): ResponseInterface
    {
        return Response::html($this->view->render('admin/wiederherstellen', [
            'title' => 'Tresor wiederherstellen',
            'flash' => $this->session->pullFlash(),
            'csrf' => $this->session->csrfToken(),
            'fehler' => $fehler,
            'entsperrt' => $entsperrt,
        ], Area::Admin));
    }

    private function istEntsperrt(Request $request): bool
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request)) !== null;
        } catch (CryptoException) {
            return false;
        }
    }

    /**
     * Tells every admin who can grant the vault, including whoever just used
     * the key - a wrong or misused recovery key is meant to stand out to more
     * than the one session that typed it (docs/spec/01-sicherheit.md
     * section 3 "Sicherheits-Mails an den Nutzer").
     */
    private function benachrichtigen(): void
    {
        foreach ($this->verwaltung->freigeber() as $admin) {
            $this->mailer->sendeSicherheitshinweis(
                $this->crypto->decrypt($admin->emailEnc),
                'Der Tresor wurde mit dem Wiederherstellungsschlüssel des Vereins entsperrt.',
            );
        }
    }
}
