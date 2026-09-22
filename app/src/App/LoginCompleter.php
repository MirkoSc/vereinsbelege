<?php

declare(strict_types=1);

namespace App\App;

use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Account\VaultAccess;
use App\Service\Crypto\Vault;
use App\View\FlashArt;

/**
 * The last step shared by every way a login can finish (issue #17/M3-4):
 * directly from App\App\AuthController::submit() when no second factor
 * stands in the way, or from App\App\MfaController once one is confirmed.
 * Pulled out so that step - Session::login(), the vault cookie, the "no
 * grant yet" flash - exists exactly once instead of twice with the risk of
 * drifting apart.
 *
 * What it deliberately does NOT do: record `last_login_at`
 * (App\Repository\UserRepository::touchLastLogin()) or register a trusted
 * device. Both callers already hold what they need for that (a
 * LoginService/UserRepository, an MfaService) and the order they do it in
 * relative to this differs slightly, so it stays their call.
 */
final readonly class LoginCompleter
{
    public function __construct(
        private Session $session,
        private SessionVault $vaultSession,
    ) {
    }

    /**
     * @param int $sessionEpoch `user.session_epoch` from the moment the
     *        password was checked, not re-read here: a reset that happened
     *        in between (while a second factor was pending) must end this
     *        session too, not be silently adopted by it (issue #18/M3-5).
     */
    public function complete(
        int $userId,
        ?Vault $vault,
        VaultAccess $vaultAccess,
        ?string $weiter,
        int $sessionEpoch = 0,
    ): ResponseInterface {
        $this->session->login($userId, epoch: $sessionEpoch);

        $antwort = Response::redirect($weiter ?? '/app');

        if ($vault === null || $vaultAccess !== VaultAccess::Entsperrt) {
            // Logged in without a readable vault - a normal state of the
            // user lifecycle (M3-5/M3-7), so it is explained, not hidden.
            $this->session->flash(
                $vaultAccess->meldung() ?? 'Der Tresor ist nicht entsperrt.',
                FlashArt::Warnung,
            );

            return $antwort;
        }

        return $antwort->withCookie(
            Cookie::vaultKey($this->vaultSession->store($vault), Request::httpsFromGlobals()),
        );
    }
}
