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
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Export\Nextcloud\NextcloudZiel;
use App\Service\Export\Nextcloud\NextcloudZielFehler;
use App\Service\Export\Nextcloud\NextcloudZielSpeicher;
use App\Service\Export\Nextcloud\WebDav;
use App\Service\Export\Nextcloud\WebDavFehler;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page for the Nextcloud filing of the export (issue #95,
 * docs/spec/05-auswertung-und-export.md section 2 "Nextcloud-Ablage"):
 * WebDAV address of the folder, user and app password - stored in the
 * vault, so the page needs the unlocked vault to show or change them -, a
 * connection test and removal.
 *
 * Permission: `admin.settings` (app/src/routes.php), like the export
 * pattern. The password never goes back to the browser: an empty field
 * keeps the stored one. The audit log records that the target changed, not
 * the address or the user.
 */
final readonly class NextcloudSettingsController
{
    private const string SEITE = '/admin/export/nextcloud';

    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private NextcloudZielSpeicher $ziele,
        private WebDav $dav,
        private AuditLog $audit,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $ziel = $tresor === null ? null : $this->gespeichert($tresor);

        return $this->formular($tresor !== null, $ziel?->url ?? '', $ziel?->benutzer ?? '', null);
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck('Nichts gespeichert. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }

        $url = self::feld($request, 'url');
        $benutzer = self::feld($request, 'benutzer');
        $passwort = $request->post['passwort'] ?? '';
        $passwort = is_string($passwort) ? $passwort : '';
        if ($passwort === '') {
            // An empty field keeps the stored password - for the same
            // address only: it must never go along to another server.
            $alt = $this->gespeichert($tresor);
            if ($alt !== null && rtrim($url, '/') !== $alt->url) {
                return $this->formular(true, $url, $benutzer, 'Die Adresse hat sich geändert – bitte das App-Passwort erneut eingeben.', 422);
            }
            $passwort = $alt?->passwort ?? '';
        }

        try {
            $ziel = NextcloudZiel::pruefen($url, $benutzer, $passwort);
        } catch (NextcloudZielFehler $fehler) {
            return $this->formular(true, $url, $benutzer, $fehler->getMessage(), 422);
        }

        $this->ziele->speichern($tresor, $ziel, $this->session->userId(), new \DateTimeImmutable());
        $this->audit->record(AuditAction::EinstellungNextcloud, $this->session->userId(), $request->ip, details: ['aktion' => 'gespeichert']);

        return $this->zurueck('Nextcloud-Ablage gespeichert. Prüfen Sie sie mit „Verbindung testen“.', FlashArt::Ok);
    }

    /** PROPFIND on the folder: reachable, logged in, and a folder. */
    public function testen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck(self::TRESOR_GESPERRT, FlashArt::Fehler);
        }
        $ziel = $this->gespeichert($tresor);
        if ($ziel === null) {
            return $this->zurueck('Es ist noch keine Nextcloud eingerichtet.', FlashArt::Fehler);
        }

        try {
            $ordner = $this->dav->eigenschaften($ziel, '');
        } catch (WebDavFehler $fehler) {
            return $this->zurueck($fehler->getMessage(), FlashArt::Fehler);
        }
        if ($ordner === null || !$ordner->ordner) {
            return $this->zurueck('Unter dieser Adresse liegt in der Nextcloud kein Ordner – bitte die Adresse prüfen.', FlashArt::Fehler);
        }

        return $this->zurueck(sprintf('Verbindung in Ordnung: Der Ordner auf %s ist erreichbar.', $ziel->host()), FlashArt::Ok);
    }

    public function entfernen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        if (!$this->ziele->loeschen()) {
            return $this->zurueck('Es war keine Nextcloud eingerichtet.', FlashArt::Info);
        }
        $this->audit->record(AuditAction::EinstellungNextcloud, $this->session->userId(), $request->ip, details: ['aktion' => 'entfernt']);

        return $this->zurueck('Nextcloud-Ablage entfernt. Bereits abgelegte Dateien bleiben in der Nextcloud.', FlashArt::Ok);
    }

    private function formular(bool $entsperrt, string $url, string $benutzer, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/nextcloud', [
            'title' => 'Nextcloud-Ablage',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $entsperrt,
            'eingerichtet' => $this->ziele->eingerichtet(),
            'url' => $url,
            'benutzer' => $benutzer,
            'fehler' => $fehler,
        ], Area::Admin), $status);
    }

    private function gespeichert(Vault $tresor): ?NextcloudZiel
    {
        try {
            return $this->ziele->lesen($tresor);
        } catch (CryptoException | \JsonException) {
            return null;
        }
    }

    private static function feld(Request $request, string $name): string
    {
        $wert = $request->post[$name] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    private function zurueck(string $meldung, FlashArt $art): ResponseInterface
    {
        $this->session->flash($meldung, $art);

        return Response::redirect(self::SEITE);
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }
}
