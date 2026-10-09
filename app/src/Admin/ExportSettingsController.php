<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Domain\InvoiceDirection;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Service\Export\BelegPfadDaten;
use App\Service\Export\ExportEinstellungen;
use App\Service\Export\PfadMuster;
use App\Service\Export\PfadVergabe;
use App\Service\Export\UngueltigesPfadMuster;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page for the folder and file name pattern of the ZIP export (issue
 * #75/M12-1, docs/spec/05-auswertung-und-export.md section 2): the pattern
 * field, the bundled patterns, and a preview built from made-up receipts -
 * the page never touches real receipts, so it needs no vault.
 *
 * Permission: `admin.settings` (app/src/routes.php), the same right as
 * Einreichung/Mail/Speicher - operating configuration. Plaintext setting.
 */
final readonly class ExportSettingsController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SettingRepository $settings,
        private AuditLog $audit,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(ExportEinstellungen::fromSettings($this->settings)->muster->muster, null, false);
    }

    /** Shows what a pattern would produce, without saving it. */
    public function vorschau(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $eingabe = self::eingabe($request);
        try {
            PfadMuster::parse($eingabe);
        } catch (UngueltigesPfadMuster $fehler) {
            return $this->formular($eingabe, $fehler->getMessage(), true, 422);
        }

        return $this->formular($eingabe, null, true);
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $eingabe = self::eingabe($request);
        try {
            $muster = PfadMuster::parse($eingabe);
        } catch (UngueltigesPfadMuster $fehler) {
            return $this->formular($eingabe, $fehler->getMessage(), true, 422);
        }

        new ExportEinstellungen($muster)->speichern($this->settings);
        // The pattern is configuration, not business data - it may go into
        // the audit details as it is.
        $this->audit->record(AuditAction::EinstellungExport, $this->session->userId(), $request->ip, details: [
            'muster' => $muster->muster,
        ]);
        $this->session->flash('Muster gespeichert.');

        return Response::redirect('/admin/export');
    }

    /**
     * Made-up receipts that show the interesting cases: month folders, a
     * collision, a receipt without supplier, a cash receipt, a value with a
     * forbidden character. Never real data.
     *
     * @return list<BelegPfadDaten>
     */
    public static function beispielBelege(): array
    {
        $datum = static fn(string $iso): \DateTimeImmutable => new \DateTimeImmutable($iso);
        $ausgabe = InvoiceDirection::Ausgabe;

        return [
            new BelegPfadDaten($datum('2026-02-03'), $ausgabe, 'Musterbau GmbH', 'Material', 'R-1001', 4999),
            new BelegPfadDaten($datum('2026-05-17'), $ausgabe, 'Musterbau GmbH', 'Material', 'R-1187', 12900),
            new BelegPfadDaten($datum('2026-05-17'), $ausgabe, 'Musterbau GmbH', 'Material', 'R-1188', 2350),
            new BelegPfadDaten($datum('2026-01-15'), $ausgabe, 'Stadtwerke Musterstadt', 'Energie', '2026/0042', 18412),
            new BelegPfadDaten($datum('2026-03-20'), $ausgabe, null, 'Verpflegung', '', 1250, kasse: true),
            new BelegPfadDaten($datum('2026-04-09'), InvoiceDirection::Einnahme, 'Beispiel-Sponsor e. V.', 'Spenden', 'S-7', 50000),
        ];
    }

    /**
     * @return list<string> the paths the made-up receipts get, in a
     *         "Belege_2026" folder like the real export
     */
    public static function vorschauPfade(PfadMuster $muster): array
    {
        $wurzel = ExportEinstellungen::wurzelordner(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $vergabe = new PfadVergabe();

        return array_map(
            static fn(BelegPfadDaten $beleg): string => $vergabe->vergeben([$wurzel, ...$muster->aufloesen($beleg)], 'pdf'),
            self::beispielBelege(),
        );
    }

    private function formular(string $muster, ?string $fehler, bool $istVorschau, int $status = 200): ResponseInterface
    {
        $gespeichert = ExportEinstellungen::fromSettings($this->settings)->muster;
        $pfade = [];
        if ($fehler === null) {
            $pfade = self::vorschauPfade(PfadMuster::parse($muster));
        }

        return Response::html($this->view->render('admin/export', [
            'title' => 'Export',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'muster' => $muster,
            'gespeichertesMuster' => $gespeichert->muster,
            'istVorschau' => $istVorschau && $muster !== $gespeichert->muster,
            'fehler' => $fehler,
            'pfade' => $pfade,
            'darfExportieren' => $this->view->berechtigungen()?->darf(Permission::ExportZip) ?? false,
        ], Area::Admin), $status);
    }

    private static function eingabe(Request $request): string
    {
        $wert = $request->post['muster'] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect('/admin/export');
    }
}
