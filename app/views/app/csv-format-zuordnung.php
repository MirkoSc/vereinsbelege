<?php

/**
 * The swappable part of the CSV mapping assistant (M9-3, issue #61,
 * docs/spec/04-bank-und-abgleich.md section 3): what was detected, the file
 * format, the column mapping and the preview of the first rows.
 *
 * Rendered inside app/views/app/csv-format-assistent.php on a full page
 * load and on its own (View::fragment()) as the htmx answer to every
 * change of the form - the form wraps it, so every select here is sent
 * with the next change. No JavaScript of ours, no hx-on (CLAUDE.md
 * section 4).
 *
 * The preview shows cell contents of the uploaded file to the person who
 * uploaded it - in this response only, nowhere stored.
 *
 * @var bool $datei a file came with the request
 * @var string|null $kennung fingerprint of that file (keeps the person's choices for the same file)
 * @var string|null $fehler upload or format error
 * @var \App\Service\Bank\Csv\CsvErkennung|null $erkannt set when detection ran for a new file
 * @var \App\Service\Bank\Csv\CsvTrennzeichen $trennzeichen
 * @var \App\Service\Bank\Csv\CsvZeichensatz $zeichensatz
 * @var \App\Service\Bank\Csv\CsvDatumsformat $datumsformat
 * @var \App\Service\Bank\Csv\CsvDezimaltrenner $dezimaltrenner
 * @var array<string, list<string>> $zuordnung
 * @var list<string> $spalten column names to offer
 * @var int|null $kopfzeile
 * @var \App\Service\Bank\Csv\CsvProfilUngueltig|null $profilFehler
 * @var \App\Service\Bank\Csv\CsvErgebnis|null $ergebnis
 * @var string|null $vorschauFehler
 * @var list<array{zeile: int, buchung: ?\App\Service\Bank\Csv\CsvBuchung, fehler: ?string}> $zeilen
 * @var \App\Service\Bank\Csv\CsvProfil|null $bekannt an existing profile that already reads this file
 * @var array{text: string, feld: string}|null $speicherFehler only on the full page
 */

use App\Service\Bank\Csv\CsvDatumsformat;
use App\Service\Bank\Csv\CsvDezimaltrenner;
use App\Service\Bank\Csv\CsvFeld;
use App\Service\Bank\Csv\CsvTrennzeichen;
use App\Service\Bank\Csv\CsvZeichensatz;
use App\Service\Processing\Betrag;

$speicherFehler ??= null;
$fehlerFeld = $speicherFehler['feld'] ?? $profilFehler?->feld;
$markiert = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true"' : '';
$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$betrag = static fn(int $cent): string => e(Betrag::format($cent));
$mitSaldo = ($zuordnung[CsvFeld::Saldo->value] ?? []) !== [];
$formate = [
    'trennzeichen' => ['Trennzeichen', CsvTrennzeichen::cases(), $trennzeichen],
    'zeichensatz' => ['Zeichensatz', CsvZeichensatz::cases(), $zeichensatz],
    'datumsformat' => ['Datumsformat', CsvDatumsformat::cases(), $datumsformat],
    'dezimaltrenner' => ['Zahlenformat', CsvDezimaltrenner::cases(), $dezimaltrenner],
];
?>
<?php if ($kennung !== null): ?>
    <input type="hidden" name="datei_kennung" value="<?= e($kennung) ?>">
<?php endif; ?>

<?php if ($fehler !== null): ?>
    <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
<?php endif; ?>

<?php if (!$datei && $spalten === []): ?>
    <?php if ($fehler === null): ?>
        <p class="hinweis hinweis-info">
            Wählen Sie oben einen CSV-Export Ihrer Bank. Trennzeichen, Zeichensatz, Datums- und Zahlenformat
            werden erkannt; die Spalten ordnen Sie danach zu.
        </p>
    <?php endif; ?>
