<?php

/**
 * The review page (issue #37/M6-3, docs/spec/03-erfassung-und-ki.md
 * section 6 "Prüfansicht"): the receipt on one side, the form on the other
 * - side by side from 64rem, stacked below (the receipt first, in a box of
 * limited height that scrolls on its own, so the form stays reachable at
 * 360 px).
 *
 * Keyboard: the fields come in the order a receipt is read, and the first
 * submit button of the form is "Geprüft, nächster" - Enter in any input
 * triggers exactly that (HTML's implicit submission).
 *
 * Without JavaScript every page shows one below the other and every
 * category and partner is offered, grouped by direction; public/js/
 * pruefansicht.js adds page flipping, zoom and narrows the choices to the
 * chosen direction. The server checks all of it again
 * (App\Service\Invoice\Pruefung).
 *
 * A field the AI filled in unsure (issue #47/M7-5) is yellow and says
 * "KI unsicher" in its label - colour alone would not reach everyone.
 *
 * A suggested category says below the field where it came from - a rule,
 * the supplier, the AI with its confidence (issue #49/M7-7). It stays a
 * suggestion: the field can be changed until the receipt is saved.
 *
 * @var \App\Domain\InboxItem $eintrag
 * @var bool $entsperrt
 * @var bool $bearbeitbar the form may be saved
 * @var bool $ansehbar the form is shown at all (read-only once checked)
 * @var \DateTimeImmutable|null $festgeschriebenAm when the receipt was locked (issue #38/M6-4)
 * @var array{bilder: list<array{blobId: int, titel: string, originalId: ?int}>, pdfs: list<array{blobId: int, titel: string}>} $seiten
 * @var array<string, string> $felder
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 * @var int|null $konfliktId
 * @var list<\App\Domain\InvoiceDirection> $richtungen
 * @var list<\App\Domain\InvoiceType> $belegarten
 * @var list<array{id: int, name: string, richtung: string}> $kategorien
 * @var list<array{id: int, name: string, rolle: string}> $lieferanten
 * @var array<int, string> $kostenstellen
 * @var bool $darfLieferantAnlegen
 * @var string|null $girocodeLink the unpaid expense's GiroCode page (issue #86), null without one
 * @var int|null $position place in the queue, 1-based
 * @var int $anzahl length of the queue
 * @var int|null $naechster the next document of the queue
 * @var int $steuernMax
 * @var \App\Service\Document\DuplikatVerdacht $duplikat suspected duplicate (issue #40/M6-6)
 * @var string $duplikatBasis
 * @var bool $duplikatAufloesbar
 * @var \App\Service\Document\ERechnungAuszug|null $eRechnung the e-invoice, only before a receipt is saved (issue #46/M7-4)
 * @var list<string> $eRechnungHinweise
 * @var list<string> $archivHinweise what the archive import read, and where it differs (issue #77/M12-3)
 * @var \App\Service\Document\KiAuszug|null $ki the AI's suggestion, only before a receipt is saved (issue #47/M7-5)
 * @var list<string> $unsicher form fields the AI filled in unsure (confidence below 0.7)
 * @var string|null $kategorieHerkunft where the suggested category came from - rule, supplier, AI (issue #49/M7-7)
 * @var string $lieferantHinweis how the supplier was resolved - step and value (issue #48/M7-6)
 * @var bool $lieferantPruefen the chosen supplier was created by the AI and is still to review
 * @var array{fuer: int, merkmale: \App\Service\MasterData\Merkmale, an: bool, stand: string}|null $lernen what
 *      confirming the supplier `fuer` teaches it (issue #201/M7-6b), ticked or not, and its fingerprint
 * @var bool $kiLaeuft an AI run for this document is queued or running
 * @var bool $kiAnstossbar the page may offer to ask the AI (again)
 * @var string $csrf
 */

use App\Domain\CategoryDirection;
use App\Domain\DocumentStatus;
use App\Domain\SupplierRole;
use App\Domain\Zahlungsweg;
use App\Service\Invoice\Festschreibung;
use App\Service\Invoice\Pruefung;

