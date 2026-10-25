<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Export\DatevExport;
use App\Service\Export\DatevZeitraum;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The DATEV export for the tax advisor /app/datev (issue #93, E-49,
 * docs/spec/05-auswertung-und-export.md section 4): the period, a preview
 * of what the Buchungsstapel holds and what is still missing, and the
 * download.
 *
 * Permission `export.csv` - on the user side, because the board, the
 * auditors and the tax advisor hold it without the admin area. The period
 * scope of an external role applies to the booking date (in SQL). The
 * export decrypts, so it needs the unlocked vault of the session.
 *
 * The download is a POST with CSRF token; the file is built in memory for
 * this one response and never cached - a club has a few thousand bookings
 * a year, well within one short request. Its name names the period only.
 */
final readonly class DatevController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    private const string UNLESBAR = 'Mindestens eine Buchung in diesem Zeitraum lässt sich nicht entschlüsseln – der Export ist nicht möglich.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private DatevExport $export,
        private AuditLog $audit,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $zeitraum = DatevZeitraum::ausFeldern($request->query, new \DateTimeImmutable('today'));
        $fehler = $zeitraum->fehler();
        $plan = null;
        if ($tresor !== null && $fehler === null) {
            try {
                $plan = $this->export->vorbereiten($tresor, $zeitraum, $this->bereich(), new \DateTimeImmutable());
            } catch (CryptoException | \JsonException) {
                $fehler = self::UNLESBAR;
            }
        }

        return Response::html($this->view->render('app/datev', [
            'title' => 'DATEV-Export',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'zeitraum' => $zeitraum,
            'fehler' => $fehler,
            'plan' => $plan,
            'einstellungen' => $this->export->einstellungen(),
            'csrf' => $this->session->csrfToken(),
            'darfEinstellen' => $this->berechtigungen()->darf(Permission::AdminSettings),
        ], Area::App));
    }

    public function datei(Request $request): ResponseInterface
    {
        $this->session->start();
        $zeitraum = DatevZeitraum::ausFeldern($request->post, new \DateTimeImmutable('today'));
        $zurueck = '/app/datev?' . http_build_query($zeitraum->alsFelder());

        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck($zurueck, 'Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck($zurueck, 'Der Export wurde nicht erstellt. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }
        $fehler = $zeitraum->fehler();
        if ($fehler !== null) {
            return $this->zurueck($zurueck, $fehler, FlashArt::Fehler);
        }

        try {
            $plan = $this->export->vorbereiten($tresor, $zeitraum, $this->bereich(), new \DateTimeImmutable());
        } catch (CryptoException | \JsonException) {
            return $this->zurueck($zurueck, self::UNLESBAR, FlashArt::Fehler);
        }
        if (!$plan->istExportierbar()) {
            return $this->zurueck($zurueck, 'Der Export wurde nicht erstellt – bitte zuerst die genannten Punkte erledigen.', FlashArt::Fehler);
        }
        $datei = $plan->datei();

        $this->audit->record(AuditAction::ExportDatev, $this->session->userId(), $request->ip, details: [
            ...$zeitraum->alsFelder(),
            'buchungen' => count($plan->zeilen),
        ]);

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="' . $zeitraum->dateiname() . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], $datei);
    }

    private function zurueck(string $ziel, string $meldung, FlashArt $art): ResponseInterface
    {
        $this->session->flash($meldung, $art);

        return Response::redirect($ziel);
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    private function bereich(): Zugriffsbereich
    {
        return $this->berechtigungen()->zugriffsbereich(Permission::ExportCsv);
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
