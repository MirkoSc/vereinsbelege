<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\Berechtigungen;
use App\Domain\Iban;
use App\Domain\Permission;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountSaved;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Kassensturz;
use App\Service\Bank\KassensturzVorschau;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Processing\Betrag;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Bank accounts and cash boxes /app/konten (M9-1, issue #59,
 * docs/spec/04-bank-und-abgleich.md section 1): the list, one account,
 * creating, changing, deactivating and deleting one, and the cash count
 * ("Kassensturz") of a cash box.
 *
 * Reading needs `bank.view` (Admin, Vorstand, Finanzen, Kassenprüfer,
 * Steuerberater), everything that writes `bank.book` (Admin, Finanzen) -
 * app/src/routes.php declares it per route, and the account page only
 * offers the form to whoever holds `bank.book`. Names, IBANs and amounts
 * are vault data: without an unlocked vault the pages say so and show
 * nothing.
 *
 * Nothing of an account goes into a URL, a flash message or the audit log -
 * the log keeps the names of the changed fields, never their values, and
 * never an amount.
 */
final readonly class AccountController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private BankAccountService $service,
        private Kassensturz $kassensturz,
        private AuditLog $audit,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);

        $gruppen = [];
        foreach (BankAccountKind::cases() as $art) {
            $gruppen[$art->value] = [];
        }
        foreach ($tresor === null ? [] : $this->service->liste($tresor) as $konto) {
            $gruppen[$konto->kind->value][] = $konto;
        }

        return Response::html($this->view->render('app/konten', [
            'title' => 'Konten',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'gruppen' => $gruppen,
            'darfPflegen' => $this->berechtigungen()->darf(Permission::BankBook),
        ], Area::App));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        if ($this->tresor($request) === null) {
            return $this->gesperrt();
        }
        $art = BankAccountKind::tryFrom(self::abfrage($request, 'art')) ?? BankAccountKind::Bank;

        return $this->formular($art, null, self::leer(), null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        $art = BankAccountKind::tryFrom(self::text($request, 'art')) ?? BankAccountKind::Bank;
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/konten/neu?art=' . $art->value);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }

        $felder = self::felder($request);
        try {
            $ergebnis = $this->service->anlegen($tresor, $art, $felder, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($art, null, $felder, $e, 422);
        }

        $this->audit->record(
            AuditAction::KontoAngelegt,
            $this->session->userId(),
            $request->ip,
            $ergebnis->id,
            ['art' => $art->value, 'felder' => $ergebnis->geaenderteFelder],
        );
        $this->session->flash($art === BankAccountKind::Kasse ? 'Kasse angelegt.' : 'Konto angelegt.');

        return Response::redirect('/app/konten/' . $ergebnis->id);
    }

    /**
     * The account: the form for whoever may maintain accounts, the stored
     * values read-only for everyone else; for a cash box also its counts.
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
        $konto = $this->service->finde($tresor, (int) $params['id']);
        if ($konto === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($konto->kind, $konto, self::felderAus($konto), null, tresor: $tresor);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/konten/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $konto = $this->service->finde($tresor, $id);
        if ($konto === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);
        try {
            $ergebnis = $this->service->aendern($tresor, $id, $felder, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($konto->kind, $konto, $felder, $e, 422, $tresor);
        }

        if ($ergebnis->geaenderteFelder !== []) {
            $this->protokolliere(AuditAction::KontoGeaendert, $request, $ergebnis);
        }
        $this->session->flash($konto->kind === BankAccountKind::Kasse ? 'Kasse gespeichert.' : 'Konto gespeichert.');

        return Response::redirect('/app/konten/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/konten/' . $id);
        }
        // Deleting touches no ciphertext, but a page that cannot even show
        // which account it is about should not offer to delete it either.
        if ($this->tresor($request) === null) {
            return $this->gesperrt(true);
        }

        try {
            $geloescht = $this->service->loeschen($id);
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/app/konten/' . $id);
        }

        $this->audit->record(AuditAction::KontoGeloescht, $this->session->userId(), $request->ip, $id, ['art' => $geloescht->kind->value]);
        $this->session->flash($geloescht->kind === BankAccountKind::Kasse ? 'Kasse gelöscht.' : 'Konto gelöscht.');

        return Response::redirect('/app/konten');
    }

    /**
     * The cash count form, with today's expected amount.
     *
     * @param array<string, string> $params
     */
    public function kassensturz(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }
        $kasse = $this->kasse($tresor, (int) $params['id']);
        if ($kasse === null) {
            return $this->nichtGefunden();
        }
        if (!$kasse->active) {
            $this->session->flash('Die Kasse ist deaktiviert – ein Kassensturz ist nur für eine aktive Kasse möglich.', FlashArt::Fehler);

            return Response::redirect('/app/konten/' . $kasse->id);
        }

        $heute = new \DateTimeImmutable();

        return $this->kassensturzSeite($kasse, ['datum' => $heute->format('Y-m-d'), 'ist' => '', 'notiz' => ''], null, null);
    }

    /**
     * "Differenz berechnen" checks and shows the difference without
     * storing anything; "Kassensturz speichern" records the count.
     *
     * @param array<string, string> $params
     */
    public function kassensturzErfassen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/konten/' . $id . '/kassensturz');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $kasse = $this->kasse($tresor, $id);
        if ($kasse === null) {
            return $this->nichtGefunden();
        }

        $felder = [];
        foreach (['datum', 'ist', 'notiz'] as $feld) {
            $felder[$feld] = self::text($request, $feld);
        }
        $jetzt = new \DateTimeImmutable();
        try {
            if (self::text($request, 'aktion') !== 'speichern') {
                return $this->kassensturzSeite($kasse, $felder, $this->kassensturz->pruefen($kasse, $felder, $jetzt), null);
            }
            $zaehlung = $this->kassensturz->erfassen($tresor, $kasse, $felder, $this->session->userId(), $jetzt);
        } catch (BankRuleViolation $e) {
            return $this->kassensturzSeite($kasse, $felder, null, $e, 422);
        }

        $this->audit->record(AuditAction::KassensturzErfasst, $this->session->userId(), $request->ip, $zaehlung);
        $this->session->flash('Kassensturz gespeichert.');

        return Response::redirect('/app/konten/' . $kasse->id);
    }

    /**
     * @param array<string, string> $felder
     */
    private function formular(
        BankAccountKind $art,
        ?BankAccount $konto,
        array $felder,
        ?BankRuleViolation $fehler,
        int $status = 200,
        ?Vault $tresor = null,
    ): ResponseInterface {
        $darfPflegen = $this->berechtigungen()->darf(Permission::BankBook);
        $kassenstuerze = $konto !== null && $tresor !== null && $konto->kind === BankAccountKind::Kasse
            ? $this->kassensturz->liste($tresor, $konto, $this->berechtigungen()->zugriffsbereich(Permission::BankView))
            : [];

        return Response::html($this->view->render('app/konto', [
            'title' => $konto === null ? ($art === BankAccountKind::Kasse ? 'Neue Kasse' : 'Neues Konto') : $art->label(),
            'art' => $art,
            'konto' => $konto,
            'felder' => $felder,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'konfliktId' => $fehler?->konfliktId,
            'flash' => $this->session->pullFlash(),
            'darfPflegen' => $darfPflegen,
            'verwendungen' => $konto === null ? 0 : $this->service->verwendungen($konto->id),
            'kassenstuerze' => $kassenstuerze,
        ], Area::App), $status);
    }

    /**
     * @param array<string, string> $felder
     */
    private function kassensturzSeite(
        BankAccount $kasse,
        array $felder,
        ?KassensturzVorschau $vorschau,
        ?BankRuleViolation $fehler,
        int $status = 200,
    ): ResponseInterface {
        $heute = new \DateTimeImmutable();

        return Response::html($this->view->render('app/kassensturz', [
            'title' => 'Kassensturz',
            'kasse' => $kasse,
            'felder' => $felder,
            'sollHeute' => $this->kassensturz->sollBestand($kasse, $heute),
            'heute' => $heute,
            'vorschau' => $vorschau,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'flash' => $this->session->pullFlash(),
        ], Area::App), $status);
    }

    /** The account if it is a cash box - a bank account has no cash count. */
    private function kasse(Vault $tresor, int $id): ?BankAccount
    {
        $konto = $this->service->finde($tresor, $id);

        return $konto?->kind === BankAccountKind::Kasse ? $konto : null;
    }

    private function protokolliere(AuditAction $aktion, Request $request, BankAccountSaved $ergebnis): void
    {
        $this->audit->record($aktion, $this->session->userId(), $request->ip, $ergebnis->id, ['felder' => $ergebnis->geaenderteFelder]);
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
        return ['name' => '', 'iban' => '', 'bic' => '', 'bank' => '', 'opening_balance' => '0,00',
            'opening_date' => new \DateTimeImmutable()->format('Y-m-d'), 'active' => '1'];
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
    private static function felderAus(BankAccount $konto): array
    {
        return [
            'name' => $konto->data->name,
            'iban' => $konto->data->iban === '' ? '' : Iban::formatieren($konto->data->iban),
            'bic' => $konto->data->bic,
            'bank' => $konto->data->bank,
            'opening_balance' => Betrag::format($konto->openingBalance),
            'opening_date' => $konto->openingDate->format('Y-m-d'),
            'active' => $konto->active ? '1' : '',
        ];
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

        return Response::redirect('/app/konten');
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Konto nicht gefunden',
            'message' => 'Dieses Konto gibt es nicht (mehr).',
            'startseite' => '/app/konten',
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
