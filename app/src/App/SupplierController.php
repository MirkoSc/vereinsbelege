<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\Iban;
use App\Domain\Supplier;
use App\Domain\SupplierRole;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CategoryRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\MasterData\SupplierRuleViolation;
use App\Service\MasterData\SupplierSaved;
use App\Service\MasterData\SupplierService;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Suppliers and payers /app/lieferanten (M6-2, issue #36, docs/spec/
 * 03-erfassung-und-ki.md section 7): the list with search and role filter,
 * a new supplier, changing and deleting one.
 *
 * Behind `supplier.manage` (Admin, Finanzen - app/src/routes.php). Names,
 * IBANs and everything else of a supplier are vault data, and even writing
 * needs the vault (the blind indexes are keyed from it): without an
 * unlocked vault the pages say so and show nothing.
 *
 * Nothing of a supplier goes into a URL, a flash message or the audit log -
 * the log keeps the names of the changed fields, never their values
 * (docs/spec/01-sicherheit.md section 6: a payer may well be a person).
 */
final readonly class SupplierController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private SupplierService $service,
        private CategoryRepository $kategorien,
        private AuditLog $audit,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        $rolle = SupplierRole::tryFrom(self::abfrage($request, 'rolle'));
        $suche = trim(self::abfrage($request, 'q'));
        $tresor = $this->tresor($request);

        return Response::html($this->view->render('app/lieferanten', [
            'title' => 'Lieferanten & Zahler',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'lieferanten' => $tresor === null ? [] : $this->service->liste($tresor, $rolle, $suche),
            'rolle' => $rolle,
            'suche' => $suche,
            'kategorien' => $this->kategorieNamen(),
        ], Area::App));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        if ($this->tresor($request) === null) {
            return $this->gesperrt();
        }

        return $this->formular(null, self::leer(), null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/lieferanten/neu');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }

        $felder = self::felder($request);
        try {
            $ergebnis = $this->service->anlegen($tresor, $felder, new \DateTimeImmutable());
        } catch (SupplierRuleViolation $e) {
            return $this->formular(null, $felder, $e, 422);
        }

        $this->protokolliere(AuditAction::LieferantAngelegt, $request, $ergebnis);
        $this->meldeGespeichert($ergebnis, 'Lieferant angelegt.');

        return Response::redirect('/app/lieferanten/' . $ergebnis->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $lieferant = $this->service->finde($tresor, (int) $params['id']);
        if ($lieferant === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($lieferant, self::felderAus($lieferant), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/lieferanten/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $lieferant = $this->service->finde($tresor, $id);
        if ($lieferant === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);
        try {
            $ergebnis = $this->service->aendern($tresor, $id, $felder, new \DateTimeImmutable());
        } catch (SupplierRuleViolation $e) {
            return $this->formular($lieferant, $felder, $e, 422);
        }

        if ($ergebnis->geaenderteFelder !== []) {
            $this->protokolliere(AuditAction::LieferantGeaendert, $request, $ergebnis);
        }
        $this->meldeGespeichert($ergebnis, 'Lieferant gespeichert.');

        return Response::redirect('/app/lieferanten/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/lieferanten/' . $id);
        }
        // Deleting touches no ciphertext, but a page that cannot even show
        // which supplier it is about should not offer to delete it either.
        if ($this->tresor($request) === null) {
            return $this->gesperrt(true);
        }

        try {
            $geloescht = $this->service->loeschen($id);
        } catch (SupplierRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/app/lieferanten/' . $id);
        }

        $this->audit->record(AuditAction::LieferantGeloescht, $this->session->userId(), $request->ip, $id, ['rolle' => $geloescht->role->value]);
        $this->session->flash('Lieferant gelöscht.');

        return Response::redirect('/app/lieferanten');
    }

    /**
     * @param array<string, string> $felder
     */
    private function formular(?Supplier $lieferant, array $felder, ?SupplierRuleViolation $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('app/lieferant', [
            'title' => $lieferant === null ? 'Neuer Lieferant' : 'Lieferant bearbeiten',
            'lieferant' => $lieferant,
            'felder' => $felder,
            'fehler' => $fehler?->getMessage(),
            'konfliktId' => $fehler?->konfliktId,
            'flash' => $this->session->pullFlash(),
            'kategorien' => $this->kategorieAuswahl($felder['kategorie'] ?? ''),
            'verwendungen' => $lieferant === null ? 0 : $this->service->verwendungen($lieferant->id),
        ], Area::App), $status);
    }

    /**
     * Every active category grouped by direction, plus the stored one if it
     * has been deactivated since - the select must be able to show what is
     * there. The service checks whether it fits the role.
     *
     * @return array<string, array<int, string>> group label => id => name
     */
    private function kategorieAuswahl(string $gewaehlt): array
    {
        $gruppen = [];
        foreach ($this->kategorien->all() as $kategorie) {
            if ($kategorie->active || (string) $kategorie->id === $gewaehlt) {
                $gruppen[$kategorie->direction->gruppe()][$kategorie->id] = $kategorie->name . ($kategorie->active ? '' : ' (inaktiv)');
            }
        }

        return $gruppen;
    }

    /**
     * @return array<int, string> id => name, every category
     */
    private function kategorieNamen(): array
    {
        $namen = [];
        foreach ($this->kategorien->all() as $kategorie) {
            $namen[$kategorie->id] = $kategorie->name;
        }

        return $namen;
    }

    private function protokolliere(AuditAction $aktion, Request $request, SupplierSaved $ergebnis): void
    {
        $this->audit->record($aktion, $this->session->userId(), $request->ip, $ergebnis->id, ['felder' => $ergebnis->geaenderteFelder]);
    }

    private function meldeGespeichert(SupplierSaved $ergebnis, string $text): void
    {
        if ($ergebnis->namensgleich) {
            $this->session->flash(
                $text . ' Ein anderer Lieferant hat denselben Namen oder Alias – falls es derselbe ist, bitte zusammenführen, statt beide zu pflegen.',
                FlashArt::Warnung,
            );

            return;
        }
        $this->session->flash($text);
    }

    /**
     * @return array<string, string>
     */
    private static function leer(): array
    {
        return ['name' => '', 'rolle' => SupplierRole::Lieferant->value, 'kategorie' => '', 'aliases' => '', 'ibans' => '',
            'address' => '', 'bic' => '', 'vat_id' => '', 'tax_number' => '', 'creditor_id' => '', 'mandate_refs' => '',
            'email' => '', 'website' => '', 'customer_number' => '', 'notes' => ''];
    }

    /**
     * @return array<string, string>
     */
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach (array_keys(self::leer()) as $feld) {
            $wert = $request->post[$feld] ?? '';
            $felder[$feld] = is_string($wert) ? $wert : '';
        }

        return $felder;
    }

    /**
     * @return array<string, string>
     */
    private static function felderAus(Supplier $lieferant): array
    {
        $d = $lieferant->data;

        return [
            'name' => $d->name,
            'rolle' => $lieferant->role->value,
            'kategorie' => $lieferant->defaultCategoryId === null ? '' : (string) $lieferant->defaultCategoryId,
            'aliases' => implode("\n", $d->aliases),
            'ibans' => implode("\n", array_map(Iban::formatieren(...), $d->ibans)),
            'address' => $d->address,
            'bic' => $d->bic,
            'vat_id' => $d->vatId,
            'tax_number' => $d->taxNumber,
            'creditor_id' => $d->creditorId,
            'mandate_refs' => implode("\n", $d->mandateRefs),
            'email' => $d->email,
            'website' => $d->website,
            'customer_number' => $d->customerNumber,
            'notes' => $d->notes,
        ];
    }

    private static function abfrage(Request $request, string $feld): string
    {
        $wert = $request->query[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    /**
     * The list says by itself that the vault is locked; a flash is only
     * needed when a form was sent and its content is lost.
     */
    private function gesperrt(bool $geschrieben = false): ResponseInterface
    {
        if ($geschrieben) {
            $this->session->flash('Die Änderung wurde nicht übernommen. ' . self::TRESOR_GESPERRT, FlashArt::Fehler);
        }

        return Response::redirect('/app/lieferanten');
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Lieferant nicht gefunden',
            'message' => 'Diesen Lieferanten gibt es nicht (mehr).',
            'startseite' => '/app/lieferanten',
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
