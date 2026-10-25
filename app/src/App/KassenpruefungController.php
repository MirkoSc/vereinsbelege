<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Report\Kassenpruefung;
use App\Service\Report\KassenpruefungCsv;
use App\Service\Report\ZeitraumAuswahl;
use App\View\Area;
use App\View\View;

/**
 * The Kassenprüfung /app/auswertung/kassenpruefung (issue #74, docs/spec/
 * 05-auswertung-und-export.md section 1, E-52): a period → the
 * completeness report as a printable page, each table also as CSV.
 *
 * Rights (app/src/routes.php): the page `report.view`, the downloads
 * `export.csv`. Both show bank data, so both also need `bank.view` -
 * checked here, server side; its period scope narrows bookings and
 * balances in SQL (external auditors see their released period only, the
 * opening balance as a carry-over, E-44). Receipts behind an allocation
 * are named within the scope of `report.view`. Without an unlocked vault
 * the page says so and shows nothing; a download answers 403.
 *
 * The period comes from the query string (App\Service\Report\
 * ZeitraumAuswahl) - plain numbers and dates. Nothing of a booking goes into
 * a URL or a file name. Page and downloads are never cached by the browser.
 */
final readonly class KassenpruefungController
{
    public const string PFAD = '/app/auswertung/kassenpruefung';

    /**
     * @param \Closure(): Kassenpruefung $kassenpruefung built only when the
     *        report is actually read (it opens the database)
     */
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private \Closure $kassenpruefung,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();
        $b = $this->berechtigungen();
        $heute = new \DateTimeImmutable('today');
        $auswahl = ZeitraumAuswahl::ausAnfrage($request->query, self::standard($b, $heute), $heute);
        $tresor = $this->tresor($request);
        $darfBank = $b->darf(Permission::BankView);

        $bericht = $tresor !== null && $darfBank
            ? ($this->kassenpruefung)()->bericht($tresor, $auswahl->zeitraum, $b->zugriffsbereich(Permission::BankView), $b->zugriffsbereich(Permission::ReportView))
            : null;

        return self::ohneCache(Response::html($this->view->render('app/kassenpruefung', [
            'title' => 'Kassenprüfung',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'darfBank' => $darfBank,
            'darfCsv' => $b->darf(Permission::ExportCsv),
            'auswahl' => $auswahl,
            'bericht' => $bericht,
            'erstesJahr' => ZeitraumAuswahl::ERSTES_JAHR,
            'letztesJahr' => (int) $heute->format('Y') + 1,
            'scripts' => ['/js/drucken.js'],
        ], Area::App)));
    }

    /**
     * One table as CSV (`buchungen`, `salden`, `ohne-beleg`, `manuell`).
     * Built in memory and sent as one string - no file on the disk.
     *
     * @param array<string, string> $params
     */
    public function csv(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $teil = $params['teil'] ?? '';
        if (!isset(KassenpruefungCsv::TEILE[$teil])) {
            return $this->fehler(404, 'Nicht gefunden', 'Diese Tabelle gibt es in der Kassenprüfung nicht.');
        }
        $b = $this->berechtigungen();
        if (!$b->darf(Permission::ReportView) || !$b->darf(Permission::BankView)) {
            return $this->fehler(403, 'Kein Zugriff', 'Für die Kassenprüfung braucht Ihr Konto die Rechte „Auswertungen sehen“ und „Konten und Buchungen sehen“.');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->fehler(403, 'Tresor gesperrt', 'Die Daten sind verschlüsselt und nur mit entsperrtem Tresor lesbar. Bitte melden Sie sich neu an.');
        }

        $heute = new \DateTimeImmutable('today');
        $auswahl = ZeitraumAuswahl::ausAnfrage($request->query, self::standard($b, $heute), $heute);
        $bericht = ($this->kassenpruefung)()->bericht($tresor, $auswahl->zeitraum, $b->zugriffsbereich(Permission::BankView), $b->zugriffsbereich(Permission::ReportView));
        $zeitraum = $bericht->sichtbar ?? $auswahl->zeitraum;

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . KassenpruefungCsv::dateiname($teil, $zeitraum) . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], KassenpruefungCsv::tabelle($bericht, $teil)->csv());
    }

    /**
     * The period the page opens with: the account's released period when it
     * has one (external auditors; when it spans more than
     * ZeitraumAuswahl::MAX_JAHRE_FREI years, its last calendar year), else
     * the last completed calendar year - what a Kassenprüfung usually looks
     * at.
     */
    public static function standard(Berechtigungen $b, \DateTimeImmutable $heute): ZeitraumAuswahl
    {
        $bereich = $b->darf(Permission::BankView) ? $b->zugriffsbereich(Permission::BankView) : $b->zugriffsbereich(Permission::ReportView);
        if ($bereich->von === null && $bereich->bis === null) {
            return ZeitraumAuswahl::jahr((int) $heute->format('Y') - 1);
        }
        $bis = $bereich->bis ?? $heute;
        $von = $bereich->von ?? new \DateTimeImmutable($bis->format('Y') . '-01-01');
        // A released period longer than a free period may be (issue #250)
        // opens with its last calendar year; the rest stays selectable.
        if ($bis >= $von->modify('+' . ZeitraumAuswahl::MAX_JAHRE_FREI . ' years')) {
            $von = new \DateTimeImmutable($bis->format('Y') . '-01-01');
        }

        return ZeitraumAuswahl::frei($von, max($von, $bis));
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    private function fehler(int $status, string $titel, string $meldung): Response
    {
        return self::ohneCache(Response::html($this->view->render('error', [
            'title' => $titel,
            'message' => $meldung,
            'startseite' => self::PFAD,
        ], Area::App), $status));
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private static function ohneCache(Response $response): Response
    {
        return new Response($response->status, [...$response->headers, 'Cache-Control' => 'no-store, private'], $response->body, $response->additionalCookies);
    }
}
