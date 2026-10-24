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
 * @var bool $pruefen only the suppliers the AI created, still to review (issue #48/M7-6)
 * @var int $zuPruefen how many those are
 * @var array<int, string> $kategorien id => name
 */

use App\Domain\Iban;
use App\Domain\SupplierRole;

?>
<section>
    <h2><?= $pruefen ? 'Neue Lieferanten prüfen' : 'Lieferanten &amp; Zahler' ?></h2>

    <?php if ($pruefen): ?>
        <p><a href="/app/lieferanten">← Alle Lieferanten</a></p>
        <p class="gedaempft">
            Diese Lieferanten und Zahler hat die KI beim Auslesen von Belegen neu angelegt, weil sie keinen
            vorhandenen fand. Bitte je Eintrag prüfen: Angaben korrigieren, als geprüft markieren – oder, wenn
            es ihn schon gibt, mit dem vorhandenen zusammenführen.
        </p>
    <?php elseif ($zuPruefen > 0): ?>
        <p class="hinweis hinweis-warnung">
            <?= e((string) $zuPruefen) ?> <?= $zuPruefen === 1 ? 'Lieferant wurde' : 'Lieferanten wurden' ?> von der KI neu angelegt und
            <?= $zuPruefen === 1 ? 'wartet' : 'warten' ?> auf eine Prüfung.
            <a href="/app/lieferanten?pruefen=1">Neue Lieferanten prüfen</a>
        </p>
    <?php endif; ?>

    <p class="gedaempft"<?= $pruefen ? ' hidden' : '' ?>>
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
            <?php if ($pruefen): ?>
                <input type="hidden" name="pruefen" value="1">
            <?php endif; ?>
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
                    <a class="knopf knopf-still" href="/app/lieferanten<?= $pruefen ? '?pruefen=1' : '' ?>">Zurücksetzen</a>
                <?php endif; ?>
            </p>
        </form>

        <?php if ($lieferanten === []): ?>
            <div class="leer"><?= match (true) {
                $suche !== '' || $rolle !== null => 'Keine Lieferanten zu diesem Filter.',
                $pruefen => 'Keine neuen Lieferanten zu prüfen.',
                default => 'Noch keine Lieferanten angelegt.',
            } ?></div>
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
                            <?php if ($pruefen): ?>
                                <th scope="col">Angelegt</th>
                                <th scope="col">Aktion</th>
                            <?php endif; ?>
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
                                <?php if ($pruefen): ?>
                                    <td><?= e($lieferant->createdAt->format('d.m.Y')) ?></td>
                                    <td>
                                        <form method="post" action="/app/lieferanten/<?= e((string) $lieferant->id) ?>/geprueft">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="zurueck" value="pruefen">
                                            <button type="submit" class="knopf">Als geprüft markieren</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
