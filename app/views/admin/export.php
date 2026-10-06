<?php

/**
 * Admin page for the ZIP export's folder and file name pattern (issue
 * #75/M12-1, docs/spec/05-auswertung-und-export.md section 2): the pattern,
 * the bundled patterns, the placeholders, and a preview from made-up
 * receipts.
 *
 * Only components from /admin/designsystem, no page-specific CSS/JS - the
 * preview is a second submit button with its own formaction, the tables are
 * .tabelle-karten so long paths wrap on a phone instead of scrolling.
 *
 * @var string $csrf
 * @var string $muster
 * @var string $gespeichertesMuster
 * @var bool $istVorschau
 * @var ?string $fehler
 * @var list<string> $pfade
 */

use App\Service\Export\PfadMuster;

?>
<section class="schmal">
    <h2>Export</h2>

    <p class="gedaempft">
        Der ZIP-Export legt jeden Beleg in einen Ordner, den das Muster
        bestimmt – Ebenen werden mit „/“ getrennt, die letzte Ebene ist der
        Dateiname. Gleiche Namen bekommen „(2)“, „(3)“ … angehängt; Zeichen,
        die Windows in Dateinamen verbietet, werden zu „-“. Die Endung
        (meist <code>.pdf</code>) ergibt sich aus der Datei.
    </p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/export" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="export-muster">Muster
            <input type="text" id="export-muster" name="muster" value="<?= e($muster) ?>"
                   maxlength="<?= PfadMuster::MAX_LAENGE ?>" spellcheck="false" autocomplete="off"
                   <?= $fehler !== null ? 'aria-invalid="true"' : '' ?>>
        </label>
        <p class="feld-hilfe">
            Gespeichert: <code><?= e($gespeichertesMuster) ?></code>
        </p>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Muster speichern</button>
            <button type="submit" class="knopf" formaction="/admin/export/vorschau">Vorschau</button>
        </p>
    </form>

    <?php if ($pfade !== []): ?>
        <h3>Beispiel<?= $istVorschau ? ' – Vorschau, noch nicht gespeichert' : '' ?></h3>
        <p class="gedaempft">Erfundene Belege, so wie sie im ZIP landen würden:</p>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <thead>
                    <tr><th scope="col">Pfad im ZIP</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($pfade as $pfad): ?>
                        <tr><td><code><?= e($pfad) ?></code></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3>Vorlagen</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle tabelle-karten">
            <thead>
                <tr><th scope="col">Vorlage</th><th scope="col">Muster</th><th scope="col"><span class="gedaempft">Aktion</span></th></tr>
            </thead>
            <tbody>
                <?php foreach (PfadMuster::VORLAGEN as $bezeichnung => $vorlage): ?>
                    <tr>
                        <th scope="row" data-label="Vorlage"><?= e($bezeichnung) ?></th>
                        <td data-label="Muster"><code><?= e($vorlage) ?></code></td>
                        <td>
                            <?php if ($vorlage === $gespeichertesMuster): ?>
                                <span class="marke marke-ok">Aktiv</span>
                            <?php else: ?>
                                <form method="post" action="/admin/export">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="muster" value="<?= e($vorlage) ?>">
                                    <button type="submit" class="knopf knopf-still">Übernehmen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3>Platzhalter</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle tabelle-karten">
            <thead>
                <tr><th scope="col">Platzhalter</th><th scope="col">Wird zu</th></tr>
            </thead>
            <tbody>
                <?php foreach (PfadMuster::PLATZHALTER as $name => $beschreibung): ?>
                    <tr>
                        <th scope="row" data-label="Platzhalter"><code>{<?= e($name) ?>}</code></th>
                        <td data-label="Wird zu"><?= e($beschreibung) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
