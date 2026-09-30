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
 * @var \App\Domain\InboxItem $eintrag
 * @var bool $entsperrt
 * @var bool $bearbeitbar the form may be saved
 * @var bool $ansehbar the form is shown at all (read-only once checked)
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
 * @var int|null $position place in the queue, 1-based
 * @var int $anzahl length of the queue
 * @var int|null $naechster the next document of the queue
 * @var int $steuernMax
 * @var string $csrf
 */

use App\Domain\CategoryDirection;
use App\Domain\DocumentStatus;
use App\Domain\SupplierRole;
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
                ? 'Dieser Beleg ist festgeschrieben und lässt sich nicht mehr ändern.'
                : 'Dieser Beleg ist geprüft. Die Angaben sind nur noch zu lesen.' ?>
        </p>
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
                <label for="pruefen-datum">Belegdatum <span class="pflicht" aria-hidden="true">*</span>
                    <input type="date" id="pruefen-datum" name="datum" value="<?= $wert('datum') ?>"
                           max="<?= e($heute->modify('+1 year')->format('Y-m-d')) ?>" required<?= $fehlerAn('datum') ?>>
                </label>
            </div>

            <label for="pruefen-nummer">Belegnummer
                <input type="text" id="pruefen-nummer" name="nummer" value="<?= $wert('nummer') ?>"
                       maxlength="<?= e((string) Pruefung::NUMMER_MAX) ?>" autocomplete="off" spellcheck="false"<?= $fehlerAn('nummer') ?>>
            </label>

            <fieldset>
                <legend>Beträge</legend>
                <div class="pruefen-zeile">
                    <label for="pruefen-brutto">Brutto <span class="pflicht" aria-hidden="true">*</span>
                        <input type="text" id="pruefen-brutto" name="brutto" value="<?= $wert('brutto') ?>" inputmode="decimal"
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
                <label for="pruefen-lieferant">Auswählen
                    <select id="pruefen-lieferant" name="lieferant"<?= $fehlerAn('lieferant') ?>>
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
                <?php if ($darfLieferantAnlegen && $bearbeitbar): ?>
                    <p class="feld-hilfe">Noch nicht vorhanden? Dann hier neu anlegen – als Lieferant bei einer Ausgabe, als Zahler bei einer Einnahme.</p>
                    <label for="pruefen-lieferant-neu-name">Neu anlegen: Name
                        <input type="text" id="pruefen-lieferant-neu-name" name="lieferant_neu_name" value="<?= $wert('lieferant_neu_name') ?>"
                               maxlength="200" autocomplete="off"<?= $fehlerAn('lieferant_neu_name') ?>>
                    </label>
                    <label for="pruefen-lieferant-neu-iban">IBAN (freiwillig)
                        <input type="text" id="pruefen-lieferant-neu-iban" name="lieferant_neu_iban" value="<?= $wert('lieferant_neu_iban') ?>"
                               maxlength="42" autocomplete="off" spellcheck="false">
                    </label>
                <?php endif; ?>
            </fieldset>

            <label for="pruefen-kategorie">Kategorie
                <select id="pruefen-kategorie" name="kategorie"<?= $fehlerAn('kategorie') ?>>
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
</div>
<?php endif; ?>
