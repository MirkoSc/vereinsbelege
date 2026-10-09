<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\BankTransaction;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionSetBy;
use App\Domain\Berechtigungen;
use App\Domain\CashCount;
use App\Domain\Category;
use App\Domain\Permission;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CategoryRepository;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\BuchungFilter;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Buchungsregeln;
use App\Service\Bank\Kassensturz;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Processing\Betrag;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Bookings /app/buchungen (M9-5, issue #63, docs/spec/
 * 04-bank-und-abgleich.md section 1, E-17): the list with its filters, one
 * booking, and the bookings entered by hand on an account or a cash box -
 * above all income without a receipt, and the suggested "Kassendifferenz"
 * after a cash count.
 *
 * Since M9-6 (issue #64) category and receipt status of an imported
 * booking can be set by hand ("Einordnung"), and every booking says where
 * they come from - default, rule (with a link to it) or by hand.
 *
 * Reading needs `bank.view`, entering, changing and deleting `bank.book` -
 * app/src/routes.php declares it per route. The period scope of external
 * roles applies to the booking date (in SQL for the list, per row for one
 * booking). Amounts, purposes and counterparties are vault data: without
 * an unlocked vault the pages say so and show nothing.
 *
 * Nothing of a booking goes into a URL, a flash message or the audit log
 * beyond its id, account id, direction and receipt status - never an
 * amount, a purpose or a name.
 */
final readonly class BuchungController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Buchungen $buchungen,
        private BankAccountService $konten,
        private CategoryRepository $kategorien,
        private Kassensturz $kassensturz,
        private AuditLog $audit,
        private Buchungsregeln $regeln,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $heute = new \DateTimeImmutable();
        $filter = BuchungFilter::ausAbfrage($request->query, $heute);
        $konten = $tresor === null ? [] : $this->konten->liste($tresor);
        $buchungen = $tresor === null ? [] : $this->buchungen->liste($tresor, $filter, $this->bereich(Permission::BankView));

        $einnahmen = 0;
        $ausgaben = 0;
        foreach ($buchungen as $buchung) {
            if ($buchung->amount >= 0) {
                $einnahmen += $buchung->amount;
            } else {
                $ausgaben += $buchung->amount;
            }
        }

        return Response::html($this->view->render('app/buchungen', [
            'title' => 'Buchungen',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'filter' => $filter,
            'filterAktiv' => $filter->aktiv($heute),
            'konten' => self::nachId($konten),
            'kategorien' => self::kategorienNachId($this->kategorien->all()),
            'buchungen' => $buchungen,
            'einnahmen' => $einnahmen,
            'ausgaben' => $ausgaben,
            'darfBuchen' => $this->berechtigungen()->darf(Permission::BankBook),
            'belegStandard' => $this->buchungen->belegStandard(),
        ], Area::App));
    }

    /**
     * The form for a new manual booking. `konto` and `richtung` preselect;
     * `kassensturz` fills in the difference of that cash count as a
     * suggestion.
     */
    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        $felder = self::leer();
        $vorschlag = null;
        $zaehlungId = self::zahl(self::abfrage($request, 'kassensturz'));
        if ($zaehlungId !== null) {
            $vorschlag = $this->kassendifferenz($tresor, $zaehlungId);
            if ($vorschlag instanceof ResponseInterface) {
                return $vorschlag;
            }
            $felder = $vorschlag['felder'];
        } else {
            $felder['konto'] = (string) (self::zahl(self::abfrage($request, 'konto')) ?? '');
            $felder['richtung'] = BankTransactionDirection::tryFrom(self::abfrage($request, 'richtung'))?->value ?? '';
        }

        return $this->formular($tresor, null, $felder, null, kassensturz: $vorschlag === null ? null : $vorschlag['zaehlung']);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/neu');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }

        $felder = self::felder($request);
        $zaehlungId = self::zahl(self::text($request, 'kassensturz'));
        $kassensturz = $zaehlungId === null ? null : $this->kassensturzFuer($tresor, $felder, $zaehlungId);
        try {
            $id = $this->buchungen->anlegen($tresor, $felder, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($tresor, null, $felder, $e, 422, $kassensturz);
        }

        $buchung = $this->buchungen->finde($tresor, $id, $this->bereich(Permission::BankBook)) ?? throw new \LogicException('The booking just written is gone.');
        $this->audit->record(AuditAction::BuchungAngelegt, $this->session->userId(), $request->ip, $id, [
            'konto' => $buchung->accountId,
            'richtung' => $buchung->direction->value,
            'beleg' => $buchung->docStatus->value,
        ]);
        $this->session->flash('Buchung gespeichert.');

        // A suggested difference goes back to where it came from: the cash
        // box and its counts.
        return Response::redirect($kassensturz === null ? '/app/buchungen/' . $id : '/app/konten/' . $buchung->accountId);
    }

    /**
     * One booking: the form for a manual one and whoever holds `bank.book`,
     * the stored values read-only otherwise.
     *
     * @param array<string, string> $params
     */
    public function ansicht(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $buchung = $this->buchungen->finde($tresor, (int) $params['id'], $this->bereich(Permission::BankView));
        if ($buchung === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($tresor, $buchung, self::felderAus($buchung), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $buchung = $this->buchungen->finde($tresor, $id, $this->bereich(Permission::BankBook));
        if ($buchung === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);
        try {
            $geaendert = $this->buchungen->aendern($tresor, $buchung, $felder, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($tresor, $buchung, $felder, $e, 422);
        }

        if ($geaendert !== []) {
            $this->audit->record(AuditAction::BuchungGeaendert, $this->session->userId(), $request->ip, $id, ['felder' => $geaendert]);
        }
        $this->session->flash('Buchung gespeichert.');

        return Response::redirect('/app/buchungen/' . $id);
    }

    /**
     * Category and receipt status of an imported booking, set by hand
     * (M9-6). A manual booking is changed through its form.
     *
     * @param array<string, string> $params
     */
    public function einordnen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $buchung = $this->buchungen->finde($tresor, $id, $this->bereich(Permission::BankBook));
        if ($buchung === null) {
            return $this->nichtGefunden();
        }

        $felder = ['kategorie' => self::text($request, 'kategorie'), 'beleg' => self::text($request, 'beleg')];
        try {
            $geaendert = $this->buchungen->einordnen($buchung, $felder, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($tresor, $buchung, self::felderAus($buchung), $e, 422, einordnung: $felder);
        }

        if ($geaendert !== []) {
            $this->audit->record(AuditAction::BuchungEingeordnet, $this->session->userId(), $request->ip, $id, ['felder' => $geaendert]);
        }
        $this->session->flash($geaendert === [] ? 'Nichts geändert.' : 'Einordnung gespeichert. Regeln ändern sie nicht mehr.');

        return Response::redirect('/app/buchungen/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $buchung = $this->buchungen->finde($tresor, $id, $this->bereich(Permission::BankBook));
        if ($buchung === null) {
            return $this->nichtGefunden();
        }

        try {
            $this->buchungen->loeschen($buchung);
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/app/buchungen/' . $id);
        }

        $this->audit->record(AuditAction::BuchungGeloescht, $this->session->userId(), $request->ip, $id, ['konto' => $buchung->accountId]);
        $this->session->flash('Buchung gelöscht.');

        return Response::redirect('/app/buchungen?konto=' . $buchung->accountId);
    }

    /**
     * The booking suggested for the difference of a cash count: on the day
     * of the count, the open difference as amount, shortfall an expense and
     * surplus income, purpose "Kassendifferenz", no receipt - the count is
     * the evidence. A redirect when there is nothing to book.
     *
     * @return array{felder: array<string, string>, zaehlung: CashCount}|ResponseInterface
     */
    private function kassendifferenz(Vault $tresor, int $zaehlungId): array|ResponseInterface
    {
        $gefunden = $this->zaehlung($tresor, $zaehlungId);
        if ($gefunden === null) {
            return $this->nichtGefunden();
        }
        [$kasse, $zaehlung] = $gefunden;
        $differenz = $this->kassensturz->offeneDifferenz($tresor, $kasse, $zaehlung);
        if ($differenz === 0) {
            $this->session->flash('Die Kasse stimmt mit diesem Kassensturz überein – es gibt keine Differenz zu buchen.');

            return Response::redirect('/app/konten/' . $kasse->id);
        }

        $richtung = $differenz < 0 ? BankTransactionDirection::Ausgabe : BankTransactionDirection::Einnahme;
        $kategorie = $this->buchungen->kategorieMitNamen($richtung === BankTransactionDirection::Ausgabe ? 'Sonstiges' : 'Sonstige Einnahmen', $richtung);

        return [
            'felder' => [
                ...self::leer(),
                'konto' => (string) $kasse->id,
                'datum' => $zaehlung->countedOn->format('Y-m-d'),
                'betrag' => Betrag::format(abs($differenz)),
                'richtung' => $richtung->value,
                'kategorie' => $kategorie === null ? '' : (string) $kategorie,
                'zweck' => Buchungen::KASSENDIFFERENZ,
                'beleg' => Buchungen::BELEG_NICHT_NOETIG,
            ],
            'zaehlung' => $zaehlung,
        ];
    }

    /**
     * The cash count a refused suggestion came from, so the page keeps
     * saying so - only while the form still names its cash box.
     *
     * @param array<string, string> $felder
     */
    private function kassensturzFuer(Vault $tresor, array $felder, int $zaehlungId): ?CashCount
    {
        $gefunden = $this->zaehlung($tresor, $zaehlungId);

        return $gefunden !== null && (string) $gefunden[0]->id === $felder['konto'] ? $gefunden[1] : null;
    }

    /**
     * A cash count with its cash box, if the reader may see it.
     *
     * @return array{BankAccount, CashCount}|null
     */
    private function zaehlung(Vault $tresor, int $zaehlungId): ?array
    {
        $kontoId = $this->kassensturz->kasseVon($zaehlungId);
        $kasse = $kontoId === null ? null : $this->konten->finde($tresor, $kontoId);
        $zaehlung = $kasse?->kind === BankAccountKind::Kasse ? $this->kassensturz->finde($tresor, $kasse, $zaehlungId) : null;
        if ($kasse === null || $zaehlung === null || !$this->bereich(Permission::BankBook)->erlaubt(null, $zaehlung->countedOn)) {
            return null;
        }

        return [$kasse, $zaehlung];
    }

    /**
     * @param array<string, string>      $felder
     * @param array<string, string>|null $einordnung kategorie, beleg of the "Einordnung" form of an imported booking
     */
    private function formular(
        Vault $tresor,
        ?BankTransaction $buchung,
        array $felder,
        ?BankRuleViolation $fehler,
        int $status = 200,
        ?CashCount $kassensturz = null,
        ?array $einordnung = null,
    ): ResponseInterface {
        $darfBuchen = $this->berechtigungen()->darf(Permission::BankBook);
        $konten = $this->konten->liste($tresor);
        $kategorien = $this->kategorien->all();
        $einordnung ??= $buchung === null ? [] : [
            'kategorie' => $buchung->categoryId === null ? '' : (string) $buchung->categoryId,
            'beleg' => match ($buchung->docSource) {
                BankTransactionSetBy::Standard => '',
                default => $buchung->docRequired ? Buchungen::BELEG_NOETIG : Buchungen::BELEG_NICHT_NOETIG,
            },
        ];

        return Response::html($this->view->render('app/buchung', [
            'title' => $buchung === null ? 'Neue Buchung' : 'Buchung',
            'buchung' => $buchung,
            'felder' => $felder,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'flash' => $this->session->pullFlash(),
            'bearbeitbar' => $darfBuchen && ($buchung === null || $buchung->istManuell()),
            'darfBuchen' => $darfBuchen,
            // New bookings go to active accounts only; a booking keeps the
            // account it has even once that is deactivated.
            'kontoAuswahl' => array_values(array_filter(
                $konten,
                static fn(BankAccount $k): bool => $k->active || (string) $k->id === ($felder['konto'] ?? ''),
            )),
            'konten' => self::nachId($konten),
            'kategorieAuswahl' => array_values(array_filter(
                $kategorien,
                static fn(Category $k): bool => $k->active || (string) $k->id === ($felder['kategorie'] ?? ''),
            )),
            'kategorien' => self::kategorienNachId($kategorien),
            'einordnung' => $einordnung,
            'regel' => $buchung?->ruleId === null ? null : $this->regeln->finde($tresor, $buchung->ruleId),
            'belegStandard' => $this->buchungen->belegStandard(),
            'kassensturz' => $kassensturz,
            'heute' => new \DateTimeImmutable(),
        ], Area::App), $status);
    }

    /**
     * @param list<BankAccount> $konten
     *
     * @return array<int, BankAccount>
     */
    private static function nachId(array $konten): array
    {
        $nachId = [];
        foreach ($konten as $konto) {
            $nachId[$konto->id] = $konto;
        }

        return $nachId;
    }

    /**
     * @param list<Category> $kategorien
     *
     * @return array<int, Category>
     */
    private static function kategorienNachId(array $kategorien): array
    {
        $nachId = [];
        foreach ($kategorien as $kategorie) {
            $nachId[$kategorie->id] = $kategorie;
        }

        return $nachId;
    }

    private function bereich(Permission $recht): Zugriffsbereich
    {
        return $this->berechtigungen()->zugriffsbereich($recht);
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    /**
     * @return array<string, string>
     */
    private static function leer(): array
    {
        return ['konto' => '', 'datum' => new \DateTimeImmutable()->format('Y-m-d'), 'betrag' => '', 'richtung' => '',
            'kategorie' => '', 'zweck' => '', 'gegenseite' => '', 'beleg' => ''];
    }

    /**
     * @return array<string, string>
     */
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach (array_keys(self::leer()) as $feld) {
            $felder[$feld] = self::text($request, $feld);
        }

        return $felder;
    }

    /**
     * @return array<string, string>
     */
    private static function felderAus(BankTransaction $buchung): array
    {
        return [
            'konto' => (string) $buchung->accountId,
            'datum' => $buchung->bookingDate->format('Y-m-d'),
            'betrag' => Betrag::format(abs($buchung->amount)),
            'richtung' => $buchung->direction->value,
            'kategorie' => $buchung->categoryId === null ? '' : (string) $buchung->categoryId,
            'zweck' => $buchung->purpose,
            'gegenseite' => $buchung->counterpartyName,
            'beleg' => $buchung->docRequired ? Buchungen::BELEG_NOETIG : Buchungen::BELEG_NICHT_NOETIG,
        ];
    }

    private static function zahl(string $wert): ?int
    {
        return ctype_digit($wert) && (int) $wert > 0 ? (int) $wert : null;
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
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

        return Response::redirect('/app/buchungen');
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Buchung nicht gefunden',
            'message' => 'Diese Buchung gibt es nicht (mehr).',
            'startseite' => '/app/buchungen',
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
