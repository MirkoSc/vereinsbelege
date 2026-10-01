<?php

/**
 * Cash count of a cash box (M9-1, issue #59, docs/spec/
 * 04-bank-und-abgleich.md section 1 "Kassensturz"): enter the counted cash,
 * see the difference to the expected amount, record it.
 *
 * Two submit buttons, no JavaScript: "Differenz berechnen" comes first in
 * the form, so Enter in a field only previews and never stores. The
 * expected amount is computed on the server for the chosen date.
 *
 * @var string $csrf
 * @var \App\Domain\BankAccount $kasse
 * @var array<string, string> $felder datum, ist, notiz
 * @var int $sollHeute expected amount today, in cents
 * @var \DateTimeImmutable $heute
 * @var \App\Service\Bank\KassensturzVorschau|null $vorschau
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 */

use App\Service\Bank\Kassensturz;
use App\Service\Processing\Betrag;

$fehlerAn = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="kassensturz-fehler"' : '';
$wert = static fn(string $feld): string => e($felder[$feld] ?? '');
$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
?>
<section class="schmal">
    <h2>Kassensturz: <?= e($kasse->data->name) ?></h2>

    <p><a href="/app/konten/<?= e((string) $kasse->id) ?>">← Zur Kasse</a></p>

    <p class="gedaempft">
        Zählen Sie das Bargeld in der Kasse und tragen Sie den Bestand ein. Soll-Bestand heute
        (<?= e($heute->format('d.m.Y')) ?>): <strong><?= $betrag($sollHeute) ?></strong> – aus dem Anfangsbestand
        vom <?= e($kasse->openingDate->format('d.m.Y')) ?> und den Kassenbuchungen seitdem.
    </p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="kassensturz-fehler"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if ($vorschau !== null): ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption>Ergebnis zum <?= e($vorschau->datum->format('d.m.Y')) ?> – noch nicht gespeichert</caption>
                <tbody>
                    <tr><th scope="row">Soll-Bestand</th><td class="zahl"><?= $betrag($vorschau->soll) ?></td></tr>
                    <tr><th scope="row">Gezählt</th><td class="zahl"><?= $betrag($vorschau->ist) ?></td></tr>
                    <tr><th scope="row">Differenz</th><td class="zahl"><?= $betrag($vorschau->differenz()) ?></td></tr>
                    <tr>
                        <th scope="row">Ergebnis</th>
                        <td><span class="marke <?= e($vorschau->ergebnis()->marke()) ?>"><?= e($vorschau->ergebnis()->label()) ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post" action="/app/konten/<?= e((string) $kasse->id) ?>/kassensturz" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="kassensturz-datum" class="feld-kurz">Datum <span class="pflicht" aria-hidden="true">*</span>
            <input type="date" id="kassensturz-datum" name="datum" value="<?= $wert('datum') ?>" required
                   min="<?= e($kasse->openingDate->format('Y-m-d')) ?>" max="<?= e($heute->format('Y-m-d')) ?>"<?= $fehlerAn('datum') ?>>
        </label>

        <label for="kassensturz-ist" class="feld-kurz">Gezählter Bestand (€) <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="kassensturz-ist" name="ist" value="<?= $wert('ist') ?>" inputmode="decimal"
                   required<?= $fehlerAn('ist') ?>>
        </label>

        <label for="kassensturz-notiz">Notiz
            <textarea id="kassensturz-notiz" name="notiz" rows="2"
                      maxlength="<?= e((string) Kassensturz::NOTE_MAX) ?>"<?= $fehlerAn('notiz') ?>><?= $wert('notiz') ?></textarea>
        </label>
        <p class="feld-hilfe">Zum Beispiel, wer gezählt hat oder woher eine Differenz kommen könnte.</p>

        <p class="knopfreihe">
            <button type="submit" class="knopf" name="aktion" value="berechnen">Differenz berechnen</button>
            <button type="submit" class="knopf knopf-primaer" name="aktion" value="speichern">Kassensturz speichern</button>
            <a class="knopf knopf-still" href="/app/konten/<?= e((string) $kasse->id) ?>">Abbrechen</a>
        </p>
    </form>
</section>
