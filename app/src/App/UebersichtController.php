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
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
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
        private ?MailSettingsRepository $mailSettings = null,
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
        foreach (self::REITER as $name => [$href, , $recht]) {
            if (self::darf($b, $recht, $name === 'offen-erwartet')) {
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
            ? ['posten' => [], 'unlesbar' => 0, 'andere' => 0]
            : $this->uebersichten->unbezahlt($tresor, $this->berechtigungen()->zugriffsbereich(Permission::ReportView), new \DateTimeImmutable(), $altbestand);

        return $this->seite('offen-unbezahlt', 'Unbezahlte Rechnungen', $tresor !== null, [
            'posten' => $ergebnis['posten'],
            'summen' => Uebersichten::summen($ergebnis['posten']),
            'altbestand' => $altbestand,
            'altbestandAnzahl' => $altbestand ? 0 : $ergebnis['andere'],
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
            // Only a checked base (configured public URL, or a host of the
            // sender's domain) goes into a text meant for a mail.
            'einreichenUrl' => $this->einreichenUrl($request),
        ]);
    }

    public function erwartet(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!self::darf($this->berechtigungen(), Permission::DocumentEdit, true)) {
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
            if (self::darf($b, $recht, $name === 'offen-erwartet')) {
                $reiter[] = ['href' => $href, 'label' => $label, 'aktiv' => $name === $vorlage];
            }
        }

        return Response::html($this->view->render('app/' . $vorlage, [
            'title' => $titel,
            'flash' => $this->session->pullFlash(),
            'entsperrt' => $entsperrt,
            'reiter' => $reiter,
            // Where a receipt opens: the review page for who may edit, else
            // the read-only inbox entry, else nowhere.
            'belegPfad' => $b->darf(Permission::DocumentEdit) ? '/app/belege/pruefen/' : ($b->darf(Permission::InboxView) ? '/app/posteingang/' : null),
            ...$daten,
        ], Area::App));
    }

    private function einreichenUrl(Request $request): ?string
    {
        $basis = $this->mailSettings === null
            ? null
            : PublicUrl::resolve($this->mailSettings->get(), $request->header('host') ?? '', Request::httpsFromGlobals());

        return $basis === null ? null : rtrim($basis, '/') . '/einreichen';
    }

    /** The expected receipts span every cost center and period (E-26): only without a scope. */
    private static function darf(Berechtigungen $b, Permission $recht, bool $ohneScope): bool
    {
        return $b->darf($recht) && (!$ohneScope || $b->zugriffsbereich($recht)->istUnbeschraenkt());
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
