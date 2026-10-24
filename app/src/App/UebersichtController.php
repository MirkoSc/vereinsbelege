<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Permission;
use App\Domain\SeriesStatus;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Account\SessionVault;
use App\Service\Bank\Uebersichten;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Invoice\SerienAnsicht;
use App\Service\Invoice\SerienFaelligkeit;
use App\Service\Invoice\Serien;
use App\View\Area;
use App\View\View;

/**
 * The work lists "Offen" /app/offen/... (M10-5, issue #69, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Übersichten", E-37): unpaid
 * receipts, open reimbursements per submitter, bookings without a receipt,
 * and expected receipts of recurring series. Read-only - every action
 * links to the page where it is done.
 *
 * Rights (app/src/routes.php): the receipt lists `report.view` with its
 * scope (cost center, period - in SQL), the bookings `bank.view` with its
 * scope, the expected receipts `document.edit` without a scope like the
 * series pages (E-26). The tab row only shows the lists the account may
 * open. Without an unlocked vault the pages say so and show nothing.
 *
 * Nothing of a receipt or booking goes into a URL beyond its id.
 */
final readonly class UebersichtController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Uebersichten $uebersichten,
        private Serien $serien,
    ) {
    }

    /** Template => [path, tab label, right] - the order of the tab row. */
    private const array REITER = [
        'offen-unbezahlt' => ['/app/offen/rechnungen', 'Unbezahlt', Permission::ReportView],
        'offen-erstattungen' => ['/app/offen/erstattungen', 'Erstattungen', Permission::ReportView],
        'offen-buchungen' => ['/app/offen/buchungen', 'Buchungen ohne Beleg', Permission::BankView],
        'offen-erwartet' => ['/app/offen/erwartet', 'Erwartete Belege', Permission::DocumentEdit],
    ];

    /** /app/offen: the first list the account may open. */
    public function start(Request $request): ResponseInterface
    {
        $b = $this->berechtigungen();
        foreach (self::REITER as [$href, , $recht]) {
            if ($b->darf($recht)) {
                return Response::redirect($href);
            }
        }

        return Response::redirect('/app');
    }

    public function unbezahlt(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $altbestand = ($request->query['altbestand'] ?? '') === '1';
        $ergebnis = $tresor === null
            ? ['posten' => [], 'unlesbar' => 0]
            : $this->uebersichten->unbezahlt($tresor, $this->berechtigungen()->zugriffsbereich(Permission::ReportView), new \DateTimeImmutable());
        $alt = array_values(array_filter($ergebnis['posten'], static fn($p): bool => $p->altbestand));
        $posten = $altbestand ? $alt : array_values(array_filter($ergebnis['posten'], static fn($p): bool => !$p->altbestand));

        return $this->seite('offen-unbezahlt', 'Unbezahlte Rechnungen', $tresor !== null, [
            'posten' => $posten,
            'summen' => Uebersichten::summen($posten),
            'altbestand' => $altbestand,
            'altbestandAnzahl' => count($alt),
            'unlesbar' => $ergebnis['unlesbar'],
        ]);
    }

    public function erstattungen(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        $ergebnis = $tresor === null
            ? ['empfaenger' => [], 'unlesbar' => 0]
            : $this->uebersichten->erstattungen($tresor, $this->berechtigungen()->zugriffsbereich(Permission::ReportView), new \DateTimeImmutable());

        return $this->seite('offen-erstattungen', 'Offene Erstattungen', $tresor !== null, $ergebnis);
    }

    public function buchungen(Request $request): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);

        return $this->seite('offen-buchungen', 'Buchungen ohne Beleg', $tresor !== null, [
            'fehlend' => $tresor === null ? [] : $this->uebersichten->buchungenOhneBeleg($tresor, $this->berechtigungen()->zugriffsbereich(Permission::BankView)),
            'darfHochladen' => $this->berechtigungen()->darf(Permission::DocumentSubmitInternal),
            'einreichenUrl' => $request->ownOrigin() . '/einreichen',
        ]);
    }

    public function erwartet(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->berechtigungen()->zugriffsbereich(Permission::DocumentEdit)->istUnbeschraenkt()) {
            return Response::html($this->view->render('error', [
                'title' => 'Nicht verfügbar',
                'message' => 'Erwartete Belege kommen aus wiederkehrenden Rechnungen, die alle Belege eines Lieferanten umfassen. Ihr Zugriff ist auf einen Bereich beschränkt, deshalb ist diese Seite für Sie nicht verfügbar.',
                'startseite' => '/app',
            ], Area::App), 403);
        }
        $tresor = $this->tresor($request);
        $serien = $tresor === null ? [] : array_values(array_filter(
            $this->serien->liste($tresor, new \DateTimeImmutable()),
            static fn(SerienAnsicht $s): bool => $s->record->status === SeriesStatus::Bestaetigt
                && in_array($s->kennzahlen?->faelligkeit, [SerienFaelligkeit::Faellig, SerienFaelligkeit::Ueberfaellig], true),
        ));
        // Overdue first, then by the next expected date.
        usort($serien, static fn(SerienAnsicht $a, SerienAnsicht $b): int => [
            $a->kennzahlen?->faelligkeit !== SerienFaelligkeit::Ueberfaellig,
            $a->record->nextExpected,
        ] <=> [
            $b->kennzahlen?->faelligkeit !== SerienFaelligkeit::Ueberfaellig,
            $b->record->nextExpected,
        ]);

        return $this->seite('offen-erwartet', 'Erwartete Belege', $tresor !== null, ['serien' => $serien]);
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function seite(string $vorlage, string $titel, bool $entsperrt, array $daten): ResponseInterface
    {
        $b = $this->berechtigungen();
        $reiter = [];
        foreach (self::REITER as $name => [$href, $label, $recht]) {
            if ($b->darf($recht)) {
                $reiter[] = ['href' => $href, 'label' => $label, 'aktiv' => $name === $vorlage];
            }
        }

        return Response::html($this->view->render('app/' . $vorlage, [
            'title' => $titel,
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $entsperrt,
            'reiter' => $reiter,
            ...$daten,
        ], Area::App));
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
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
