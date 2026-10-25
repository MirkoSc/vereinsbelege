<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Report\Berichtstabelle;
use App\Service\Report\BudgetFehler;
use App\Service\Report\Budgetergebnis;
use App\Service\Report\Budgets;
use App\Service\Report\Dashboard;
use App\Service\Report\Grundlage;
use App\Service\Report\ZeitraumAuswahl;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Budgets per category and cost center with Soll/Ist (issue #92,
 * docs/spec/05-auswertung-und-export.md section 1 "Stand #92", E-58).
 *
 * - The page and its CSV read with `report.view` and its scope: a
 *   Vereinsverantwortlicher sees only the budgets of his cost centers, an
 *   external account the Ist of its released period only.
 * - Saving and deleting need `bank.book` (route declaration) - the right
 *   of who keeps the club's finances (Finanzen, Admin); it has no scope.
 *
 * The query string carries only the year and the basis; amounts are vault
 * data and need an unlocked vault. Never cached by the browser.
 */
final readonly class BudgetController
{
    private const string PFAD = '/app/auswertung/budget';

    /**
     * @param \Closure(): Budgets $budgets built only when needed (it opens
     *        the database)
     * @param \Closure(): AuditLog $audit
     */
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private \Closure $budgets,
        private \Closure $audit,
    ) {
    }

    public function seite(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->anzeigen($request, [], null, 200);
    }

    public function csv(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->berechtigungen()->darf(Permission::ReportView)) {
            return $this->fehler(403, 'Kein Zugriff', 'Für die Budgets braucht Ihr Konto das Recht „Auswertungen sehen“.');
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->fehler(403, 'Tresor gesperrt', 'Die Daten sind verschlüsselt und nur mit entsperrtem Tresor lesbar. Bitte melden Sie sich neu an.');
        }
        $jahr = $this->jahr($request);
        $grundlage = self::grundlage($request);
        $ergebnis = ($this->budgets)()->vergleich($tresor, $grundlage, $jahr, $this->berechtigungen()->zugriffsbereich(Permission::ReportView));

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="budget_' . $jahr . '.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], self::tabelle($ergebnis)->csv());
    }

    public function speichern(Request $request): ResponseInterface
    {
        $this->session->start();
        // The form's year as sent: an invalid one is refused by the service,
        // never silently replaced by another year.
        $roh = self::text($request->post['jahr'] ?? null);
        $jahr = preg_match('/^\d{4}$/', $roh) === 1 ? (int) $roh : 0;
        $anzeigeJahr = $this->jahr($request, true);
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFehler($anzeigeJahr);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->fehler(403, 'Tresor gesperrt', 'Budgets sind verschlüsselt und nur mit entsperrtem Tresor zu pflegen. Bitte melden Sie sich neu an.');
        }
        $felder = [
            'kategorie' => self::text($request->post['kategorie'] ?? null),
            'kostenstelle' => self::text($request->post['kostenstelle'] ?? null),
            'betrag' => self::text($request->post['betrag'] ?? null),
        ];
        try {
            $gespeichert = ($this->budgets)()->speichern($tresor, $jahr, $felder, $this->session->userId(), new \DateTimeImmutable());
        } catch (BudgetFehler $e) {
            return $this->anzeigen($request, $felder, $e, 422, $anzeigeJahr);
        }
        ($this->audit)()->record(AuditAction::BudgetGespeichert, $this->session->userId(), $request->ip, $gespeichert['id'], [
            'jahr' => $gespeichert['jahr'],
            'kategorie_id' => $gespeichert['kategorie'],
            'kostenstelle_id' => $gespeichert['kostenstelle'],
            'neu' => $gespeichert['neu'],
        ]);
        $this->session->flash($gespeichert['neu'] ? 'Budget angelegt.' : 'Budget geändert.');

        return Response::redirect(self::PFAD . '?jahr=' . $jahr);
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $jahr = $this->jahr($request, true);
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFehler($jahr);
        }
        $geloescht = ($this->budgets)()->loeschen((int) ($params['id'] ?? 0));
        if ($geloescht === null) {
            return $this->fehler(404, 'Nicht gefunden', 'Dieses Budget gibt es nicht (mehr).');
        }
        ($this->audit)()->record(AuditAction::BudgetGeloescht, $this->session->userId(), $request->ip, $geloescht->id, [
            'jahr' => $geloescht->year,
            'kategorie_id' => $geloescht->categoryId,
            'kostenstelle_id' => $geloescht->costCenterId,
        ]);
        $this->session->flash('Budget gelöscht.');

        return Response::redirect(self::PFAD . '?jahr=' . $geloescht->year);
    }

    /**
     * The CSV of the page's table: Soll, Ist, Abweichung in cents, the
     * percentages as text with one decimal.
     */
    public static function tabelle(Budgetergebnis $ergebnis): Berichtstabelle
    {
        $zeilen = [];
        foreach ($ergebnis->zeilen as $zeile) {
            $zeilen[] = [
                $ergebnis->kategorie($zeile),
                $ergebnis->kostenstelle($zeile),
                $zeile->soll->betrag,
                $zeile->ist,
                $zeile->abweichung(),
                self::prozent($zeile->abweichungPromille),
                self::prozent($zeile->ausschoepfungPromille),
            ];
        }

        return new Berichtstabelle(
            ['Kategorie', 'Kostenstelle', 'Soll (EUR)', 'Ist (EUR)', 'Abweichung Soll − Ist (EUR)', 'Abweichung (%)', 'Ausgeschöpft (%)'],
            $zeilen,
        );
    }

    /** Tenths of a percent as "12,5 %", "-3,0 %"; null as "–". */
    public static function prozent(?int $promille): string
    {
        if ($promille === null) {
            return '–';
        }

        return ($promille < 0 ? '-' : '') . intdiv(abs($promille), 10) . ',' . (abs($promille) % 10) . ' %';
    }

    /**
     * @param array<string, string> $felder the form as sent (after an error)
     */
    private function anzeigen(Request $request, array $felder, ?BudgetFehler $fehler, int $status, ?int $jahr = null): ResponseInterface
    {
        $b = $this->berechtigungen();
        $heute = new \DateTimeImmutable('today');
        $jahr ??= $this->jahr($request);
        $grundlage = self::grundlage($request);
        $tresor = $this->tresor($request);
        // Without an unlocked vault nothing is read - not even the database.
        $ergebnis = null;
        $pflegen = false;
        $auswahl = ['kategorien' => [], 'kostenstellen' => []];
        if ($tresor !== null) {
            $budgets = ($this->budgets)();
            $ergebnis = $budgets->vergleich($tresor, $grundlage, $jahr, $b->zugriffsbereich(Permission::ReportView));
            $pflegen = $b->darf(Permission::BankBook);
            if ($pflegen) {
                $auswahl = $budgets->auswahl();
            }
        }
        $erstes = Dashboard::erstesJahr($b, ZeitraumAuswahl::ERSTES_JAHR);
        $letztes = self::letztesJahr($b, $heute);

        $antwort = Response::html($this->view->render('app/auswertung-budget', [
            'title' => 'Budget ' . $jahr,
            'flash' => $this->session->pullFlash(),
            'jahr' => $jahr,
            'vorjahr' => $jahr > $erstes ? $jahr - 1 : null,
            'folgejahr' => $jahr < $letztes ? $jahr + 1 : null,
            'grundlage' => $grundlage,
            'entsperrt' => $tresor !== null,
            'ergebnis' => $ergebnis,
            'pflegen' => $pflegen,
            'auswahl' => $auswahl,
            'csvErlaubt' => $b->darf(Permission::ExportCsv),
            'csrf' => $this->session->csrfToken(),
            'felder' => $felder,
            'fehler' => $fehler,
        ], Area::App), $status);

        return new Response($antwort->status, [...$antwort->headers, 'Cache-Control' => 'no-store, private'], $antwort->body, $antwort->additionalCookies);
    }

    /**
     * The year from `?jahr=` (or the form): a plain number from the first
     * year to next year - budgets are planned ahead -, else the dashboard's
     * default year.
     */
    private function jahr(Request $request, bool $ausFormular = false): int
    {
        $b = $this->berechtigungen();
        $heute = new \DateTimeImmutable('today');
        $wert = $ausFormular ? ($request->post['jahr'] ?? null) : ($request->query['jahr'] ?? null);
        if (is_string($wert) && preg_match('/^\d{4}$/', $wert) === 1) {
            $jahr = (int) $wert;
            if ($jahr >= ZeitraumAuswahl::ERSTES_JAHR && $jahr <= (int) $heute->format('Y') + 1) {
                return $jahr;
            }
        }

        return Dashboard::standardJahr($b, $heute);
    }

    /** Next year, or the last year of the account's period scope. */
    private static function letztesJahr(Berechtigungen $b, \DateTimeImmutable $heute): int
    {
        $naechstes = (int) $heute->format('Y') + 1;
        $bis = $b->zugriffsbereich(Permission::ReportView)->bis;

        return $bis !== null && (int) $bis->format('Y') < $naechstes ? (int) $bis->format('Y') : $naechstes;
    }

    private static function grundlage(Request $request): Grundlage
    {
        return Grundlage::tryFrom(self::text($request->query['grundlage'] ?? null)) ?? Grundlage::STANDARD;
    }

    private function csrfFehler(int $jahr): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect(self::PFAD . '?jahr=' . $jahr);
    }

    private function fehler(int $status, string $titel, string $text): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => $titel,
            'message' => $text,
            'startseite' => self::PFAD,
        ], Area::App), $status);
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    private static function text(mixed $wert): string
    {
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
}
