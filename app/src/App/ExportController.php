<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\Berechtigungen;
use App\Domain\ExportRun;
use App\Domain\ExportRunStatus;
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
use App\Repository\ExportRunRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Export\ExportEinstellungen;
use App\Service\Export\ExportFilter;
use App\Service\Export\Nextcloud\NextcloudAblage;
use App\Service\Export\Nextcloud\NextcloudAblageFehler;
use App\Service\Export\Nextcloud\WebDavFehler;
use App\Service\Export\ZipExport;
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
 * Every export is an export run (issue #179/M12-4): the form creates the
 * run - which documents it holds - and leads to its page; there the ZIP is
 * downloaded, as often as needed, and only the user's confirmation
 * "Vollständig gespeichert" marks the documents as exported, for the whole
 * club. The server streams the ZIP and never learns whether it arrived, so
 * an aborted download marks nothing. A confirmed run can be reset.
 *
 * A run is visible to the user who created it and to every holder of
 * `export.zip` without a scope - a scoped role (the tax advisor) sees only
 * its own runs. Downloading a run applies the current scope again.
 *
 * Instead of the download a run can be filed into the club's Nextcloud
 * (issue #95, App\Service\Export\Nextcloud\NextcloudAblage): the run page
 * starts the chain, public/js/nextcloud.js drives its steps. Only a holder
 * of `export.zip` without a scope may do so - the cloud folder is the
 * club's archive. Filing marks nothing either; confirming stays the user's.
 *
 * Every write is a POST with CSRF token, and the token stays out of URLs.
 * The filter travels as ids and dates only; the download name names the
 * period only.
 */
final readonly class ExportController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    /** Runs listed on /app/export. */
    private const int LETZTE = 10;

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private ZipExport $export,
        private ExportRunRepository $laeufe,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private AuditLog $audit,
        private ?NextcloudAblage $nextcloud = null,
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
            'plan' => $tresor === null || $fehler !== null ? null : $this->export->vorbereiten($filter, $this->bereich(), $tresor, null, $this->buchungsbereich()),
            'lieferanten' => $tresor === null ? [] : $this->export->lieferantenAuswahl($this->bereich(), $tresor),
            'kategorien' => $this->kategorien->all(),
            'kostenstellen' => $this->kostenstellen->all(),
            'laeufe' => $this->laeufe->letzte($this->bereich()->istUnbeschraenkt() ? null : ($this->session->userId() ?? 0), self::LETZTE),
            'csrf' => $this->session->csrfToken(),
            'darfMusterAendern' => $this->berechtigungen()->darf(Permission::AdminSettings),
        ], Area::App));
    }

    /**
     * Creates the run from the filter - the documents of the preview, as
     * `offen` - and leads to its page. Writes no audit entry: nothing has
     * been read out yet, and the run marks nothing.
     */
    public function anlegen(Request $request): ResponseInterface
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

        $plan = $this->export->vorbereiten($filter, $this->bereich(), $tresor, null, $this->buchungsbereich());
        if ($plan->anzahlBelege === 0) {
            return $this->zurueck($zurueck, 'Für diese Auswahl gibt es keine Belege – es wurde kein ZIP erstellt.', FlashArt::Info);
        }
        if ($plan->zuGross()) {
            return $this->zurueck($zurueck, 'Der Export wäre zu groß für eine ZIP-Datei. Bitte den Zeitraum verkleinern, z. B. je Quartal.', FlashArt::Fehler);
        }

        $id = $this->laeufe->anlegen($filter->alsFelder(), $plan->documentIds, $plan->anzahlBelege, $plan->anzahlDateien(), $this->session->userId(), new \DateTimeImmutable());

        return Response::redirect(self::pfad($id));
    }

    /** @param array<string, string> $params */
    public function lauf(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $lauf = $this->sichtbarerLauf($params);
        if ($lauf === null) {
            return $this->nichtGefunden();
        }
        $filter = $this->filter($lauf);

        return Response::html($this->view->render('app/export-lauf', [
            'title' => 'Export',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $this->tresor($request) !== null,
            'lauf' => $lauf,
            'filter' => $filter,
            'dateiname' => ExportEinstellungen::wurzelordner($filter->von, $filter->bis) . '.zip',
            'csrf' => $this->session->csrfToken(),
            'darfMarkieren' => $this->darfMarkieren(),
            'nextcloud' => $this->darfNextcloud() ? [
                'eingerichtet' => $this->nextcloud->eingerichtet(),
                'stand' => $this->nextcloud->stand($lauf->id),
                'darfEinrichten' => $this->berechtigungen()->darf(Permission::AdminSettings),
            ] : null,
            'scripts' => $this->darfNextcloud() ? ['/js/nextcloud.js'] : [],
        ], Area::App));
    }

    /**
     * Starts filing the run into the Nextcloud (issue #95) and leads back to
     * the run page, where public/js/nextcloud.js drives the steps.
     *
     * @param array<string, string> $params
     */
    public function nextcloudStarten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $lauf = $this->sichtbarerLauf($params);
        if ($lauf === null || !$this->darfNextcloud()) {
            return $this->nichtGefunden();
        }
        $zurueck = self::pfad($lauf->id);
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck($zurueck, 'Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck($zurueck, 'Nichts abgelegt. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }

        try {
            $this->nextcloud->starten($lauf, $this->bereich(), $this->buchungsbereich(), $tresor, $this->session->userId(), new \DateTimeImmutable());
        } catch (NextcloudAblageFehler | WebDavFehler $e) {
            return $this->zurueck($zurueck, $e->getMessage(), FlashArt::Fehler);
        }

        return Response::redirect($zurueck);
    }

    /**
     * One step of the filing, as JSON for public/js/nextcloud.js.
     *
     * @param array<string, string> $params
     */
    public function nextcloudSchritt(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $lauf = $this->sichtbarerLauf($params);
        if ($lauf === null || !$this->darfNextcloud()) {
            return Response::json(['fehler' => 'Diesen Export gibt es nicht.'], 404);
        }
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Die Sitzung ist abgelaufen – bitte die Seite neu laden.'], 403);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return Response::json(['fehler' => self::TRESOR_GESPERRT], 403);
        }
        // The steps of other tabs must not wait for this session's lock.
        $userId = $this->session->userId();
        $this->session->schliessen();

        try {
            $stand = $this->nextcloud->schritt($lauf, $this->bereich(), $this->buchungsbereich(), $tresor, $userId, $request->ip, new \DateTimeImmutable());
        } catch (NextcloudAblageFehler | WebDavFehler $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json([
            'status' => $stand->status->value,
            'verarbeitet' => $stand->nextIndex,
            'gesamt' => $stand->total,
            'hochgeladen' => $stand->hochgeladen,
            'vorhanden' => $stand->vorhanden,
            'umbenannt' => $stand->umbenannt,
        ]);
    }

    /**
     * @phpstan-assert-if-true !null $this->nextcloud
     */
    private function darfNextcloud(): bool
    {
        return $this->nextcloud !== null && $this->bereich()->istUnbeschraenkt();
    }

    /**
     * The download of a run: its documents as the current scope allows,
     * streamed. Repeatable, and it marks nothing - only bestaetigen() does.
     *
     * @param array<string, string> $params
     */
    public function zip(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $lauf = $this->sichtbarerLauf($params);
        if ($lauf === null) {
            return $this->nichtGefunden();
        }
        $zurueck = self::pfad($lauf->id);

        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck($zurueck, 'Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->zurueck($zurueck, 'Der Export wurde nicht erstellt. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }

        $filter = $this->filter($lauf);
        $plan = $this->export->vorbereiten($filter, $this->bereich(), $tresor, $lauf->id, $this->buchungsbereich());
        if ($plan->anzahlBelege === 0) {
            return $this->zurueck($zurueck, 'Die Belege dieses Exports sind nicht mehr verfügbar – es wurde kein ZIP erstellt.', FlashArt::Info);
        }
        if ($plan->zuGross()) {
            return $this->zurueck($zurueck, 'Der Export wäre zu groß für eine ZIP-Datei. Bitte den Zeitraum verkleinern, z. B. je Quartal.', FlashArt::Fehler);
        }

        $this->audit->record(
            AuditAction::ExportErstellt,
            $this->session->userId(),
            $request->ip,
            details: [...$filter->auditDetails(), 'lauf' => $lauf->id, 'belege' => $plan->anzahlBelege, 'dateien' => $plan->anzahlDateien()],
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

    /**
     * "Vollständig gespeichert": the run's documents count as exported.
     * Idempotent - a second click (or tab) finds the run confirmed and
     * writes no second audit entry. Needs no vault: ids only.
     *
     * @param array<string, string> $params
     */
    public function bestaetigen(Request $request, array $params): ResponseInterface
    {
        return $this->statuswechsel(
            $request,
            $params,
            fn(ExportRun $lauf, ?int $userId): bool => $this->laeufe->bestaetigen($lauf->id, $userId, new \DateTimeImmutable()),
            AuditAction::ExportMarkiert,
            static fn(ExportRun $lauf): string => sprintf('%d Belege sind als exportiert markiert.', $lauf->belege),
            static fn(ExportRun $lauf): string => $lauf->status === ExportRunStatus::Bestaetigt
                ? 'Dieser Export ist bereits als exportiert markiert.'
                : 'Ein zurückgesetzter Export lässt sich nicht erneut markieren. Bitte einen neuen Export erstellen.',
        );
    }

    /**
     * Takes a confirmation back: the run's documents count as not exported
     * again - unless another confirmed run holds them too.
     *
     * @param array<string, string> $params
     */
    public function zuruecksetzen(Request $request, array $params): ResponseInterface
    {
        return $this->statuswechsel(
            $request,
            $params,
            fn(ExportRun $lauf, ?int $userId): bool => $this->laeufe->zuruecksetzen($lauf->id, $userId, new \DateTimeImmutable()),
            AuditAction::ExportZurueckgesetzt,
            static fn(ExportRun $lauf): string => sprintf('Zurückgesetzt: %d Belege gelten aus diesem Export nicht mehr als exportiert.', $lauf->belege),
            static fn(ExportRun $lauf): string => 'Nur ein als exportiert markierter Export lässt sich zurücksetzen.',
        );
    }

    /**
     * @param array<string, string> $params
     * @param \Closure(ExportRun, ?int): bool $wechsel
     * @param \Closure(ExportRun): string $erfolg
     * @param \Closure(ExportRun): string $schonGeschehen
     */
    private function statuswechsel(Request $request, array $params, \Closure $wechsel, AuditAction $aktion, \Closure $erfolg, \Closure $schonGeschehen): ResponseInterface
    {
        $this->session->start();
        $lauf = $this->sichtbarerLauf($params);
        if ($lauf === null) {
            return $this->nichtGefunden();
        }
        if (!$this->darfMarkieren()) {
            return $this->nurLesend();
        }
        $zurueck = self::pfad($lauf->id);
        if (!$this->session->checkCsrf($request)) {
            return $this->zurueck($zurueck, 'Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);
        }

        $userId = $this->session->userId();
        if (!$wechsel($lauf, $userId)) {
            return $this->zurueck($zurueck, $schonGeschehen($this->laeufe->find($lauf->id) ?? $lauf), FlashArt::Info);
        }
        $this->audit->record($aktion, $userId, $request->ip, entityId: $lauf->id, details: ['belege' => $lauf->belege]);

        return $this->zurueck($zurueck, $erfolg($lauf), FlashArt::Ok);
    }

    /**
     * The run of the route, if this user may see it: the one who created
     * it, or anyone with `export.zip` without a scope.
     *
     * @param array<string, string> $params
     */
    private function sichtbarerLauf(array $params): ?ExportRun
    {
        $lauf = $this->laeufe->find((int) ($params['id'] ?? 0));
        if ($lauf === null) {
            return null;
        }

        return $this->bereich()->istUnbeschraenkt() || ($lauf->createdBy !== null && $lauf->createdBy === $this->session->userId())
            ? $lauf
            : null;
    }

    private function filter(ExportRun $lauf): ExportFilter
    {
        return ExportFilter::ausFeldern($lauf->filter, $lauf->createdAt);
    }

    private static function pfad(int $id): string
    {
        return '/app/export/lauf/' . $id;
    }

    /**
     * The marking counts for the whole club (05 section 2: "Wer nur zum
     * Ansehen exportiert (Kassenprüfer, Steuerberater), bestätigt nicht"),
     * and external roles are read only (01 section 4). `export.zip` alone
     * would let them confirm or reset anyone's run - security review,
     * issue #78.
     */
    private function darfMarkieren(): bool
    {
        return !$this->berechtigungen()->istExtern();
    }

    private function nurLesend(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Keine Berechtigung',
            'message' => 'Externe Zugänge können Exporte erstellen und herunterladen, aber nicht als exportiert markieren.',
            'startseite' => '/app/export',
        ], Area::App), 403);
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Export nicht gefunden',
            'message' => 'Diesen Export gibt es nicht.',
            'startseite' => '/app/export',
        ], Area::App), 404);
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

    /** Payment details in index.csv come from bookings: only with `bank.view`, within its scope. */
    private function buchungsbereich(): ?Zugriffsbereich
    {
        return $this->berechtigungen()->darf(Permission::BankView) ? $this->berechtigungen()->zugriffsbereich(Permission::BankView) : null;
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
