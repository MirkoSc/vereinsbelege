<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\User;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\UserRepository;
use App\Repository\VaultGrantRepository;
use App\Repository\VaultRepository;
use App\Service\Account\SessionVault;
use App\Service\Account\UserAdministration;
use App\Service\Account\UserRuleViolation;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;
use App\Service\Mail\Mailer;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Vault grants (issue #20/M3-7, docs/spec/01-sicherheit.md section 2
 * "Freigabe"): who waits for access, who has it, granting and revoking.
 *
 * Behind `admin.vault_grant` (app/src/routes.php). Granting needs the
 * admin's own unlocked vault: it is taken from this session
 * (SessionVault + the `__Host-vk` cookie) for the one request and handed to
 * UserAdministration::freigeben() - no other way to VK_priv exists on the
 * server. Without it (a session whose cookie is gone, an admin without a
 * grant of their own) the page says so instead of offering the button.
 *
 * Every change mails the account concerned ("Sicherheits-Mails an den
 * Nutzer: ... Tresor-Freigabe erteilt/entzogen", section 3).
 */
final readonly class VaultGrantController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private UserRepository $users,
        private VaultRepository $vaults,
        private VaultGrantRepository $grants,
        private UserAdministration $verwaltung,
        private Mailer $mailer,
        private ServerCrypto $crypto,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();
        $jetzt = new \DateTimeImmutable();
        $aktuell = $this->vaults->current();
        $mitGrant = $aktuell === null ? [] : $this->grants->grantsFor($aktuell->version);
        $ausstehend = array_flip($this->verwaltung->ausstehend($jetzt));

        $konten = $this->users->all();
        $namen = [];
        foreach ($konten as $user) {
            $namen[$user->id] = $this->crypto->decrypt($user->displayNameEnc);
        }

        $wartend = [];
        $freigegeben = [];
        foreach ($konten as $user) {
            $zeile = [
                'id' => $user->id,
                'name' => $namen[$user->id],
                'email' => $this->crypto->decrypt($user->emailEnc),
            ];
            if (isset($ausstehend[$user->id])) {
                $wartend[] = $zeile;
            } elseif (isset($mitGrant[$user->id])) {
                $von = $mitGrant[$user->id]['granted_by'];
                $freigegeben[] = [
                    ...$zeile,
                    'seit' => $mitGrant[$user->id]['granted_at'],
                    'durch' => $von === null ? 'Installation' : ($namen[$von] ?? '–'),
                    'aktiv' => $user->mayLogIn($jetzt),
                ];
            }
        }
        $nachName = static fn(array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
        usort($wartend, $nachName);
        usort($freigegeben, $nachName);

        return Response::html($this->view->render('admin/tresor', [
            'title' => 'Tresor-Freigaben',
            'flash' => $this->session->pullFlash(),
            'wartend' => $wartend,
            'freigegeben' => $freigegeben,
            'entsperrt' => $this->tresor($request) !== null,
            'eigeneId' => $this->session->userId(),
        ], Area::Admin));
    }

    /**
     * @param array<string, string> $params
     */
    public function freigeben(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $id = (int) $params['id'];
        $tresor = $this->tresor($request);
        try {
            if ($tresor === null) {
                throw new UserRuleViolation('Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.');
            }
            $this->verwaltung->freigeben($id, $tresor, (int) $this->session->userId());
        } catch (UserRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/admin/tresor');
        }

        $user = $this->users->findById($id);
        if ($user !== null) {
            $this->hinweis($user, 'Ihr Zugang wurde für den Tresor freigegeben. Nach der nächsten Anmeldung sehen Sie die Belege, die Ihre Rollen erlauben.');
        }
        $this->session->flash('Freigabe erteilt. Sie wirkt ab der nächsten Anmeldung des Kontos.');

        return Response::redirect('/admin/tresor');
    }

    /**
     * @param array<string, string> $params
     */
    public function entziehen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $id = (int) $params['id'];
        try {
            $this->verwaltung->entziehen($id, (int) $this->session->userId());
        } catch (UserRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/admin/tresor');
        }

        $user = $this->users->findById($id);
        if ($user !== null) {
            $this->hinweis($user, 'Die Freigabe Ihres Zugangs für den Tresor wurde entzogen. Sie können sich weiter anmelden, sehen aber keine Belege mehr.');
        }
        $this->session->flash('Freigabe entzogen. Laufende Sitzungen des Kontos sind beendet.');

        return Response::redirect('/admin/tresor');
    }

    /** The admin's own vault, unlocked for this request - or null. */
    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private function hinweis(User $user, string $ereignis): void
    {
        $this->mailer->sendeSicherheitshinweis($this->crypto->decrypt($user->emailEnc), $ereignis);
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect('/admin/tresor');
    }
}