<?php else: ?>
    <?php if ($erkannt !== null): ?>
        <p class="hinweis hinweis-info">
            Erkannt: <?= e($erkannt->zeichensatz->label()) ?>, getrennt durch <?= e($erkannt->trennzeichen->label()) ?>,
            Kopfzeile in Zeile <?= e((string) $erkannt->kopfzeile) ?>.
            Bitte prüfen Sie die Zuordnung der Spalten.
        </p>
    <?php endif; ?>
    <?php if ($bekannt !== null): ?>
        <p class="hinweis hinweis-ok">
            Diese Datei liest bereits das Format
            <a href="/app/konten/csv-formate/<?= e((string) $bekannt->id) ?>">„<?= e($bekannt->name) ?>“</a>.
            Ein eigenes Format brauchen Sie dafür nur, wenn dessen Vorschau nicht stimmt.
        </p>
    <?php endif; ?>

    <fieldset>
        <legend>Dateiformat</legend>
        <?php foreach ($formate as $name => [$label, $faelle, $aktuell]): ?>
            <label for="csv-<?= e($name) ?>"><?= e($label) ?>
                <select id="csv-<?= e($name) ?>" name="<?= e($name) ?>">
                    <?php foreach ($faelle as $fall): ?>
                        <option value="<?= e($fall->value) ?>"<?= $gewaehlt($fall === $aktuell) ?>><?= e($fall->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Spalten zuordnen</legend>
        <p class="feld-hilfe">
            Pflicht sind der Buchungstag und der Betrag – entweder eine Spalte mit Vorzeichen oder
            getrennte Spalten für Soll (Ausgang) und Haben (Eingang). Alles andere hilft später beim Abgleich.
        </p>
        <?php if ($profilFehler !== null): ?>
            <p class="feld-fehler" id="csv-zuordnung-fehler"><?= e($profilFehler->getMessage()) ?></p>
        <?php endif; ?>
        <?php foreach (CsvFeld::cases() as $feld): ?>
            <?php $auswahl = $zuordnung[$feld->value] ?? []; ?>
            <?php if ($feld === CsvFeld::Verwendungszweck): ?>
                <fieldset>
                    <legend><?= e($feld->label()) ?></legend>
                    <p class="feld-hilfe">Mehrere Spalten werden in dieser Reihenfolge mit Leerzeichen verbunden.</p>
                    <?php foreach ($spalten as $spalte): ?>
                        <label class="feld-ankreuz">
                            <input type="checkbox" name="zuordnung[<?= e($feld->value) ?>][]" value="<?= e($spalte) ?>"<?= in_array($spalte, $auswahl, true) ? ' checked' : '' ?>>
                            <?= e($spalte) ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php else: ?>
                <label for="csv-feld-<?= e($feld->value) ?>"><?= e($feld->label()) ?>
                    <select id="csv-feld-<?= e($feld->value) ?>" name="zuordnung[<?= e($feld->value) ?>][]"<?= $markiert($feld->value) ?>>
                        <option value="">– keine Spalte –</option>
                        <?php foreach ($spalten as $spalte): ?>
                            <option value="<?= e($spalte) ?>"<?= $gewaehlt(($auswahl[0] ?? null) === $spalte) ?>><?= e($spalte) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        <?php endforeach; ?>
    </fieldset>

    <h3>Vorschau</h3>
    <?php if (!$datei): ?>
        <p class="gedaempft">Für eine Vorschau oben eine Beispieldatei wählen.</p>
    <?php elseif ($profilFehler !== null): ?>
        <p class="hinweis hinweis-warnung">Die Vorschau erscheint, sobald die Pflichtspalten zugeordnet sind.</p>
    <?php elseif ($vorschauFehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($vorschauFehler) ?></p>
    <?php elseif ($ergebnis !== null): ?>
        <?php
        $zeitraum = $ergebnis->zeitraum();
        $teile = [count($ergebnis->buchungen) === 1 ? '1 Buchung lesbar' : count($ergebnis->buchungen) . ' Buchungen lesbar'];
        if ($ergebnis->fehler !== []) {
            $teile[] = count($ergebnis->fehler) === 1 ? '1 Zeile nicht lesbar' : count($ergebnis->fehler) . ' Zeilen nicht lesbar';
        }
        if ($ergebnis->vorgemerkt > 0) {
            $teile[] = $ergebnis->vorgemerkt . ' vorgemerkt (nicht gebucht, werden übersprungen)';
        }
        ?>
        <p class="<?= $ergebnis->fehler === [] ? 'hinweis hinweis-ok' : 'hinweis hinweis-warnung' ?>">
            <?= e(implode(', ', $teile)) ?>.
            <?php if ($zeitraum !== null): ?>
                Zeitraum <?= e($zeitraum[0]->format('d.m.Y')) ?> bis <?= e($zeitraum[1]->format('d.m.Y')) ?>.
            <?php endif; ?>
            Kopfzeile in Zeile <?= e((string) $ergebnis->kopfzeile) ?>.
        </p>
        <?php if ($zeilen !== []): ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption>Die ersten <?= e((string) count($zeilen)) ?> Zeilen, wie der Import sie liest</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="zahl">Zeile</th>
                            <th scope="col">Buchungstag</th>
                            <th scope="col">Valuta</th>
                            <th scope="col" class="zahl">Betrag</th>
                            <th scope="col">Gegenseite</th>
                            <th scope="col">Verwendungszweck</th>
                            <?php if ($mitSaldo): ?>
                                <th scope="col" class="zahl">Saldo</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zeilen as $zeile): ?>
                            <tr>
                                <td class="zahl"><?= e((string) $zeile['zeile']) ?></td>
                                <?php if ($zeile['buchung'] === null): ?>
                                    <td colspan="<?= $mitSaldo ? '6' : '5' ?>"><span class="marke marke-fehler">nicht lesbar</span> <?= e((string) $zeile['fehler']) ?></td>
                                <?php else: ?>
                                    <?php $umsatz = $zeile['buchung']->umsatz; $details = $umsatz->details; ?>
                                    <td><?= e($umsatz->buchungsdatum->format('d.m.Y')) ?></td>
                                    <td><?= e($umsatz->valuta->format('d.m.Y')) ?></td>
                                    <td class="zahl"><?= $betrag($umsatz->cent) ?> <?= e($zeile['buchung']->waehrung) ?></td>
                                    <td>
                                        <?= ($details?->name ?? '') === '' ? '<span class="gedaempft">–</span>' : e((string) $details?->name) ?>
                                        <?php if (($details?->iban ?? '') !== ''): ?>
                                            <br><span class="gedaempft klein"><?= e((string) $details?->iban) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= ($details?->verwendungszweck ?? '') === '' ? '<span class="gedaempft">–</span>' : e((string) $details?->verwendungszweck) ?></td>
                                    <?php if ($mitSaldo): ?>
                                        <td class="zahl"><?= $zeile['buchung']->saldoCent === null ? '<span class="gedaempft">–</span>' : $betrag($zeile['buchung']->saldoCent) ?></td>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