$document = $eintrag->document;
$basis = '/app/belege/pruefen/' . $document->id;
$referenz = $eintrag->referenz ?? '#' . $document->id;
$heute = new \DateTimeImmutable('today');
$gesperrt = $bearbeitbar ? '' : ' disabled';

// aria-invalid and the pointer to the message for the field at fault.
$fehlerAn = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="pruefen-fehler"' : '';
$wert = static fn(string $feld): string => e($felder[$feld] ?? '');
$gewaehlt = static fn(string $feld, string $option): string => ($felder[$feld] ?? '') === $option ? ' selected' : '';
$unsicherAn = static fn(string $feld): string => in_array($feld, $unsicher, true) ? ' class="feld-unsicher"' : '';
$unsicherMarke = static fn(string $feld): string => in_array($feld, $unsicher, true) ? ' <span class="marke marke-warnung">KI unsicher</span>' : '';

$kategorieGruppen = [];
foreach ($kategorien as $kategorie) {
    $kategorieGruppen[$kategorie['richtung']][] = $kategorie;
}
$lieferantenGruppen = [];
foreach ($lieferanten as $lieferant) {
    $lieferantenGruppen[$lieferant['rolle']][] = $lieferant;
}
$rollenGruppe = [
    SupplierRole::Lieferant->value => 'Lieferanten',
    SupplierRole::Zahler->value => 'Zahler',
    SupplierRole::Beide->value => 'Lieferanten und Zahler',
];
?>
<section class="pruefen-kopf">
    <p>
        <a href="/app/belege/pruefen">← Alle zu prüfenden Belege</a>
        <?php if ($position !== null): ?>
            <span class="gedaempft">· Beleg <?= e((string) $position) ?> von <?= e((string) $anzahl) ?></span>
        <?php endif; ?>
    </p>

    <h2>Beleg <?= e($referenz) ?> <?php require __DIR__ . '/posteingang-status.php'; ?></h2>

    <?php if ($girocodeLink !== null): ?>
        <p class="hinweis hinweis-info">Diese Rechnung ist noch nicht (ganz) bezahlt. <a href="<?= e($girocodeLink) ?>">GiroCode zum Bezahlen anzeigen</a></p>
    <?php endif; ?>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Belegbilder und Angaben sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um den Beleg zu prüfen.
        </p>
    <?php elseif (!$ansehbar): ?>
        <p class="hinweis hinweis-info">
            Dieser Beleg ist noch nicht zur Prüfung freigegeben (Status „<?= e($document->status->bezeichnung()) ?>“).
            <a href="/app/posteingang/<?= e((string) $document->id) ?>">Im Posteingang öffnen</a>
        </p>
    <?php elseif (!$bearbeitbar): ?>
        <p class="hinweis hinweis-info">
            <?= $document->status === DocumentStatus::Festgeschrieben
                ? 'Dieser Beleg ist festgeschrieben' . ($festgeschriebenAm === null ? '' : ' (seit ' . $festgeschriebenAm->format('d.m.Y') . ')') . ' und lässt sich nicht mehr ändern. Eine Korrektur geht nur über „Festschreibung aufheben“ mit Begründung.'
                : 'Dieser Beleg ist geprüft. Die Angaben sind nur noch zu lesen.' ?>
        </p>
    <?php endif; ?>

    <?php require __DIR__ . '/duplikat-hinweis.php'; ?>

    <?php if (($archivHinweise ?? []) !== [] && $bearbeitbar): ?>
        <div class="hinweis hinweis-info">
            <p>
                Lieferant, Datum und weitere Angaben sind aus dem Archiv vorbelegt (Ordner und Dateiname).
                Bitte prüfen und mit „Geprüft“ bestätigen – gespeichert ist noch nichts.
            </p>
            <ul>
                <?php foreach ($archivHinweise as $hinweis): ?>
                    <li><?= e($hinweis) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($bearbeitbar && $ki !== null && $ki->gueltig()): ?>
        <?php $kiWarnungen = $ki->warnungen(); ?>
        <div class="hinweis <?= $kiWarnungen === [] && $unsicher === [] ? 'hinweis-info' : 'hinweis-warnung' ?>">
            <p>
                Die Angaben sind ein Vorschlag der KI (<?= e($ki->anbieter) ?><?= $ki->modell === '' ? '' : ', ' . e($ki->modell) ?><?= $ki->fallback ? ', Fallback-Profil' : '' ?>).
                Bitte prüfen und mit „Geprüft“ bestätigen – gespeichert ist noch nichts.
                <?php if ($unsicher !== []): ?>
                    Gelb markierte Felder („KI unsicher“) hat die KI nur unsicher gelesen.
                <?php endif; ?>
                <?php if ($kiLaeuft): ?>
                    Ein neuer Durchlauf der KI läuft gerade.
                <?php endif; ?>
            </p>
            <?php if ($kiWarnungen !== []): ?>
                <ul>
                    <?php foreach ($kiWarnungen as $warnung): ?>
                        <li><?= e($warnung) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php elseif ($bearbeitbar && $kiLaeuft): ?>
        <p class="hinweis hinweis-info">
            Die KI liest diesen Beleg gerade aus. Ihr Vorschlag erscheint nach dem Neuladen dieser Seite –
            oder Sie erfassen den Beleg gleich von Hand.
        </p>
    <?php elseif ($bearbeitbar && ($ki !== null || $kiAnstossbar)): ?>
        <div class="hinweis <?= $ki !== null ? 'hinweis-fehler' : 'hinweis-info' ?>">
            <p>
                <?php if ($ki !== null): ?>
                    Die KI konnte den Beleg nicht auslesen: <?= e($ki->meldung) ?>
                    Bitte von Hand erfassen<?= $kiAnstossbar ? ' oder die KI erneut fragen' : '' ?>.
                <?php else: ?>
                    Die KI hat diesen Beleg noch nicht ausgelesen.
                <?php endif; ?>
            </p>
            <?php if ($kiAnstossbar): ?>
                <form method="post" action="<?= e($basis) ?>/ki-erneut">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="knopf"><?= $ki !== null ? 'KI erneut versuchen' : 'Mit KI auslesen' ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($eRechnung !== null && $bearbeitbar): ?>
        <?php if ($eRechnung->gelesen()): ?>
            <div class="hinweis hinweis-info">
                <p>
                    Die Angaben stammen aus der E-Rechnung<?= $eRechnung->syntax === null ? '' : ' (' . e($eRechnung->syntax->label()) . ')' ?>
                    und wurden ohne KI übernommen<?= $ki !== null && $ki->gueltig() ? ' – die KI ergänzt nur, was die E-Rechnung nicht kennt (z. B. Kategorie und Zweck)' : '' ?>.
                    Bitte prüfen und mit „Geprüft“ bestätigen – gespeichert ist noch nichts.
                </p>
                <?php if ($eRechnungHinweise !== []): ?>
                    <ul>
                        <?php foreach ($eRechnungHinweise as $hinweis): ?>
                            <li><?= e($hinweis) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="hinweis hinweis-warnung">
                Der Beleg enthält eine E-Rechnung, die nicht übernommen werden konnte (<?= e($eRechnung->befund->label()) ?>).
                Bitte die Angaben von Hand erfassen.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="pruefen-fehler">
            <?= e($fehler) ?>
            <?php if ($konfliktId !== null): ?>
                <a href="/app/lieferanten/<?= e((string) $konfliktId) ?>">Zum vorhandenen Lieferanten</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>

