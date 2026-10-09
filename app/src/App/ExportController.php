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
use App\Http\StreamResponse;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Export\ExportFilter;
use App\Service\Export\ZipExport;
use App\Service\MasterData\SupplierService;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The ZIP export /app/export (issue #76/M12-2,
 * docs/spec/05-auswertung-und-export.md section 2): the filters with a
 * preview of what the ZIP will hold, and the download itself.
 *
 * Permission `export.zip` - on the user side and not below /admin, because
 * the board, the auditors and the tax advisor hold it without having the
 * admin area. The period scope of an external role applies to the receipt
 * date (in SQL). The export decrypts, so it needs the unlocked vault of the
 * session (CLAUDE.md section 4); without it the page says so.
 *
 * The download is a POST with CSRF token: it writes the audit log, and the
 * token stays out of URLs. The filter travels as ids and dates only; the
 * download name names the period only.
 */
final readonly class ExportController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private ZipExport $export,
        private SupplierService $lieferanten,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private AuditLog $audit,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $filter = ExportFilter::ausFeldern($request->query, new \DateTimeImmutable());
        $fehler = $filter->fehler();

        return Response::html($this->view->render('app/export', [
            'title' => 'Export',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'filter' => $filter,
            'fehler' => $fehler,
            'plan' => $tresor === null || $fehler !== null ? null : $this->export->vorbereiten($filter, $this->bereich(), $tresor),
            'lieferanten' => $tresor === null ? [] : $this->lieferanten->liste($tresor),
            'kategorien' => $this->kategorien->all(),
            'kostenstellen' => $this->kostenstellen->all(),
            'csrf' => $this->session->csrfToken(),
            'darfMusterAendern' => $this->berechtigungen()->darf(Permission::AdminSettings),
        ], Area::App));
    }

    public function zip(Request $request): ResponseInterface
    {
        $this->session->start();
        $filter = ExportFilter::ausFeldern($request->post, new \DateTimeImmutable());
        $zurueck = '/app/export?' . http_build_query(array_filter($filter->alsFelder(), static fn(string $wert): bool => $wert !== ''));

        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck($zurueck, 'Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck($zurueck, 'Der Export wurde nicht erstellt. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }
        $fehler = $filter->fehler();
        if ($fehler !== null) {
            return $this->zurueck($zurueck, $fehler, FlashArt::Fehler);
        }

        $plan = $this->export->vorbereiten($filter, $this->bereich(), $tresor);
        if ($plan->anzahlBelege === 0) {
            return $this->zurueck($zurueck, 'Für diese Auswahl gibt es keine Belege – es wurde kein ZIP erstellt.', FlashArt::Info);
        }
        if ($plan->zuGross()) {
            return $this->zurueck($zurueck, 'Der Export wäre zu groß für eine ZIP-Datei. Bitte den Zeitraum verkleinern, z. B. je Quartal.', FlashArt::Fehler);
        }

        $this->audit->record(
            AuditAction::ExportErstellt,
            $this->session->userId(),
            $request->ip,
            details: [...$filter->auditDetails(), 'belege' => $plan->anzahlBelege, 'dateien' => $plan->anzahlDateien()],
        );

        // The download can take minutes: the session file must not stay
        // locked meanwhile, or every other tab of this user waits for it.
        $this->session->schliessen();
        // Compression by PHP would hold back output and undo the streaming.
        if (!headers_sent()) {
            ini_set('zlib.output_compression', '0');
        }

        return new StreamResponse($this->export->strom($plan, $tresor, new \DateTimeImmutable()), [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $plan->dateiname() . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            // No proxy buffering (nginx in front of Apache at many hosts).
            'X-Accel-Buffering' => 'no',
        ]);
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
        return $this->berechtigungen()->zugriffsbereich(Permission::ExportZip);
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
