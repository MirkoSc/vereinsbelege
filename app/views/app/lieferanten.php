<?php

/**
 * Suppliers and payers (M6-2, issue #36, docs/spec/03-erfassung-und-ki.md
 * section 7): search and role filter, then one row per supplier sorted by
 * name.
 *
 * Only components from /admin/designsystem; the table scrolls sideways on
 * narrow screens like every other table. A plain GET form - no script
 * needed. The search term stays in the URL like the inbox search: it is
 * what the user typed, not stored data, and the page is behind the login.
 *
 * Names and IBANs are vault data: without an unlocked vault there is
 * nothing to show.
 *
 * @var bool $entsperrt
 * @var list<\App\Domain\Supplier> $lieferanten
 * @var \App\Domain\SupplierRole|null $rolle
 * @var string $suche
 * @var array<int, string> $kategorien id => name
 */

use App\Domain\Iban;
use App\Domain\SupplierRole;

?>
<section>
    <h2>Lieferanten &amp; Zahler</h2>

    <p class="gedaempft">
        Stammdaten der Geschäftspartner: Lieferanten schicken Rechnungen, Zahler (Sponsoren, Gemeinde,
        Verband) zahlen an den Verein. IBANs, USt-ID und Aliasse helfen später, Belege und Buchungen
        automatisch zuzuordnen. Eine IBAN oder USt-ID gehört zu genau einem Lieferanten.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Namen und Bankverbindungen sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um Lieferanten zu sehen und zu pflegen.
        </p>
    <?php else: ?>
        <p class="knopfreihe">
            <a class="knopf knopf-primaer" href="/app/lieferanten/neu">Neuer Lieferant</a>
        </p>

        <form method="get" action="/app/lieferanten" class="formular">
            <label for="lieferanten-suche">Suche
                <input type="search" id="lieferanten-suche" name="q" value="<?= e($suche) ?>"
                       aria-describedby="lieferanten-suche-hilfe">
            </label>
            <p class="feld-hilfe" id="lieferanten-suche-hilfe">Name, Alias oder IBAN (auch ein Teil davon).</p>

            <label for="lieferanten-rolle">Rolle
                <select id="lieferanten-rolle" name="rolle">
                    <option value="">Alle</option>
                    <?php foreach (SupplierRole::cases() as $fall): ?>
                        <option value="<?= e($fall->value) ?>"<?= $rolle === $fall ? ' selected' : '' ?>><?= e($fall->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <p class="knopfreihe">
                <button type="submit" class="knopf">Filtern</button>
                <?php if ($suche !== '' || $rolle !== null): ?>
                    <a class="knopf knopf-still" href="/app/lieferanten">Zurücksetzen</a>
                <?php endif; ?>
            </p>
        </form>

        <?php if ($lieferanten === []): ?>
            <div class="leer"><?= $suche !== '' || $rolle !== null ? 'Keine Lieferanten zu diesem Filter.' : 'Noch keine Lieferanten angelegt.' ?></div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e((string) count($lieferanten)) ?> <?= count($lieferanten) === 1 ? 'Eintrag' : 'Einträge' ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Rolle</th>
                            <th scope="col">IBAN</th>
                            <th scope="col">Standard-Kategorie</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lieferanten as $lieferant): ?>
                            <tr>
                                <td>
                                    <a href="/app/lieferanten/<?= e((string) $lieferant->id) ?>"><?= e($lieferant->data->name) ?></a>
                                    <?php if ($lieferant->needsReview): ?>
                                        <span class="marke marke-warnung">prüfen</span>
                                    <?php endif; ?>
                                    <?php if ($lieferant->data->aliases !== []): ?>
                                        <br><span class="gedaempft">auch: <?= e(implode(', ', $lieferant->data->aliases)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($lieferant->role->label()) ?></td>
                                <td>
                                    <?php if ($lieferant->data->ibans === []): ?>
                                        <span class="gedaempft">–</span>
                                    <?php else: ?>
                                        <?= e(Iban::formatieren($lieferant->data->ibans[0])) ?>
                                        <?php if (count($lieferant->data->ibans) > 1): ?>
                                            <span class="gedaempft">(+<?= e((string) (count($lieferant->data->ibans) - 1)) ?>)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $lieferant->defaultCategoryId === null ? '<span class="gedaempft">–</span>' : e($kategorien[$lieferant->defaultCategoryId] ?? '?') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