<?php if ($entsperrt && $ansehbar): ?>
<div class="pruefansicht">
    <section class="beleg-betrachter" aria-label="Beleg">
        <div class="beleg-werkzeuge knopfreihe" hidden>
            <button type="button" class="knopf" data-beleg="zurueck" aria-label="Vorige Seite">‹</button>
            <span class="beleg-seitenzahl" aria-live="polite"></span>
            <button type="button" class="knopf" data-beleg="weiter" aria-label="Nächste Seite">›</button>
            <button type="button" class="knopf" data-beleg="kleiner" aria-label="Verkleinern">−</button>
            <span class="beleg-zoom" aria-live="polite">100 %</span>
            <button type="button" class="knopf" data-beleg="groesser" aria-label="Vergrößern">+</button>
        </div>

        <?php if ($seiten['bilder'] === [] && $seiten['pdfs'] === []): ?>
            <div class="leer">Keine Seiten lesbar.</div>
        <?php else: ?>
            <?php if ($seiten['bilder'] !== []): ?>
                <ol class="beleg-seiten zoom-1">
                    <?php foreach ($seiten['bilder'] as $seite): ?>
                        <?php $url = $basis . '/datei/' . $seite['blobId']; ?>
                        <li class="beleg-seite">
                            <div class="beleg-bildrahmen">
                                <img src="<?= e($url) ?>" alt="<?= e($seite['titel']) ?>">
                            </div>
                            <span class="klein gedaempft">
                                <?= e($seite['titel']) ?> ·
                                <a href="<?= e($url) ?>" target="_blank" rel="noopener">In neuem Tab</a>
                                <?php if ($seite['originalId'] !== null): ?>
                                    · <a href="<?= e($basis . '/datei/' . $seite['originalId']) ?>" target="_blank" rel="noopener">Original</a>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ($seiten['pdfs'] !== []): ?>
                <p class="knopfreihe">
                    <?php foreach ($seiten['pdfs'] as $pdf): ?>
                        <a class="knopf" href="<?= e($basis . '/datei/' . $pdf['blobId']) ?>" target="_blank" rel="noopener"><?= e($pdf['titel']) ?> öffnen</a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <div class="pruefen-spalte">
    <form method="post" action="<?= e($basis) ?>" class="pruefen-formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset class="pruefen-felder"<?= $gesperrt ?>>
            <legend class="visuell-versteckt">Angaben zum Beleg</legend>

            <fieldset class="pruefen-richtung"<?= $fehlerAn('richtung') ?>>
                <legend>Richtung <span class="pflicht" aria-hidden="true">*</span></legend>
                <?php foreach ($richtungen as $richtung): ?>
                    <label class="feld-ankreuz">
                        <input type="radio" name="richtung" value="<?= e($richtung->value) ?>"<?= ($felder['richtung'] ?? '') === $richtung->value ? ' checked' : '' ?>>
                        <?= e($richtung->label()) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="pruefen-zeile">
                <label for="pruefen-belegart">Belegart <span class="pflicht" aria-hidden="true">*</span>
                    <select id="pruefen-belegart" name="belegart"<?= $fehlerAn('belegart') ?>>
                        <?php foreach ($belegarten as $art): ?>
                            <option value="<?= e($art->value) ?>"<?= $gewaehlt('belegart', $art->value) ?>><?= e($art->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label for="pruefen-datum">Belegdatum <span class="pflicht" aria-hidden="true">*</span><?= $unsicherMarke('datum') ?>
                    <input type="date" id="pruefen-datum" name="datum" value="<?= $wert('datum') ?>"<?= $unsicherAn('datum') ?>
                           max="<?= e($heute->modify('+1 year')->format('Y-m-d')) ?>" required<?= $fehlerAn('datum') ?>>
                </label>
            </div>

            <label for="pruefen-zahlungsweg">Zahlungsweg <span class="pflicht" aria-hidden="true">*</span>
                <select id="pruefen-zahlungsweg" name="zahlungsweg"<?= $fehlerAn('zahlungsweg') ?>>
                    <?php foreach (Zahlungsweg::cases() as $weg): ?>
                        <option value="<?= e($weg->value) ?>"<?= $gewaehlt('zahlungsweg', $weg->value) ?>><?= e($weg->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="feld-hilfe">Entscheidet, ob eine Buchung erwartet wird: nur „über Konto oder Kasse gebucht“ und „Erstattung“ werden mit Buchungen abgeglichen.</span>
            </label>

            <label for="pruefen-nummer">Belegnummer
                <input type="text" id="pruefen-nummer" name="nummer" value="<?= $wert('nummer') ?>"
                       maxlength="<?= e((string) Pruefung::NUMMER_MAX) ?>" autocomplete="off" spellcheck="false"<?= $fehlerAn('nummer') ?>>
            </label>

            <fieldset>
                <legend>Beträge</legend>
                <div class="pruefen-zeile">
                    <label for="pruefen-brutto">Brutto <span class="pflicht" aria-hidden="true">*</span><?= $unsicherMarke('brutto') ?>
                        <input type="text" id="pruefen-brutto" name="brutto" value="<?= $wert('brutto') ?>" inputmode="decimal"<?= $unsicherAn('brutto') ?>
                               autocomplete="off" required aria-describedby="pruefen-betrag-hilfe"<?= $fehlerAn('brutto') ?>>
                    </label>
                    <label for="pruefen-waehrung" class="pruefen-waehrung">Währung
                        <input type="text" id="pruefen-waehrung" name="waehrung" value="<?= $wert('waehrung') ?>" maxlength="3"
                               autocomplete="off" spellcheck="false"<?= $fehlerAn('waehrung') ?>>
                    </label>
                </div>
                <p class="feld-hilfe" id="pruefen-betrag-hilfe">
                    Beträge wie 1.234,56 – eine Gutschrift mit Minus. Netto und Steuern sind freiwillig; passen sie
                    nicht zum Brutto, gibt es einen Hinweis.
                </p>
                <label for="pruefen-netto">Netto
                    <input type="text" id="pruefen-netto" name="netto" value="<?= $wert('netto') ?>" inputmode="decimal"
                           autocomplete="off"<?= $fehlerAn('netto') ?>>
                </label>
                <?php for ($i = 1; $i <= $steuernMax; $i++): ?>
                    <div class="pruefen-zeile">
                        <label for="pruefen-steuer-satz-<?= $i ?>" class="pruefen-satz">Satz % (<?= $i ?>)
                            <input type="text" id="pruefen-steuer-satz-<?= $i ?>" name="steuer_satz_<?= $i ?>"
                                   value="<?= $wert('steuer_satz_' . $i) ?>" inputmode="decimal" autocomplete="off"<?= $fehlerAn('steuer_satz_' . $i) ?>>
                        </label>
                        <label for="pruefen-steuer-betrag-<?= $i ?>">Steuer (<?= $i ?>)
                            <input type="text" id="pruefen-steuer-betrag-<?= $i ?>" name="steuer_betrag_<?= $i ?>"
                                   value="<?= $wert('steuer_betrag_' . $i) ?>" inputmode="decimal" autocomplete="off"<?= $fehlerAn('steuer_betrag_' . $i) ?>>
                        </label>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset>
                <legend>Lieferant bzw. Zahler</legend>
                <label for="pruefen-lieferant">Auswählen<?= $unsicherMarke('lieferant') ?>
                    <select id="pruefen-lieferant" name="lieferant"<?= $unsicherAn('lieferant') ?><?= $fehlerAn('lieferant') ?>>
                        <option value="">Keiner</option>
                        <?php foreach ($rollenGruppe as $rolle => $gruppe): ?>
                            <?php if (($lieferantenGruppen[$rolle] ?? []) !== []): ?>
                                <optgroup label="<?= e($gruppe) ?>" data-rolle="<?= e($rolle) ?>">
                                    <?php foreach ($lieferantenGruppen[$rolle] as $lieferant): ?>
                                        <option value="<?= e((string) $lieferant['id']) ?>"<?= $gewaehlt('lieferant', (string) $lieferant['id']) ?>><?= e($lieferant['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (($lieferantHinweis ?? '') !== '' && $bearbeitbar): ?>
                    <p class="feld-hilfe" id="pruefen-lieferant-herkunft">
                        <?= e($lieferantHinweis) ?>
                        <?php if (($lieferantPruefen ?? false) && $darfLieferantAnlegen): ?>
                            <a href="/app/lieferanten?pruefen=1">Neue Lieferanten prüfen</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (($lernen ?? null) !== null):
                    $lernenName = '';
                    foreach ($lieferanten as $lieferant) {
                        if ($lieferant['id'] === $lernen['fuer']) {
                            $lernenName = $lieferant['name'];
                        }
                    }
                    $lernenZeilen = $lernen['merkmale']->beschreibung(); ?>
                    <div class="pruefen-lernen" id="pruefen-lernen" data-lieferant="<?= e((string) $lernen['fuer']) ?>">
                        <input type="hidden" name="lieferant_lernen_fuer" value="<?= e((string) $lernen['fuer']) ?>">
                        <input type="hidden" name="lieferant_lernen_stand" value="<?= e($lernen['stand']) ?>">
                        <?php if ($lernenZeilen !== []): ?>
                            <label class="feld-ankreuz">
                                <input type="checkbox" name="lieferant_lernen" value="1" aria-describedby="pruefen-lernen-hilfe"<?= $lernen['an'] ? ' checked' : '' ?>>
                                Neue Merkmale beim Lieferanten „<?= e($lernenName) ?>“ ergänzen
                            </label>
                            <ul class="pruefen-lernen-liste">
                                <?php foreach ($lernenZeilen as $zeile): ?>
                                    <li><?= e($zeile) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <p class="feld-hilfe" id="pruefen-lernen-hilfe">
                            <?php if ($lernenZeilen !== []): ?>
                                Gilt nur, solange „<?= e($lernenName) ?>“ gewählt bleibt – so wird der nächste Beleg direkt erkannt.
                            <?php endif; ?>
                            <?php foreach ($lernen['merkmale']->hinweise as $hinweis): ?>
                                <?= e($hinweis) ?>
                            <?php endforeach; ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if ($darfLieferantAnlegen && $bearbeitbar): ?>
                    <p class="feld-hilfe">Noch nicht vorhanden? Dann hier neu anlegen – als Lieferant bei einer Ausgabe, als Zahler bei einer Einnahme.</p>
                    <label for="pruefen-lieferant-neu-name">Neu anlegen: Name<?= $unsicherMarke('lieferant_neu_name') ?>
                        <input type="text" id="pruefen-lieferant-neu-name" name="lieferant_neu_name" value="<?= $wert('lieferant_neu_name') ?>"<?= $unsicherAn('lieferant_neu_name') ?>
                               maxlength="200" autocomplete="off"<?= $fehlerAn('lieferant_neu_name') ?>>
                    </label>
                    <label for="pruefen-lieferant-neu-iban">IBAN (freiwillig)
                        <input type="text" id="pruefen-lieferant-neu-iban" name="lieferant_neu_iban" value="<?= $wert('lieferant_neu_iban') ?>"
                               maxlength="42" autocomplete="off" spellcheck="false">
                    </label>
                <?php endif; ?>
            </fieldset>

            <label for="pruefen-kategorie">Kategorie<?= $unsicherMarke('kategorie') ?>
                <select id="pruefen-kategorie" name="kategorie"<?= $unsicherAn('kategorie') ?><?= $fehlerAn('kategorie') ?><?= $kategorieHerkunft !== null && $fehlerFeld !== 'kategorie' ? ' aria-describedby="pruefen-kategorie-herkunft"' : '' ?>>
                    <option value="">Keine</option>
                    <?php foreach (CategoryDirection::cases() as $richtung): ?>
                        <?php if (($kategorieGruppen[$richtung->value] ?? []) !== []): ?>
                            <optgroup label="<?= e($richtung->gruppe()) ?>" data-richtung="<?= e($richtung->value) ?>">
                                <?php foreach ($kategorieGruppen[$richtung->value] as $kategorie): ?>
                                    <option value="<?= e((string) $kategorie['id']) ?>"<?= $gewaehlt('kategorie', (string) $kategorie['id']) ?>><?= e($kategorie['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($kategorieHerkunft !== null): ?>
                <p class="feld-hilfe" id="pruefen-kategorie-herkunft">Vorgeschlagen von: <?= e($kategorieHerkunft) ?></p>
            <?php endif; ?>

            <label for="pruefen-kostenstelle">Kostenstelle (Mannschaft/Bereich)
                <select id="pruefen-kostenstelle" name="kostenstelle"<?= $fehlerAn('kostenstelle') ?>>
                    <option value="">Keine</option>
                    <?php foreach ($kostenstellen as $id => $name): ?>
                        <option value="<?= e((string) $id) ?>"<?= $gewaehlt('kostenstelle', (string) $id) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <fieldset>
                <legend>Weitere Angaben</legend>
                <label for="pruefen-faellig" class="feld-kurz">Fällig am
                    <input type="date" id="pruefen-faellig" name="faellig" value="<?= $wert('faellig') ?>"<?= $fehlerAn('faellig') ?>>
                </label>
                <div class="pruefen-zeile">
                    <label for="pruefen-leistung-von">Leistung von
                        <input type="date" id="pruefen-leistung-von" name="leistung_von" value="<?= $wert('leistung_von') ?>"<?= $fehlerAn('leistung_von') ?>>
                    </label>
                    <label for="pruefen-leistung-bis">bis
                        <input type="date" id="pruefen-leistung-bis" name="leistung_bis" value="<?= $wert('leistung_bis') ?>"<?= $fehlerAn('leistung_bis') ?>>
                    </label>
                </div>
                <label for="pruefen-zweck">Wofür? (kurz)
                    <input type="text" id="pruefen-zweck" name="zweck" value="<?= $wert('zweck') ?>"
                           maxlength="<?= e((string) Pruefung::ZWECK_MAX) ?>"<?= $fehlerAn('zweck') ?>>
                </label>
                <label for="pruefen-notiz">Notiz
                    <textarea id="pruefen-notiz" name="notiz" rows="3"
                              maxlength="<?= e((string) Pruefung::NOTIZ_MAX) ?>"<?= $fehlerAn('notiz') ?>><?= $wert('notiz') ?></textarea>
                </label>
            </fieldset>
        </fieldset>

        <?php if ($bearbeitbar): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer" name="aktion" value="geprueft">Geprüft, nächster</button>
                <button type="submit" class="knopf" name="aktion" value="speichern">Nur speichern</button>
                <?php if ($naechster !== null): ?>
                    <a class="knopf knopf-still" href="/app/belege/pruefen/<?= e((string) $naechster) ?>">Überspringen</a>
                <?php endif; ?>
            </p>
            <p class="feld-hilfe">Enter in einem Feld = „Geprüft, nächster“.</p>
        <?php endif; ?>
    </form>

    <?php if ($document->status === DocumentStatus::Geprueft): ?>
        <form method="post" action="<?= e($basis) ?>/festschreiben" class="formular pruefen-festschreiben">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <fieldset>
                <legend>Festschreiben</legend>
                <p class="gedaempft">
                    Danach sind Beträge, Datum, Lieferant, Kategorie, Kostenstelle und die Belegdateien nicht mehr
                    änderbar. Korrigieren lässt sich nur noch über „Festschreibung aufheben“ mit Begründung – das
                    steht im Protokoll.
                </p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer">Festschreiben</button>
                </p>
            </fieldset>
        </form>
    <?php elseif ($document->status === DocumentStatus::Festgeschrieben): ?>
        <form method="post" action="<?= e($basis) ?>/festschreibung-aufheben" class="formular pruefen-festschreiben">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <fieldset>
                <legend>Festschreibung aufheben</legend>
                <label for="aufheben-grund">Grund <span class="pflicht">*</span>
                    <textarea id="aufheben-grund" name="grund" rows="3" required
                              maxlength="<?= e((string) Festschreibung::GRUND_MAX) ?>"<?= $fehlerAn('grund') ?>></textarea>
                </label>
                <p class="gedaempft klein">
                    Der Beleg ist danach wieder „In Prüfung“ und muss nach der Korrektur erneut geprüft und
                    festgeschrieben werden. Grund und Aufhebung stehen im Protokoll.
                </p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Festschreibung aufheben</button>
                </p>
            </fieldset>
        </form>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>
