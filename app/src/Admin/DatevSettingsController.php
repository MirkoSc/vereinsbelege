<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Domain\BankAccountRecord;
use App\Domain\Category;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\BankAccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\SettingRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Crypto\CryptoException;
use App\Service\Export\DatevEinstellungen;
use App\Service\Export\UngueltigeDatevEinstellung;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page /admin/datev (issue #93, E-49,
 * docs/spec/05-auswertung-und-export.md section 4): advisor and client
 * number, length of a ledger account, chart of accounts, clearing account,
 * and the ledger account of every category and every bank account or cash
 * box.
 *
 * Permission: `admin.settings`, like the other export settings. Plaintext
 * setting; the page needs no vault - with it, the accounts show their
 * names, without it their kind and number.
 */
final readonly class DatevSettingsController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private SettingRepository $settings,
        private CategoryRepository $kategorien,
        private BankAccountRepository $kontoRecords,
        private BankAccountService $konten,
        private AuditLog $audit,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular($request, DatevEinstellungen::fromSettings($this->settings), [], null);
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect('/admin/datev');
        }

        $kategorieIds = array_map(static fn(Category $k): int => $k->id, $this->kategorien->all());
        $kontoIds = array_map(static fn(BankAccountRecord $k): int => $k->id, $this->kontoRecords->all());
        try {
            $neu = DatevEinstellungen::ausFormular($request->post, $kategorieIds, $kontoIds);
        } catch (UngueltigeDatevEinstellung $fehler) {
            return $this->formular($request, DatevEinstellungen::fromSettings($this->settings), $request->post, $fehler, 422);
        }

        $neu->speichern($this->settings);
        // Configuration, not business data - still only what changed in
        // shape, no numbers.
        $this->audit->record(AuditAction::EinstellungDatev, $this->session->userId(), $request->ip, details: [
            'vollstaendig' => $neu->istVollstaendig(),
            'sachkontenlaenge' => $neu->sachkontenlaenge,
            'kontenrahmen' => $neu->kontenrahmen,
            'kategorien' => count($neu->kategorien),
            'konten' => count($neu->konten),
        ]);
        $this->session->flash('DATEV-Einstellungen gespeichert.');

        return Response::redirect('/admin/datev');
    }

    /**
     * @param array<string, mixed> $eingabe the posted form after an error - shown again as typed
     */
    private function formular(Request $request, DatevEinstellungen $einstellungen, array $eingabe, ?UngueltigeDatevEinstellung $fehler, int $status = 200): ResponseInterface
    {
        $namen = [];
        try {
            $tresor = $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
            foreach ($tresor === null ? [] : $this->konten->lesbare($tresor)['konten'] as $konto) {
                $namen[$konto->id] = $konto->data->name;
            }
        } catch (CryptoException) {
            // Locked: kind and number only.
        }
        $konten = [];
        foreach ($this->kontoRecords->all() as $record) {
            $konten[] = [
                'id' => $record->id,
                'name' => $namen[$record->id] ?? sprintf('%s Nr. %d', $record->kind->label(), $record->id),
                'art' => $record->kind->label(),
                'aktiv' => $record->active,
            ];
        }

        return Response::html($this->view->render('admin/datev', [
            'title' => 'DATEV',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'einstellungen' => $einstellungen,
            'eingabe' => $eingabe,
            'fehler' => $fehler,
            'kategorien' => $this->kategorien->all(),
            'konten' => $konten,
        ], Area::Admin), $status);
    }
}
