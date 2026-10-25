<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\Berechtigungen;
use App\Domain\Document;
use App\Domain\DocumentStatus;
use App\Domain\DuplikatGrund;
use App\Domain\InboxItem;
use App\Domain\InvoiceDirection;
use App\Domain\InvoiceType;
use App\Domain\KategorieHerkunft;
use App\Domain\Permission;
use App\Domain\Sphere;
use App\Domain\Supplier;
use App\Domain\Zugriffsbereich;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\CategoryRepository;
use App\Repository\CostCenterRepository;
use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Document\DuplikatRuleViolation;
use App\Service\Document\Duplikatpruefung;
use App\Service\Document\ERechnungAuszug;
use App\Service\Document\KiAuszug;
use App\Service\Invoice\Festschreibung;
use App\Service\Invoice\InvoiceRuleViolation;
use App\Service\Invoice\KategorieVorschlag;
use App\Service\Invoice\Pruefung;
use App\Service\MasterData\Merkmale;
use App\Service\MasterData\Sphaereneinstellung;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The review page /app/belege/pruefen (issue #37/M6-3, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Prüfansicht"): the queue of documents
 * to capture, and one document with its pages next to the form - dates,
 * amounts, number, direction, partner, category, cost center - and the
 * action "Geprüft, nächster".
 *
 * Behind `document.edit` (Admin, Finanzen - app/src/routes.php), narrowed
 * to that right's scope in SQL: an id outside it answers 404 like one that
 * does not exist. Everything of a receipt is vault data - without the
 * unlocked vault the page says so and shows and writes nothing. Creating a
 * supplier from the form additionally needs `supplier.manage`, checked
 * again by App\Service\Invoice\Pruefung.
 *
 * Locking a checked receipt and lifting the lock with a reason (issue
 * #38/M6-4, App\Service\Invoice\Festschreibung) are two more actions of the
 * page, behind the same right and scope.
 *
 * A suspected duplicate (issue #40/M6-6, App\Service\Document\
 * Duplikatpruefung) is marked in the queue and explained on the page, with
 * "Als Duplikat verwerfen" and "Bewusst behalten" - same right and scope.
 *
 * The AI's suggestion (issue #47/M7-5) fills the form of a receipt not yet
 * captured, unsure fields marked yellow, its warnings on top; after a
 * failure the page offers "KI erneut versuchen" - same right and scope.
 *
 * The category of such a form comes from a rule, the supplier's default or
 * the AI, in that order (issue #49/M7-7) - the page says which.
 *
 * Nothing of a receipt goes into a URL or a flash message.
 */
final readonly class PruefungController
{
    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private Pruefung $pruefung,
        private Festschreibung $festschreibung,
        private CategoryRepository $kategorien,
        private CostCenterRepository $kostenstellen,
        private Duplikatpruefung $duplikate,
        private ?Sphaereneinstellung $sphaeren = null,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        $eintraege = $this->pruefung->warteschlange($this->bereich());
        $geprueft = $this->pruefung->geprueftListe($this->bereich());

        return Response::html($this->view->render('app/pruefen-liste', [
            'title' => 'Belege prüfen',
            'flash' => $this->session->pullFlash(),
            'eintraege' => $eintraege,
            'geprueft' => $geprueft,
            'duplikate' => $this->duplikate->verdaechtige(array_map(
                static fn(InboxItem $item): int => $item->document->id,
                [...$eintraege, ...$geprueft],
            )),
            'abgeschnitten' => count($eintraege) >= Pruefung::WARTESCHLANGE,
            'kostenstellen' => $this->kostenstellenNamen(),
            'entsperrt' => $this->tresor($request) !== null,
        ], Area::App));
    }

    /**
     * @param array<string, string> $params
     */
    public function formular(Request $request, array $params): ResponseInterface
    {
        $this->session->start();

        $eintrag = $this->pruefung->eintrag((int) $params['id'], $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->seite($eintrag, null, [], null);
        }

        $document = $eintrag->document;
        $beleg = $this->pruefung->beleg($document, $tresor);
        $felder = $this->pruefung->felder($document, $beleg);
        $vorbelegbar = $beleg === null && $document->status->pruefbar();
        $leer = $felder;
        if ($vorbelegbar) {
            // What the submitter asked about the money prefills the
            // Zahlungsweg (M10-1, issue #65).
            $felder = $this->pruefung->zahlungswegVorbelegen($felder, $eintrag, $tresor);
        }

        // No receipt captured yet: what was read automatically fills the
        // form - the person still confirms it by saving. First the AI's
        // suggestion (issue #47/M7-5) ...
        $ki = $vorbelegbar ? $this->pruefung->kiAuszug($document, $tresor) : null;
        $nachKi = $felder;
        if ($ki !== null && $ki->extraktion !== null) {
            ['felder' => $felder] = $this->pruefung->vorbelegen($felder, $ki->extraktion, $tresor);
            $nachKi = $felder;
        }

        // ... then an e-invoice over it (issue #46/M7-4): what it states was
        // read from the invoice itself, not suggested; the AI only adds what
        // it does not know (category, purpose, cost center).
        $eRechnung = $vorbelegbar ? $this->pruefung->eRechnung($document, $tresor) : null;
        $hinweise = [];
        if ($eRechnung !== null && $eRechnung->extraktion !== null && $eRechnung->gelesen()) {
            ['felder' => $felder, 'hinweise' => $hinweise] = $this->pruefung->vorbelegen($felder, $eRechnung->extraktion, $tresor);
        }

        // ... then the supplier as `resolve_supplier` found it (issue
        // #48/M7-6) - it knows the master data, the AI and the e-invoice
        // only name a supplier.
        $lieferant = ['hinweis' => '', 'unsicher' => false, 'pruefen' => false];
        $aufloesung = $vorbelegbar ? $this->pruefung->lieferantenAufloesung($document, $tresor) : null;
        if ($aufloesung !== null) {
            ['felder' => $felder, 'hinweis' => $lieferant['hinweis'], 'unsicher' => $lieferant['unsicher'], 'pruefen' => $lieferant['pruefen']]
                = $this->pruefung->lieferantVorbelegen($felder, $aufloesung, $tresor);
        }
        $nachAufloesung = $felder['lieferant'];
        $vorgeschlagen = $lieferant['unsicher'] ? $nachAufloesung : '';

        // From the archive import (issue #77/M12-3): what the path said lies
        // over what was read automatically - the archive was sorted by a
        // person.
        $archivHinweise = [];
        $archiv = $vorbelegbar ? $this->pruefung->archivVorgabe($document, $tresor) : null;
        if ($archiv !== null) {
            ['felder' => $felder, 'hinweise' => $archivHinweise] = $archiv->ueberlagern($leer, $felder, $eRechnung?->gelesen() === true ? 'E-Rechnung' : 'KI');
        }

        // The category in a fixed order (issue #49/M7-7): a rule of the
        // club, the supplier's default, then the AI - on partner, direction
        // and purpose as filled in now. A category from the archive stays,
        // a person sorted it.
        $kategorie = null;
        if ($vorbelegbar && $archiv?->kategorieId === null) {
            ['felder' => $felder, 'vorschlag' => $kategorie] = $this->pruefung->kategorieVorschlagen($felder, $ki, $tresor, $this->bereich());
        }

        // What confirming the supplier would teach it (issue #201/M7-6b) -
        // only for the one the page chose from what was read, not one the
        // archive set, and only for whom may maintain suppliers.
        $lernen = null;
        if ($vorbelegbar && $felder['lieferant'] !== '' && $felder['lieferant'] === $nachAufloesung && $this->berechtigungen()->darf(Permission::SupplierManage)) {
            $lernen = $this->pruefung->lernbareMerkmale($document, $tresor, (int) $felder['lieferant']);
        }

        $unsicher = $ki === null ? [] : self::unsichereFelder($ki->unsicher, $leer, $nachKi, $felder);
        if ($kategorie !== null && $kategorie->herkunft !== KategorieHerkunft::Ki) {
            // A rule or the supplier chose it - the AI's doubt does not apply.
            $unsicher = array_values(array_diff($unsicher, ['kategorie']));
        }

        return $this->seite(
            $eintrag,
            $tresor,
            $felder,
            null,
            eRechnung: $eRechnung,
            eRechnungHinweise: $hinweise,
            archivHinweise: $archivHinweise,
            ki: $ki,
            unsicher: [
                ...$unsicher,
                // A suggestion by a similar name (step 5) - unless the archive chose another.
                ...($vorgeschlagen !== '' && $felder['lieferant'] === $vorgeschlagen ? ['lieferant'] : []),
            ],
            kategorieVorschlag: $kategorie,
            // Said only while the choice is still the resolution's.
            lieferantHinweis: $felder['lieferant'] === $nachAufloesung ? $lieferant['hinweis'] : '',
            lieferantPruefen: $lieferant['pruefen'],
            lernen: $lernen === null ? null : ['fuer' => (int) $felder['lieferant'], 'merkmale' => $lernen, 'an' => true],
        );
    }

    /**
     * "KI erneut versuchen" / "Mit KI auslesen" (issue #47/M7-5): queues
     * the AI extraction again - from `ki_fehler` back to
     * `bereit_zur_auswertung`, or for a document still waiting that was
     * never read. CSRF, scope (404 outside it) and the unlocked vault like
     * the page's other actions; a refusal comes back as a message.
     *
     * @param array<string, string> $params
     */
    public function kiErneut(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/belege/pruefen/' . $id;
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $eintrag = $this->pruefung->eintrag($id, $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        if ($this->tresor($request) === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        try {
            $this->pruefung->kiErneut($eintrag->document, $this->session->userId(), $request->ip, new \DateTimeImmutable());
        } catch (InvoiceRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $this->session->flash(sprintf(
            'Die KI liest Beleg %s neu aus. Das läuft im Hintergrund, solange die App geöffnet ist – die Vorschläge erscheinen nach dem Neuladen dieser Seite.',
            $eintrag->referenz ?? '#' . $id,
        ));

        return Response::redirect($ziel);
    }

    /**
     * The form fields to mark yellow (issue #47/M7-5, docs/spec/
     * 03-erfassung-und-ki.md section 6 "Prüfansicht": confidence below 0.7):
     * only fields the AI actually filled in and nothing laid over since -
     * not one an e-invoice or the archive filled, not one the AI left empty.
     *
     * @param list<string> $unsicher `confidence` keys (AusleseVorschlag::unsicher())
     * @param array<string, string> $vorher the form before the AI
     * @param array<string, string> $nachKi the form after the AI
     * @param array<string, string> $zuletzt the form as the page shows it
     *
     * @return list<string>
     */
    private static function unsichereFelder(array $unsicher, array $vorher, array $nachKi, array $zuletzt): array
    {
        $felderJe = [
            'supplier' => ['lieferant', 'lieferant_neu_name'],
            'total_gross' => ['brutto'],
            'invoice_date' => ['datum'],
            'category' => ['kategorie'],
        ];
        $markiert = [];
        foreach ($unsicher as $schluessel) {
            foreach ($felderJe[$schluessel] ?? [] as $feld) {
                $wert = $nachKi[$feld] ?? '';
                if ($wert !== '' && $wert !== ($vorher[$feld] ?? '') && $wert === ($zuletzt[$feld] ?? '')) {
                    $markiert[] = $feld;
                }
            }
        }

        return $markiert;
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/belege/pruefen/' . $id;
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $eintrag = $this->pruefung->eintrag($id, $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $felder = self::felder($request);
        // Only a form that showed the field sends it (issue #84, E-15) - a
        // form opened before the switch was turned on keeps the stored sphere.
        if ($this->sphaerenAktiv() && array_key_exists(Pruefung::FELD_SPHAERE, $request->post)) {
            $wert = $request->post[Pruefung::FELD_SPHAERE];
            $felder[Pruefung::FELD_SPHAERE] = is_string($wert) ? $wert : '';
        }
        $geprueft = ($request->post['aktion'] ?? '') === 'geprueft';
        // The supplier the page offered to teach (issue #201/M7-6b).
        $fuer = $request->post['lieferant_lernen_fuer'] ?? '';
        $lernenFuer = is_string($fuer) && ctype_digit($fuer) ? (int) $fuer : null;
        $lernenAn = ($request->post['lieferant_lernen'] ?? '') === '1';
        $stand = $request->post['lieferant_lernen_stand'] ?? '';
        try {
            $ergebnis = $this->pruefung->speichern(
                $eintrag->document,
                $tresor,
                $felder,
                $geprueft,
                $this->berechtigungen()->darf(Permission::SupplierManage),
                $this->session->userId(),
                $request->ip,
                new \DateTimeImmutable(),
                $lernenAn ? $lernenFuer : null,
                is_string($stand) ? $stand : '',
            );
        } catch (InvoiceRuleViolation $e) {
            // Shown again as it was, ticked or not - planned anew.
            $merkmale = $lernenFuer === null || !$this->berechtigungen()->darf(Permission::SupplierManage)
                ? null
                : $this->pruefung->lernbareMerkmale($eintrag->document, $tresor, $lernenFuer);

            return $this->seite($eintrag, $tresor, $felder, $e, 422, lernen: $merkmale === null ? null : ['fuer' => (int) $lernenFuer, 'merkmale' => $merkmale, 'an' => $lernenAn]);
        }

        // Only the reference (plaintext) - the message shows on the next
        // document's page, so it has to say which one it was about.
        $referenz = $eintrag->referenz ?? '#' . $id;
        $meldung = sprintf($geprueft ? 'Beleg %s geprüft.' : 'Beleg %s gespeichert.', $referenz);
        if ($ergebnis->lieferantAngelegt) {
            $meldung .= ' Der neue Partner ist angelegt – weitere Angaben unter „Lieferanten“.';
        }
        if ($ergebnis->gelernt !== []) {
            $meldung .= ' Beim Lieferanten ergänzt: ' . implode(', ', $ergebnis->gelernt) . '.';
        }
        $warnungen = $ergebnis->warnungen;
        if ($ergebnis->nichtGelernt) {
            $warnungen[] = 'Die neuen Merkmale wurden nicht beim Lieferanten ergänzt – sie haben sich seit dem Anzeigen geändert. Bitte unter „Lieferanten“ prüfen.';
        }
        // After "Geprüft, nächster" the page with the duplicate notice is
        // gone - the message on the next page has to carry it (issue #40).
        $duplikat = $this->duplikate->verdacht($eintrag->document, $this->bereich());
        if ($duplikat->besteht()) {
            $gruende = implode(' bzw. ', array_map(static fn(DuplikatGrund $g): string => $g->bezeichnung(), $duplikat->gruende()));
            $warnungen[] = 'Möglicherweise doppelt eingereicht' . ($gruende === '' ? '' : ' (' . $gruende . ')') . ' – siehe Hinweis am Beleg.';
        }
        if ($warnungen !== []) {
            $this->session->flash($meldung . ' Hinweis: ' . implode(' ', $warnungen), FlashArt::Warnung);
        } else {
            $this->session->flash($meldung);
        }

        if (!$geprueft) {
            return Response::redirect($ziel);
        }

        $naechster = $this->pruefung->naechster($this->bereich(), $id);

        return Response::redirect($naechster === null ? '/app/belege/pruefen' : '/app/belege/pruefen/' . $naechster);
    }

    /**
     * "Als Duplikat verwerfen" (issue #40/M6-6): the document is rejected
     * with the reason the application writes - on to the next one in the
     * queue, it has left it.
     *
     * @param array<string, string> $params
     */
    public function duplikatVerwerfen(Request $request, array $params): ResponseInterface
    {
        return $this->duplikatAktion($request, $params, true);
    }

    /**
     * "Bewusst behalten" (issue #40/M6-6): the suspicion is resolved, the
     * page stays.
     *
     * @param array<string, string> $params
     */
    public function duplikatBehalten(Request $request, array $params): ResponseInterface
    {
        return $this->duplikatAktion($request, $params, false);
    }

    /**
     * Locks a checked receipt (issue #38/M6-4, docs/spec/01-sicherheit.md
     * section 7). `document.edit` is enough - no four-eyes principle (E-09).
     *
     * @param array<string, string> $params
     */
    public function festschreiben(Request $request, array $params): ResponseInterface
    {
        return $this->festschreibAktion($request, $params, function (Document $document, string $referenz) use ($request): string {
            $this->festschreibung->festschreiben($document, $this->session->userId(), $request->ip, new \DateTimeImmutable());

            return sprintf('Beleg %s festgeschrieben.', $referenz);
        });
    }

    /**
     * Lifts the lock again - the one correction path. The reason is
     * mandatory and goes into the audit log; the receipt is back in review.
     *
     * @param array<string, string> $params
     */
    public function festschreibungAufheben(Request $request, array $params): ResponseInterface
    {
        return $this->festschreibAktion($request, $params, function (Document $document, string $referenz) use ($request): string {
            $grund = $request->post['grund'] ?? '';
            $this->festschreibung->aufheben($document, is_string($grund) ? $grund : '', $this->session->userId(), $request->ip, new \DateTimeImmutable());

            return sprintf('Die Festschreibung von Beleg %s ist aufgehoben – der Beleg ist wieder in Prüfung.', $referenz);
        });
    }

    /**
     * What both lock actions share: CSRF, the scope (an id outside it
     * answers 404), the unlocked vault, and the page answering a refusal.
     *
     * @param array<string, string> $params
     * @param \Closure(Document, string): string $aktion does it and returns
     *        the message for the next page
     */
    private function festschreibAktion(Request $request, array $params, \Closure $aktion): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/belege/pruefen/' . $id;
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $eintrag = $this->pruefung->eintrag($id, $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        try {
            $meldung = $aktion($eintrag->document, $eintrag->referenz ?? '#' . $id);
        } catch (InvoiceRuleViolation $e) {
            // The page as it is now, not as it was read before the refusal.
            $aktuell = $this->pruefung->eintrag($id, $this->bereich()) ?? $eintrag;
            $document = $aktuell->document;

            return $this->seite($aktuell, $tresor, $this->pruefung->felder($document, $this->pruefung->beleg($document, $tresor)), $e, 422);
        }

        $this->session->flash($meldung);

        return Response::redirect($ziel);
    }

    /**
     * CSRF, the scope (404 outside it) and the unlocked vault, like the
     * lock actions; a refusal comes back as a message on the page.
     *
     * @param array<string, string> $params
     */
    private function duplikatAktion(Request $request, array $params, bool $verwerfen): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        $ziel = '/app/belege/pruefen/' . $id;
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $eintrag = $this->pruefung->eintrag($id, $this->bereich());
        if ($eintrag === null) {
            return $this->nichtGefunden();
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $now = new \DateTimeImmutable();
        try {
            if ($verwerfen) {
                $this->duplikate->verwerfen($eintrag->document, $tresor, $this->bereich(), $this->session->userId(), $request->ip, $now);
            } else {
                $this->duplikate->behalten($eintrag->document, $this->session->userId(), $request->ip, $now);
            }
        } catch (DuplikatRuleViolation $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($ziel);
        }

        $referenz = $eintrag->referenz ?? '#' . $id;
        if (!$verwerfen) {
            $this->session->flash(sprintf('Beleg %s bleibt – der Duplikat-Verdacht ist aufgelöst.', $referenz));

            return Response::redirect($ziel);
        }

        $this->session->flash(sprintf('Beleg %s als Duplikat verworfen.', $referenz));
        $naechster = $this->pruefung->naechster($this->bereich(), $id);

        return Response::redirect($naechster === null ? '/app/belege/pruefen' : '/app/belege/pruefen/' . $naechster);
    }

    /**
     * One page, decrypted piece by piece into the response - like the
     * inbox's (App\App\InboxController::datei()), plus the page images of
     * the document's PDFs.
     *
     * @param array<string, string> $params
     */
    public function datei(Request $request, array $params): ResponseInterface
    {
        $this->session->start();

        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], self::TRESOR_GESPERRT);
        }

        $eintrag = $this->pruefung->eintrag((int) $params['id'], $this->bereich());
        $datei = $eintrag === null ? null : $this->pruefung->datei($eintrag, (int) $params['blob'], $tresor);
        if ($datei === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Nicht gefunden.');
        }

        return new StreamResponse($datei['chunks'], [
            'Content-Type' => $datei['mime'],
            'Content-Disposition' => ($datei['anhang'] ? 'attachment' : 'inline') . '; filename="' . $datei['dateiname'] . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array<string, string> $felder
     * @param list<string> $eRechnungHinweise
     * @param list<string> $archivHinweise from the archive import (issue #77/M12-3)
     * @param ?KiAuszug $ki the AI's suggestion, only before a receipt is saved (issue #47/M7-5)
     * @param list<string> $unsicher form fields the AI filled in unsure
     * @param ?KategorieVorschlag $kategorieVorschlag where the category of an unsaved form came from (issue #49/M7-7)
     * @param string $lieferantHinweis how the supplier was resolved (issue #48/M7-6)
     * @param bool $lieferantPruefen the AI created the chosen supplier, nobody has reviewed it yet
     * @param ?array{fuer: int, merkmale: Merkmale, an: bool} $lernen what confirming the
     *        supplier `fuer` would teach it, ticked or not (issue #201/M7-6b)
     */
    private function seite(
        InboxItem $eintrag,
        ?Vault $tresor,
        array $felder,
        ?InvoiceRuleViolation $fehler,
        int $status = 200,
        ?ERechnungAuszug $eRechnung = null,
        array $eRechnungHinweise = [],
        array $archivHinweise = [],
        ?KiAuszug $ki = null,
        array $unsicher = [],
        ?KategorieVorschlag $kategorieVorschlag = null,
        string $lieferantHinweis = '',
        bool $lieferantPruefen = false,
        ?array $lernen = null,
    ): ResponseInterface {
        $document = $eintrag->document;
        $bearbeitbar = $tresor !== null && $document->status->pruefbar();
        $sphaerenAktiv = $this->sphaerenAktiv();
        if ($sphaerenAktiv && $tresor !== null && !array_key_exists(Pruefung::FELD_SPHAERE, $felder)) {
            $felder[Pruefung::FELD_SPHAERE] = $this->pruefung->gespeicherteSphaere($document)?->value ?? '';
        }
        $kiOffen = $bearbeitbar && in_array($document->status, [DocumentStatus::BereitZurAuswertung, DocumentStatus::KiFehler], true);
        $warteschlange = $this->pruefung->warteschlange($this->bereich());
        $ids = array_map(static fn(InboxItem $item): int => $item->document->id, $warteschlange);
        $position = array_search($document->id, $ids, true);

        return Response::html($this->view->render('app/pruefen', [
            'title' => 'Beleg prüfen ' . ($eintrag->referenz ?? '#' . $document->id),
            'flash' => $this->session->pullFlash(),
            'eintrag' => $eintrag,
            'entsperrt' => $tresor !== null,
            'bearbeitbar' => $bearbeitbar,
            'festgeschriebenAm' => $tresor !== null && $document->status === DocumentStatus::Festgeschrieben ? $this->pruefung->festgeschriebenAm($document) : null,
            'ansehbar' => $tresor !== null && ($bearbeitbar || in_array($document->status, [DocumentStatus::Geprueft, DocumentStatus::Festgeschrieben], true)),
            'seiten' => $tresor === null ? ['bilder' => [], 'pdfs' => []] : $this->pruefung->seiten($document, $tresor),
            'felder' => $felder,
            'fehler' => $fehler?->getMessage(),
            'fehlerFeld' => $fehler?->feld,
            'konfliktId' => $fehler?->konfliktId,
            'richtungen' => InvoiceDirection::cases(),
            'belegarten' => InvoiceType::cases(),
            'kategorien' => $this->kategorieAuswahl($felder['kategorie'] ?? ''),
            'lieferanten' => $tresor === null ? [] : $this->lieferantenAuswahl($tresor),
            'kostenstellen' => $this->kostenstellenAuswahl($felder['kostenstelle'] ?? ''),
            // Tax spheres (issue #84): only with `sphaeren_aktiv` - null hides the field.
            'sphaeren' => $sphaerenAktiv ? Sphere::cases() : null,
            'sphaereVorgabe' => $sphaerenAktiv ? $this->sphaereDerKategorie($felder['kategorie'] ?? '') : null,
            'darfLieferantAnlegen' => $this->berechtigungen()->darf(Permission::SupplierManage),
            // The GiroCode page has the unpaid list's right and scope (issue #86).
            'girocodeLink' => $tresor !== null && $document->status !== DocumentStatus::Abgelehnt
                && $this->berechtigungen()->darf(Permission::ReportView) && $this->pruefung->zuUeberweisen($document, $this->berechtigungen()->zugriffsbereich(Permission::ReportView))
                ? '/app/offen/rechnungen/' . $document->id . '/girocode'
                : null,
            'position' => $position === false ? null : $position + 1,
            'anzahl' => count($ids),
            'naechster' => Pruefung::naechsterIn($ids, $document->id),
            'steuernMax' => Pruefung::STEUERN_MAX,
            'duplikat' => $this->duplikate->verdacht($document, $this->bereich()),
            'duplikatBasis' => '/app/belege/pruefen/' . $document->id,
            'duplikatAufloesbar' => $tresor !== null,
            'eRechnung' => $eRechnung,
            'eRechnungHinweise' => $eRechnungHinweise,
            'archivHinweise' => $archivHinweise,
            'ki' => $ki,
            'unsicher' => array_values(array_unique($unsicher)),
            'kategorieHerkunft' => $kategorieVorschlag?->beschreibung(regelName: $this->berechtigungen()->darf(Permission::BankView)),
            'lieferantHinweis' => $lieferantHinweis,
            'lieferantPruefen' => $lieferantPruefen,
            'lernen' => $bearbeitbar && $lernen !== null && $tresor !== null
                ? [...$lernen, 'stand' => $lernen['merkmale']->fingerabdruck($tresor->blindIndex())]
                : null,
            'kiLaeuft' => $kiOffen && $this->pruefung->kiLaeuft($document),
            'kiAnstossbar' => $kiOffen && $this->pruefung->kiAnstossbar($document),
            'scripts' => ['/js/pruefansicht.js'],
        ], Area::App), $status);
    }

    /**
     * Every active category with its direction, plus the stored one if it
     * has been deactivated since. The page shows the ones of the chosen
     * direction; the service checks it again.
     *
     * @return list<array{id: int, name: string, richtung: string}>
     */
    private function kategorieAuswahl(string $gewaehlt): array
    {
        $auswahl = [];
        foreach ($this->kategorien->all() as $kategorie) {
            if ($kategorie->active || (string) $kategorie->id === $gewaehlt) {
                $auswahl[] = [
                    'id' => $kategorie->id,
                    'name' => $kategorie->name . ($kategorie->active ? '' : ' (inaktiv)'),
                    'richtung' => $kategorie->direction->value,
                ];
            }
        }

        return $auswahl;
    }

    /** The default sphere of the chosen category, as its label - for the hint under the field. */
    private function sphaereDerKategorie(string $kategorie): ?string
    {
        if ($kategorie === '' || !ctype_digit($kategorie)) {
            return null;
        }

        return $this->kategorien->find((int) $kategorie)?->defaultSphere?->label();
    }

    private function sphaerenAktiv(): bool
    {
        return $this->sphaeren?->aktiv() ?? false;
    }

    /**
     * @return list<array{id: int, name: string, rolle: string}>
     */
    private function lieferantenAuswahl(Vault $tresor): array
    {
        return array_map(
            static fn(Supplier $s): array => ['id' => $s->id, 'name' => $s->data->name, 'rolle' => $s->role->value],
            $this->pruefung->lieferanten($tresor),
        );
    }

    /**
     * @return array<int, string> the active ones, plus the chosen one if it
     *         has been deactivated since
     */
    private function kostenstellenAuswahl(string $gewaehlt): array
    {
        $auswahl = $this->kostenstellen->active();
        $alle = $this->kostenstellenNamen();
        if ($gewaehlt !== '' && ctype_digit($gewaehlt) && !isset($auswahl[(int) $gewaehlt]) && isset($alle[(int) $gewaehlt])) {
            $auswahl[(int) $gewaehlt] = $alle[(int) $gewaehlt] . ' (inaktiv)';
        }

        return $auswahl;
    }

    /**
     * @return array<int, string>
     */
    private function kostenstellenNamen(): array
    {
        $namen = [];
        foreach ($this->kostenstellen->all() as $kostenstelle) {
            $namen[$kostenstelle->id] = $kostenstelle->name;
        }

        return $namen;
    }

    /**
     * @return array<string, string>
     */
    private static function felder(Request $request): array
    {
        $felder = [];
        foreach (Pruefung::feldnamen() as $feld) {
            $wert = $request->post[$feld] ?? '';
            $felder[$feld] = is_string($wert) ? $wert : '';
        }

        return $felder;
    }

    private function berechtigungen(): Berechtigungen
    {
        return $this->view->berechtigungen() ?? Berechtigungen::keine();
    }

    private function bereich(): Zugriffsbereich
    {
        return $this->berechtigungen()->zugriffsbereich(Permission::DocumentEdit);
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Beleg nicht gefunden',
            'message' => 'Diesen Beleg gibt es nicht, oder er liegt außerhalb Ihres Bereichs.',
            'startseite' => '/app/belege/pruefen',
        ], Area::App), 404);
    }
}
