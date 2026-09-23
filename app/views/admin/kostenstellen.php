<?php

/**
 * Cost center list (M4-1, issue #23, docs/spec/02-datenmodell.md
 * "Fachdaten").
 *
 * Only components from /admin/designsystem; the table scrolls sideways on
 * narrow screens like every other table. Reordering is two small forms per
 * row (↑/↓) instead of drag-and-drop - no JavaScript needed, and it works
 * the same at 360 px.
 *
 * @var string $csrf
 * @var list<\App\Domain\CostCenter> $kostenstellen in display order
 * @var array<int, int> $anzahl cost center id => number of accounts assigned
 */
?>
<section>
    <h2>Kostenstellen</h2>

    <p class="gedaempft">
        Kostenstellen gliedern Belege und Auswertungen (z. B. „Herren“, „E-Jugend“,
        „Vereinsheim“) und begrenzen, was ein „Vereinsverantwortlicher“ sehen darf.
        Eine deaktivierte Kostenstelle lässt sich nicht mehr neu zuweisen, bestehende
        Zuweisungen und Belege bleiben davon unberührt.
    </p>

    <p class="knopfreihe">
        <a class="knopf knopf-primaer" href="/admin/kostenstellen/neu">Neue Kostenstelle</a>
    </p>

    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption>Alle Kostenstellen</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="zahl">Benutzer</th>
                    <th scope="col">Reihenfolge</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kostenstellen as $i => $kostenstelle): ?>
                    <tr>
                        <td><a href="/admin/kostenstellen/<?= e((string) $kostenstelle->id) ?>"><?= e($kostenstelle->name) ?></a></td>
                        <td>
                            <?php if ($kostenstelle->active): ?>
                                <span class="marke marke-ok">Aktiv</span>
                            <?php else: ?>
                                <span class="marke">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td class="zahl"><?= e((string) ($anzahl[$kostenstelle->id] ?? 0)) ?></td>
                        <td>
                            <p class="knopfreihe">
                                <form method="post" action="/admin/kostenstellen/<?= e((string) $kostenstelle->id) ?>/nach-oben">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf knopf-still" aria-label="„<?= e($kostenstelle->name) ?>“ nach oben"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                                </form>
                                <form method="post" action="/admin/kostenstellen/<?= e((string) $kostenstelle->id) ?>/nach-unten">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf knopf-still" aria-label="„<?= e($kostenstelle->name) ?>“ nach unten"<?= $i === count($kostenstellen) - 1 ? ' disabled' : '' ?>>↓</button>
                                </form>
                            </p>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
