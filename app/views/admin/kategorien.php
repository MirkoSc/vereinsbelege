<?php

/**
 * Category list (M6-1, issue #35, docs/spec/02-datenmodell.md
 * "Kategorien"): one table per direction, in display order.
 *
 * Only components from /admin/designsystem; the tables scroll sideways on
 * narrow screens like every other table. Reordering is two small forms per
 * row (↑/↓) within the direction - no JavaScript needed, and it works the
 * same at 360 px. A <div>, not a <p>, holds them: a <form> inside a <p>
 * closes the paragraph and the buttons stack.
 *
 * @var string $csrf
 * @var array<string, list<\App\Domain\Category>> $gruppen direction value => categories in display order
 */

use App\Domain\CategoryDirection;

?>
<section>
    <h2>Kategorien</h2>

    <p class="gedaempft">
        Kategorien ordnen Belege und Buchungen fachlich ein (z. B. „Platzpflege & Grünanlagen“,
        „Mitgliedsbeiträge“) und sind die Grundlage der Auswertungen. Der KI-Hinweis nennt
        Beispiele, an denen die KI eine Kategorie erkennt. Eine verwendete Kategorie lässt sich
        nicht löschen, nur deaktivieren – dann wird sie nicht mehr angeboten, bestehende
        Zuordnungen bleiben.
    </p>

    <p class="knopfreihe">
        <a class="knopf knopf-primaer" href="/admin/kategorien/neu">Neue Kategorie</a>
    </p>

    <?php foreach (CategoryDirection::cases() as $richtung): ?>
        <?php $kategorien = $gruppen[$richtung->value] ?? []; ?>
        <?php if ($kategorien === [] && $richtung === CategoryDirection::Beide) {
            continue;
        } ?>
        <h3><?= e($richtung->gruppe()) ?></h3>
        <?php if ($kategorien === []): ?>
            <div class="leer">Keine Kategorien vorhanden.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e($richtung->gruppe()) ?> – <?= e((string) count($kategorien)) ?> Kategorien</caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reihenfolge</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kategorien as $i => $kategorie): ?>
                            <tr>
                                <td>
                                    <span class="farbpunkt<?= $kategorie->color === null ? '' : ' ' . e($kategorie->color->cssKlasse()) ?>" aria-hidden="true"></span>
                                    <a href="/admin/kategorien/<?= e((string) $kategorie->id) ?>"><?= e($kategorie->name) ?></a>
                                </td>
                                <td>
                                    <?php if ($kategorie->active): ?>
                                        <span class="marke marke-ok">Aktiv</span>
                                    <?php else: ?>
                                        <span class="marke">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="knopfreihe">
                                        <form method="post" action="/admin/kategorien/<?= e((string) $kategorie->id) ?>/nach-oben">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <button type="submit" class="knopf knopf-still" aria-label="„<?= e($kategorie->name) ?>“ nach oben"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                                        </form>
                                        <form method="post" action="/admin/kategorien/<?= e((string) $kategorie->id) ?>/nach-unten">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <button type="submit" class="knopf knopf-still" aria-label="„<?= e($kategorie->name) ?>“ nach unten"<?= $i === count($kategorien) - 1 ? ' disabled' : '' ?>>↓</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
