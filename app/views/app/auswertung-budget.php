<?php

/**
 * Budgets per category and cost center with Soll/Ist (issue #92, docs/spec/
 * 05-auswertung-und-export.md section 1 "Stand #92", E-58): one table per
 * fiscal year - Soll, Ist, Abweichung absolute and in percent, a bar for
 * how much is used. Every figure is text; the bar and the mark only repeat
 * it. With `bank.book` the form below keeps the budgets.
 *
 * @var int $jahr
 * @var int|null $vorjahr
 * @var int|null $folgejahr
 * @var \App\Service\Report\Grundlage $grundlage
 * @var bool $entsperrt
 * @var \App\Service\Report\Budgetergebnis|null $ergebnis null without an unlocked vault
 * @var bool $pflegen the account may keep budgets (and the vault is unlocked)
 * @var array{kategorien: array<int, string>, kostenstellen: array<int, string>} $auswahl
 * @var bool $csvErlaubt
 * @var string $csrf
 * @var array<string, string> $felder the form as sent after an error
 * @var \App\Service\Report\BudgetFehler|null $fehler
 */

use App\App\BudgetController;
use App\Service\Processing\Betrag;
use App\Service\Report\Grundlage;

$geld = static fn(int $cent): string => Betrag::format($cent) . ' €';
$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
$fehlerAn = static fn(string $feld): string => $fehler !== null && $fehler->feld === $feld ? ' aria-invalid="true" aria-describedby="budget-fehler"' : '';
$parameter = ['jahr' => (string) $jahr, 'grundlage' => $grundlage->value];
$aktiv = '/app/auswertung/budget';
?>
<section>
    <h2>Budget <?= e((string) $jahr) ?></h2>
    <?php require __DIR__ . '/../partials/auswertung-reiter.php'; ?>

    <nav class="knopfreihe nicht-drucken" aria-label="Geschäftsjahr wählen">
        <?php if ($vorjahr !== null): ?>
            <a class="knopf knopf-still" href="/app/auswertung/budget?<?= e(http_build_query([...$parameter, 'jahr' => (string) $vorjahr])) ?>" rel="prev">‹ <?= e((string) $vorjahr) ?></a>
        <?php endif; ?>
        <?php if ($folgejahr !== null): ?>
            <a class="knopf knopf-still" href="/app/auswertung/budget?<?= e(http_build_query([...$parameter, 'jahr' => (string) $folgejahr])) ?>" rel="next"><?= e((string) $folgejahr) ?> ›</a>
        <?php endif; ?>
    </nav>

    <form method="get" action="/app/auswertung/budget" class="formular nicht-drucken" aria-label="Grundlage wählen">
        <input type="hidden" name="jahr" value="<?= e((string) $jahr) ?>">
        <label for="budget-grundlage">Ist nach
            <select id="budget-grundlage" name="grundlage">
                <?php foreach (Grundlage::cases() as $g): ?>
                    <option value="<?= e($g->value) ?>"<?= $gewaehlt($grundlage === $g) ?>><?= e($g->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="knopfreihe">
            <button type="submit" class="knopf">Ansicht wechseln</button>
        </p>
    </form>
</section>

<?php if (!$entsperrt): ?>
    <p class="hinweis hinweis-info">
        Budgets und Beträge sind verschlüsselt und nur mit entsperrtem Tresor lesbar. Melden Sie sich neu an, um sie zu sehen.
    </p>
<?php elseif ($ergebnis !== null && $ergebnis->sichtbar === null): ?>
    <div class="leer">Dieses Jahr liegt außerhalb des für Sie freigegebenen Zeitraums.</div>
<?php elseif ($ergebnis !== null && $ergebnis->sichtbar !== null): ?>
    <section aria-labelledby="budget-tabelle">
        <h3 id="budget-tabelle">Soll und Ist <?= e((string) $jahr) ?></h3>
        <p class="gedaempft">
            Ist = Ausgaben, Grundlage <?= e($grundlage->label()) ?>,
            vom <?= e($ergebnis->sichtbar->von->format('d.m.Y')) ?> bis <?= e($ergebnis->sichtbar->bis->format('d.m.Y')) ?><?= $grundlage === Grundlage::Buchungen ? ', ohne Umbuchungen zwischen eigenen Konten' : ', nur geprüfte Belege' ?>.
            Gutschriften und Erstattungen mindern das Ist. Abweichung = Soll − Ist: positiv ist noch verfügbar, negativ überschritten.
            Budgets können sich überschneiden (z. B. eine Kategorie insgesamt und dieselbe Kategorie für eine Kostenstelle); deshalb gibt es keine Gesamtsumme.
        </p>
        <?php if ($ergebnis->teiljahr()): ?>
            <p class="hinweis hinweis-info">Das Ist umfasst nur den für Sie freigegebenen Teil des Jahres; das Soll gilt für das ganze Jahr.</p>
        <?php endif; ?>
        <?php if ($ergebnis->budgetsUnlesbar > 0): ?>
            <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s nicht aufgeführt.', $ergebnis->budgetsUnlesbar, $ergebnis->budgetsUnlesbar === 1 ? 'Budget ließ sich' : 'Budgets ließen sich', $ergebnis->budgetsUnlesbar === 1 ? 'ist' : 'sind')) ?></p>
        <?php endif; ?>
        <?php if ($ergebnis->unlesbar > 0): ?>
            <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s im Ist nicht mitgezählt.', $ergebnis->unlesbar, $ergebnis->unlesbar === 1 ? 'Eintrag ließ sich' : 'Einträge ließen sich', $ergebnis->unlesbar === 1 ? 'ist' : 'sind')) ?></p>
        <?php endif; ?>
        <?php if ($ergebnis->fremdwaehrung > 0): ?>
            <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s in einer anderen Währung als Euro %s im Ist nicht mitgezählt.', $ergebnis->fremdwaehrung, $ergebnis->fremdwaehrung === 1 ? 'Eintrag' : 'Einträge', $ergebnis->fremdwaehrung === 1 ? 'ist' : 'sind')) ?></p>
        <?php endif; ?>

        <?php if ($ergebnis->zeilen === []): ?>
            <div class="leer">Für <?= e((string) $jahr) ?> sind keine Budgets angelegt<?= $pflegen ? '' : ', die Sie sehen dürfen' ?>.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten geldfluss budget">
                    <thead>
                        <tr>
                            <th scope="col">Kategorie / Kostenstelle</th>
                            <th scope="col" class="geldfluss-balken-spalte"><span class="visuell-versteckt">Ausgeschöpft als Balken</span></th>
                            <th scope="col" class="zahl">Soll</th>
                            <th scope="col" class="zahl">Ist</th>
                            <th scope="col" class="zahl">Abweichung</th>
                            <th scope="col" class="zahl">Abweichung %</th>
                            <th scope="col" class="zahl">Ausgeschöpft</th>
                            <?php if ($pflegen): ?>
                                <th scope="col"><span class="visuell-versteckt">Aktion</span></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ergebnis->zeilen as $zeile): ?>
                            <tr>
                                <th scope="row">
                                    <?= e($ergebnis->kategorie($zeile)) ?> · <?= e($ergebnis->kostenstelle($zeile)) ?>
                                    <?php if ($zeile->ueberschritten()): ?>
                                        <span class="marke marke-fehler">überschritten</span>
                                    <?php endif; ?>
                                </th>
                                <td class="geldfluss-balken-spalte">
                                    <svg class="balken" viewBox="0 0 1000 10" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="balken-wert" width="<?= e((string) $zeile->balkenPromille()) ?>" height="10"/></svg>
                                </td>
                                <td class="zahl" data-label="Soll"><?= e($geld($zeile->soll->betrag)) ?></td>
                                <td class="zahl" data-label="Ist"><?= e($geld($zeile->ist)) ?></td>
                                <td class="zahl" data-label="Abweichung"><?= e($geld($zeile->abweichung())) ?></td>
                                <td class="zahl" data-label="Abweichung %"><?= e(BudgetController::prozent($zeile->abweichungPromille)) ?></td>
                                <td class="zahl" data-label="Ausgeschöpft"><?= e(BudgetController::prozent($zeile->ausschoepfungPromille)) ?></td>
                                <?php if ($pflegen): ?>
                                    <td>
                                        <form method="post" action="/app/auswertung/budget/<?= e((string) $zeile->soll->id) ?>/loeschen">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="jahr" value="<?= e((string) $jahr) ?>">
                                            <button type="submit" class="knopf knopf-gefahr">Löschen</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($csvErlaubt): ?>
                <p class="knopfreihe nicht-drucken">
                    <a class="knopf knopf-still" href="/app/auswertung/budget.csv?<?= e(http_build_query($parameter)) ?>">Als CSV herunterladen</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($pflegen): ?>
    <section class="schmal nicht-drucken" aria-labelledby="budget-pflegen">
        <h3 id="budget-pflegen">Budget für <?= e((string) $jahr) ?> anlegen oder ändern</h3>
        <p class="gedaempft">
            Wählen Sie eine Kategorie, eine Kostenstelle oder beides. Gibt es für diese Auswahl schon ein Budget, wird sein Betrag ersetzt.
        </p>
        <?php if ($fehler !== null): ?>
            <p class="hinweis hinweis-fehler" role="alert" id="budget-fehler"><?= e($fehler->getMessage()) ?></p>
        <?php endif; ?>
        <form method="post" action="/app/auswertung/budget" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="jahr" value="<?= e((string) $jahr) ?>">

            <label for="budget-kategorie">Kategorie
                <select id="budget-kategorie" name="kategorie"<?= $fehlerAn('kategorie') ?>>
                    <option value="">alle Kategorien</option>
                    <?php foreach ($auswahl['kategorien'] as $id => $name): ?>
                        <option value="<?= e((string) $id) ?>"<?= $gewaehlt(($felder['kategorie'] ?? '') === (string) $id) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="budget-kostenstelle">Kostenstelle
                <select id="budget-kostenstelle" name="kostenstelle"<?= $fehlerAn('kostenstelle') ?>>
                    <option value="">alle Kostenstellen</option>
                    <?php foreach ($auswahl['kostenstellen'] as $id => $name): ?>
                        <option value="<?= e((string) $id) ?>"<?= $gewaehlt(($felder['kostenstelle'] ?? '') === (string) $id) ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="budget-betrag" class="feld-kurz">Budget (€) <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="budget-betrag" name="betrag" value="<?= e($felder['betrag'] ?? '') ?>" inputmode="decimal" required<?= $fehlerAn('betrag') ?>>
            </label>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Budget speichern</button>
            </p>
        </form>
    </section>
<?php endif; ?>
