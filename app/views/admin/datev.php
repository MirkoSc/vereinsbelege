<?php

/**
 * Admin page of the DATEV export (issue #93, E-49,
 * docs/spec/05-auswertung-und-export.md section 4): advisor and client
 * number, ledger account length, chart of accounts, clearing account, and
 * the ledger account of every category and every bank account / cash box.
 *
 * Only components from /admin/designsystem; the tables are .tabelle-karten
 * so they turn into cards on a phone.
 *
 * @var string $csrf
 * @var \App\Service\Export\DatevEinstellungen $einstellungen
 * @var array<string, mixed> $eingabe the posted form after an error, else empty
 * @var \App\Service\Export\UngueltigeDatevEinstellung|null $fehler
 * @var list<\App\Domain\Category> $kategorien
 * @var list<array{id: int, name: string, art: string, aktiv: bool}> $konten
 */

use App\Service\Export\DatevEinstellungen;

$wert = static function (string $feld, string $gespeichert) use ($eingabe): string {
    if ($eingabe === []) {
        return $gespeichert;
    }

    return is_string($eingabe[$feld] ?? null) ? $eingabe[$feld] : '';
};
$zuordnung = static function (string $gruppe, int $id, string $gespeichert) use ($eingabe): string {
    if ($eingabe === []) {
        return $gespeichert;
    }
    $liste = $eingabe[$gruppe] ?? null;

    return is_array($liste) && is_string($liste[$id] ?? null) ? $liste[$id] : '';
};
$ungueltig = static fn(string $feld): string => $fehler !== null && $fehler->feld === $feld ? ' aria-invalid="true"' : '';
$laenge = $einstellungen->sachkontenlaenge;
$rahmen = $wert('kontenrahmen', $einstellungen->kontenrahmen);
?>
<section class="schmal">
    <h2>DATEV</h2>

    <p class="gedaempft">
        Der DATEV-Export schreibt die Buchungen aller Konten und Kassen als
        Buchungsstapel für den Steuerberater. Dafür braucht jede Kategorie und
        jedes Konto ein Sachkonto aus dem Kontenrahmen, den der Steuerberater
        führt – die Nummern stimmen Sie mit ihm ab.
    </p>
    <p><a href="/app/datev">Zum DATEV-Export</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler->getMessage()) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/datev" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <fieldset>
            <legend>Steuerberater</legend>
            <label for="datev-berater" class="feld-kurz">Beraternummer
                <input type="text" id="datev-berater" name="beraternummer" inputmode="numeric" maxlength="7"
                       value="<?= e($wert('beraternummer', $einstellungen->beraternummer)) ?>" autocomplete="off"<?= $ungueltig('beraternummer') ?>>
            </label>
            <label for="datev-mandant" class="feld-kurz">Mandantennummer
                <input type="text" id="datev-mandant" name="mandantennummer" inputmode="numeric" maxlength="5"
                       value="<?= e($wert('mandantennummer', $einstellungen->mandantennummer)) ?>" autocomplete="off"<?= $ungueltig('mandantennummer') ?>>
            </label>
            <p class="feld-hilfe">Beide Nummern nennt der Steuerberater. Ohne sie nimmt DATEV die Datei nicht an.</p>

            <label for="datev-laenge" class="feld-kurz">Sachkontenlänge
                <input type="number" id="datev-laenge" name="sachkontenlaenge"
                       min="<?= DatevEinstellungen::SACHKONTENLAENGE_MIN ?>" max="<?= DatevEinstellungen::SACHKONTENLAENGE_MAX ?>"
                       value="<?= e($wert('sachkontenlaenge', (string) $laenge)) ?>"<?= $ungueltig('sachkontenlaenge') ?>>
            </label>
            <label for="datev-rahmen" class="feld-kurz">Kontenrahmen
                <select id="datev-rahmen" name="kontenrahmen"<?= $ungueltig('kontenrahmen') ?>>
                    <option value="">keine Angabe</option>
                    <?php foreach (DatevEinstellungen::KONTENRAHMEN as $nummer => $name): ?>
                        <option value="<?= e((string) $nummer) ?>"<?= $rahmen === (string) $nummer ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="feld-hilfe">Meist 4 Stellen. Der Kontenrahmen steht nur als Hinweis in der Datei.</p>
        </fieldset>

        <fieldset>
            <legend>Bankkonten und Kassen</legend>
            <?php if ($konten === []): ?>
                <div class="leer">Noch keine Konten angelegt.</div>
            <?php else: ?>
                <table class="tabelle tabelle-karten">
                    <thead>
                        <tr>
                            <th scope="col">Konto</th>
                            <th scope="col">Sachkonto (Finanzkonto)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($konten as $konto): ?>
                            <?php $feld = 'konto[' . $konto['id'] . ']'; ?>
                            <tr>
                                <th scope="row">
                                    <?= e($konto['name']) ?>
                                    <span class="marke"><?= e($konto['art']) ?></span>
                                    <?php if (!$konto['aktiv']): ?><span class="marke">inaktiv</span><?php endif; ?>
                                </th>
                                <td data-label="Sachkonto">
                                    <input type="text" name="<?= e($feld) ?>" inputmode="numeric" maxlength="<?= DatevEinstellungen::SACHKONTENLAENGE_MAX ?>"
                                           aria-label="Sachkonto für <?= e($konto['name']) ?>" autocomplete="off"
                                           value="<?= e($zuordnung('konto', $konto['id'], $einstellungen->konten[$konto['id']] ?? '')) ?>"<?= $ungueltig($feld) ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend>Kategorien</legend>
            <table class="tabelle tabelle-karten">
                <thead>
                    <tr>
                        <th scope="col">Kategorie</th>
                        <th scope="col">Sachkonto (Gegenkonto)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kategorien as $kategorie): ?>
                        <?php $feld = 'kategorie[' . $kategorie->id . ']'; ?>
                        <tr>
                            <th scope="row">
                                <?= e($kategorie->name) ?>
                                <span class="marke"><?= e($kategorie->direction->label()) ?></span>
                                <?php if (!$kategorie->active): ?><span class="marke">inaktiv</span><?php endif; ?>
                            </th>
                            <td data-label="Sachkonto">
                                <input type="text" name="<?= e($feld) ?>" inputmode="numeric" maxlength="<?= DatevEinstellungen::SACHKONTENLAENGE_MAX ?>"
                                       aria-label="Sachkonto für <?= e($kategorie->name) ?>" autocomplete="off"
                                       value="<?= e($zuordnung('kategorie', $kategorie->id, $einstellungen->kategorien[$kategorie->id] ?? '')) ?>"<?= $ungueltig($feld) ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <label for="datev-klaerung" class="feld-kurz">Klärungskonto
                <input type="text" id="datev-klaerung" name="klaerungskonto" inputmode="numeric" maxlength="<?= DatevEinstellungen::SACHKONTENLAENGE_MAX ?>"
                       value="<?= e($wert('klaerungskonto', $einstellungen->klaerungskonto)) ?>" autocomplete="off"<?= $ungueltig('klaerungskonto') ?>>
            </label>
            <p class="feld-hilfe">
                Optional: Gegenkonto für Buchungen ohne Kategorie. Leer gelassen, müssen
                alle Buchungen eines Exports eine Kategorie haben.
            </p>
        </fieldset>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
        </p>
    </form>
</section>
