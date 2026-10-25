<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\InvoiceDirection;
use App\Domain\Permission;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Report\Dashboard;
use App\Service\Report\Dimension;
use App\Service\Report\Geldfluss;
use App\Service\Report\Grundlage;
use App\Service\Report\ZeitraumAuswahl;
use App\View\Area;
use App\View\View;

/**
 * "Wohin fließt das Geld" with drilldown (issue #73, docs/spec/
 * 05-auswertung-und-export.md section 1, E-51). Route right `report.view`;
 * its scope (cost center, period) narrows every figure.
 *
 * The query string carries only structure: the period (ZeitraumAuswahl), the
 * basis, the side (Ausgaben/Einnahmen) and for the drilldown the dimension
 * and a plaintext id (or `ohne`). Never an amount or a name. A link from a
 * line to its receipt goes to the normal receipt page, which checks its own
 * right and scope again; to its booking only with `bank.view`.
 *
 * Amounts are vault data: without an unlocked vault the page says so and
 * shows nothing. Never cached by the browser.
 */
final readonly class AuswertungController
{
    /** The longest free period: one request decrypts every line of it. */
    public const int MAX_JAHRE_FREI = 3;

    /**
     * @param \Closure(): Geldfluss $geldfluss built only when figures are
     *        actually rendered (it opens the database)
     */
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private \Closure $geldfluss,
    ) {
    }

    /** /app/auswertung - the first report view. */
    public function start(): ResponseInterface
    {
        return Response::redirect('/app/auswertung/geldfluss');
    }

    public function geldfluss(Request $request): ResponseInterface
    {
        $this->session->start();
        $filter = $this->filter($request);
        $tresor = $this->tresor($request);
        $ergebnis = $tresor === null ? null : ($this->geldfluss)()->uebersicht(
            $tresor,
            $filter['grundlage'],
            $filter['auswahl']->zeitraum,
            $filter['bereich'],
            $filter['seite'],
        );

        return $this->seite('app/auswertung-geldfluss', 'Wohin fließt das Geld', [
            ...$filter,
            'entsperrt' => $tresor !== null,
            'ergebnis' => $ergebnis,
        ]);
    }

    /** The lines behind one category or cost center. */
    public function posten(Request $request): ResponseInterface
    {
        $this->session->start();
        $dimension = Dimension::tryFrom(self::text($request->query['nach'] ?? null));
        $schluesselWert = self::text($request->query['schluessel'] ?? null);
        if ($dimension === null || !in_array($dimension, Geldfluss::DIMENSIONEN, true)
            || ($schluesselWert !== 'ohne' && preg_match('/^[1-9]\d{0,9}$/', $schluesselWert) !== 1)) {
            return Response::html($this->view->render('error', [
                'title' => 'Nicht gefunden',
                'message' => 'Diese Auswahl gibt es nicht.',
                'startseite' => '/app/auswertung/geldfluss',
            ], Area::App), 404);
        }
        $schluessel = $schluesselWert === 'ohne' ? null : (int) $schluesselWert;

        $filter = $this->filter($request);
        $b = $this->berechtigungen();
        $tresor = $this->tresor($request);
        $drilldown = $tresor === null ? null : ($this->geldfluss)()->posten(
            $tresor,
            $filter['grundlage'],
            $filter['auswahl']->zeitraum,
            $filter['bereich'],
            $filter['seite'],
            $dimension,
            $schluessel,
            $b->darf(Permission::BankView) ? $b->zugriffsbereich(Permission::BankView) : null,
        );

        return $this->seite('app/auswertung-posten', $drilldown?->titel ?? 'Einzelposten', [
            ...$filter,
            'entsperrt' => $tresor !== null,
            'dimension' => $dimension,
            'schluessel' => $schluesselWert,
            'drilldown' => $drilldown,
            // Where a receipt opens: the review page for who may edit, else
            // the read-only inbox entry, else nowhere - like the lists "Offen".
            'belegPfad' => $b->darf(Permission::DocumentEdit) ? '/app/belege/pruefen/' : ($b->darf(Permission::InboxView) ? '/app/posteingang/' : null),
            'buchungPfad' => $b->darf(Permission::BankView) ? '/app/buchungen/' : null,
        ]);
    }

    /**
     * The filters every view shares, from the query string; anything not
     * recognised falls back to its default. The period is the shared
     * ZeitraumAuswahl (issue #74); its default is the dashboard's year.
     *
     * @return array{auswahl: ZeitraumAuswahl, zuLang: bool, grundlage: Grundlage, seite: InvoiceDirection, bereich: \App\Domain\Zugriffsbereich, erstesJahr: int, letztesJahr: int, ansicht: array<string, string>, parameter: array<string, string>}
     */
    private function filter(Request $request): array
    {
        $b = $this->berechtigungen();
        $heute = new \DateTimeImmutable('today');
        $standard = ZeitraumAuswahl::jahr(Dashboard::standardJahr($b, $heute));
        $auswahl = ZeitraumAuswahl::ausAnfrage($request->query, $standard, $heute);
        // One request decrypts every line of the period (CLAUDE.md section 1):
        // a free period longer than MAX_JAHRE_FREI falls back with a note.
        $zuLang = $auswahl->zeitraum->bis >= $auswahl->zeitraum->von->modify('+' . self::MAX_JAHRE_FREI . ' years');
        if ($zuLang) {
            $auswahl = $standard;
        }
        $grundlage = Grundlage::tryFrom(self::text($request->query['grundlage'] ?? null)) ?? Grundlage::STANDARD;
        $seite = self::text($request->query['seite'] ?? null) === 'einnahmen' ? InvoiceDirection::Einnahme : InvoiceDirection::Ausgabe;
        $ansicht = [
            'grundlage' => $grundlage->value,
            'seite' => $seite === InvoiceDirection::Einnahme ? 'einnahmen' : 'ausgaben',
        ];

        return [
            'auswahl' => $auswahl,
            'zuLang' => $zuLang,
            'grundlage' => $grundlage,
            'seite' => $seite,
            'bereich' => $b->zugriffsbereich(Permission::ReportView),
            'erstesJahr' => ZeitraumAuswahl::ERSTES_JAHR,
            'letztesJahr' => (int) $heute->format('Y') + 1,
            'ansicht' => $ansicht,
            'parameter' => [...$auswahl->query(), ...$ansicht],
        ];
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function seite(string $vorlage, string $titel, array $daten): Response
    {
        $antwort = Response::html($this->view->render($vorlage, [
            'title' => $titel,
            'flash' => $this->session->pullFlash(),
            ...$daten,
        ], Area::App));

        return new Response($antwort->status, [...$antwort->headers, 'Cache-Control' => 'no-store, private'], $antwort->body, $antwort->additionalCookies);
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
