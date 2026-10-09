<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AssignmentRule;
use App\Domain\AuditAction;
use App\Domain\Berechtigungen;
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
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Buchungen;
use App\Service\Bank\Buchungsregeln;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Rules for bookings /app/buchungen/regeln (M9-6, issue #64, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M9-6"): the list with how many
 * bookings each rule acts on, one rule with its form, applying it to the
 * existing bookings, switching it off, deleting it - and the setting
 * whether income needs a receipt by default (E-17).
 *
 * Reading needs `bank.view` - auditors see what the rules do -, everything
 * that writes `bank.book`; app/src/routes.php declares it per route. Label
 * and pattern of a rule are vault data: without an unlocked vault the pages
 * say so and show nothing.
 *
 * Nothing of a rule goes into a URL, a flash message or the audit log
 * beyond its id, its direction, its category id and counts - never the
 * label, the word or the counterparty it looks for.
 */
final readonly class BuchungsregelController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Buchungsregeln $regeln,
        private Buchungen $buchungen,
        private CategoryRepository $kategorien,
        private AuditLog $audit,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);

        return Response::html($this->view->render('app/buchungsregeln', [
            'title' => 'Buchungsregeln',
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $tresor !== null,
            'regeln' => $tresor === null ? [] : $this->regeln->liste($tresor),
            'betroffen' => $this->regeln->betroffene($this->bereich(Permission::BankView)),
            'kategorien' => self::kategorienNachId($this->kategorien->all()),
            'belegStandard' => $this->regeln->belegStandard(),
            'darfBuchen' => $this->berechtigungen()->darf(Permission::BankBook),
        ], Area::App));
    }

    /** The form for a new rule; `buchung` prefills it from that booking ("Regel daraus machen"). */
    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        $felder = Buchungsregeln::leer();
        $buchungId = self::abfrage($request, 'buchung');
        if (ctype_digit($buchungId) && (int) $buchungId > 0) {
            $buchung = $this->buchungen->finde($tresor, (int) $buchungId, $this->bereich(Permission::BankBook));
            if ($buchung !== null) {
                $felder = Buchungsregeln::vorschlagAus($buchung);
            }
        }

        return $this->formular($tresor, null, $felder, null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/regeln/neu');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }

        $felder = self::felder($request);
        try {
            $id = $this->regeln->anlegen($tresor, $felder, $this->session->userId(), new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($tresor, null, $felder, $e, 422);
        }

        $regel = $this->regeln->finde($tresor, $id) ?? throw new \LogicException('The rule just written is gone.');
        $this->audit->record(AuditAction::RegelAngelegt, $this->session->userId(), $request->ip, $id, self::details($regel));
        $this->session->flash('Regel gespeichert. Sie gilt ab jetzt für jeden Kontoauszug-Import.');

        return Response::redirect('/app/buchungen/regeln/' . $id);
    }

    /**
     * One rule: the form for whoever holds `bank.book`, the stored values
     * read-only otherwise; with "Auf N Buchungen anwenden".
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
        $regel = $this->regeln->finde($tresor, (int) $params['id']);
        if ($regel === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($tresor, $regel, Buchungsregeln::felderAus($regel), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/regeln/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $regel = $this->regeln->finde($tresor, $id);
        if ($regel === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);
        try {
            $ergebnis = $this->regeln->aendern($tresor, $regel, $felder, new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            return $this->formular($tresor, $regel, $felder, $e, 422);
        }

        if ($ergebnis['felder'] === []) {
            $this->session->flash('Nichts geändert.');
        } else {
            $this->audit->record(AuditAction::RegelGeaendert, $this->session->userId(), $request->ip, $id, $ergebnis);
            $this->session->flash(sprintf(
                'Regel gespeichert. Die bisherige Wirkung auf %s wurde zurückgenommen, die geänderte Regel wirkt wieder auf %s.',
                self::buchungen($ergebnis['zurueckgenommen']),
                self::buchungen($ergebnis['erneut']),
            ));
        }

        return Response::redirect('/app/buchungen/regeln/' . $id);
    }

    /**
     * Applies the rule to the existing bookings no rule has touched yet,
     * within the scope of whoever asks.
     *
     * @param array<string, string> $params
     */
    public function anwenden(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/regeln/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $regel = $this->regeln->finde($tresor, $id);
        if ($regel === null) {
            return $this->nichtGefunden();
        }

        try {
            $anzahl = $this->regeln->anwenden($tresor, $regel, $this->bereich(Permission::BankBook), new \DateTimeImmutable());
        } catch (BankRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/app/buchungen/regeln/' . $id);
        }

        $this->audit->record(AuditAction::RegelAngewendet, $this->session->userId(), $request->ip, $id, ['buchungen' => $anzahl]);
        $this->session->flash(sprintf('Die Regel wurde auf %s angewendet.', self::buchungen($anzahl)));

        return Response::redirect('/app/buchungen/regeln/' . $id);
    }

    /**
     * Switches a rule on (`aktiv=1`) or off; off takes its effect back.
     *
     * @param array<string, string> $params
     */
    public function aktiv(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/regeln/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $regel = $this->regeln->finde($tresor, $id);
        if ($regel === null) {
            return $this->nichtGefunden();
        }

        $aktiv = self::text($request, 'aktiv') === '1';
        if ($aktiv === $regel->active) {
            return Response::redirect('/app/buchungen/regeln/' . $id);
        }
        $zurueck = $this->regeln->aktivieren($regel, $aktiv, new \DateTimeImmutable());

        if ($aktiv) {
            $this->audit->record(AuditAction::RegelAktiviert, $this->session->userId(), $request->ip, $id);
            $this->session->flash('Regel aktiviert. Sie gilt wieder für neue Importe; bestehende Buchungen lassen sich unten einordnen.');
        } else {
            $this->audit->record(AuditAction::RegelDeaktiviert, $this->session->userId(), $request->ip, $id, ['zurueckgenommen' => $zurueck]);
            $this->session->flash(sprintf('Regel deaktiviert. Ihre Wirkung auf %s wurde zurückgenommen.', self::buchungen($zurueck)));
        }

        return Response::redirect('/app/buchungen/regeln/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/regeln/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt(true);
        }
        $regel = $this->regeln->finde($tresor, $id);
        if ($regel === null) {
            return $this->nichtGefunden();
        }

        $zurueck = $this->regeln->loeschen($regel, new \DateTimeImmutable());
        $this->audit->record(AuditAction::RegelGeloescht, $this->session->userId(), $request->ip, $id, ['zurueckgenommen' => $zurueck]);
        $this->session->flash(sprintf('Regel gelöscht. Ihre Wirkung auf %s wurde zurückgenommen.', self::buchungen($zurueck)));

        return Response::redirect('/app/buchungen/regeln');
    }

    /** Whether income needs a receipt by default (E-17); plaintext, no vault. */
    public function standard(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/app/buchungen/regeln');
        }

        $noetig = self::text($request, 'einnahme_beleg_noetig') === '1';
        if ($this->regeln->belegStandardSpeichern($noetig)) {
            $this->audit->record(AuditAction::EinstellungBelegStandard, $this->session->userId(), $request->ip, details: ['einnahme_beleg_noetig' => $noetig]);
        }
        $this->session->flash($noetig
            ? 'Gespeichert: Neue Einnahmen brauchen ab jetzt einen Beleg. Bestehende Buchungen bleiben, wie sie sind.'
            : 'Gespeichert: Neue Einnahmen brauchen ab jetzt keinen Beleg. Bestehende Buchungen bleiben, wie sie sind.');

        return Response::redirect('/app/buchungen/regeln');
    }

    /**
     * @param array<string, string> $felder
     */
    private function formular(Vault $tresor, ?AssignmentRule $regel, array $felder, ?BankRuleViolation $fehler, int $status = 200): ResponseInterface
    {
        $darfBuchen = $this->berechtigungen()->darf(Permission::BankBook);
        $kategorien = $this->kategorien->all();

        return Response::html($this->view->render('app/buchungsregel', [
            'title' => $regel === null ? 'Neue Buchungsregel' : 'Buchungsregel',
            'regel' => $regel,
            'felder' => $felder,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'flash' => $this->session->pullFlash(),
            'darfBuchen' => $darfBuchen,
            'kategorieAuswahl' => array_values(array_filter(
                $kategorien,
                static fn(Category $k): bool => $k->active || (string) $k->id === ($felder['kategorie'] ?? ''),
            )),
            'kategorien' => self::kategorienNachId($kategorien),
            'betroffen' => $regel === null ? 0 : ($this->regeln->betroffene($this->bereich(Permission::BankView))[$regel->id] ?? 0),
            'kandidaten' => $regel === null || !$darfBuchen ? 0 : $this->regeln->kandidaten($tresor, $regel, $this->bereich(Permission::BankBook)),
        ], Area::App), $status);
    }

    /**
     * What the audit log keeps of a rule: structure only.
     *
     * @return array<string, mixed>
     */
    private static function details(AssignmentRule $regel): array
    {
        return ['richtung' => $regel->direction?->value, 'kein_beleg' => $regel->noReceipt, 'kategorie' => $regel->categoryId];
    }

    private static function buchungen(int $anzahl): string
    {
        return $anzahl === 1 ? '1 Buchung' : $anzahl . ' Buchungen';
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
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach (array_keys(Buchungsregeln::leer()) as $feld) {
            $felder[$feld] = self::text($request, $feld);
        }

        return $felder;
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

        return Response::redirect('/app/buchungen/regeln');
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Regel nicht gefunden',
            'message' => 'Diese Buchungsregel gibt es nicht (mehr).',
            'startseite' => '/app/buchungen/regeln',
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
