<?php

/**
 * Rules for bookings (M9-6, issue #64, docs/spec/04-bank-und-abgleich.md
 * section 5 "Stand M9-6"): every rule, oldest first - the order in which
 * they win -, what it looks for, what it does and on how many bookings,
 * linked to the booking list filtered by the rule. Below the switch whether
 * income needs a receipt by default (E-17).
 *
 * Only components from /admin/designsystem, no JavaScript. The table becomes
 * cards below 48 rem (.tabelle-karten), so nothing scrolls sideways at
 * 360 px.
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var list<\App\Domain\AssignmentRule> $regeln
 * @var array<int, int> $betroffen rule id => bookings it acts on, within the reader's scope
 * @var array<int, \App\Domain\Category> $kategorien
 * @var \App\Service\Bank\BelegStandard $belegStandard
 * @var bool $darfBuchen holds `bank.book`
 */

use App\Domain\BankTransactionDirection;
use App\Domain\Iban;

$leer = '<span class="gedaempft">–</span>';
?>
<section>
    <h2>Buchungsregeln</h2>

    <p><a href="/app/buchungen">← Buchungen</a></p>

    <p class="gedaempft">
        Regeln ordnen Buchungen aus Kontoauszügen automatisch ein, die nie einen Beleg haben werden –
        Zinsen, Kontoführung, Umbuchungen – oder immer dieselbe Kategorie bekommen.
        Sie greifen bei jedem Import; auf bestehende Buchungen lassen sie sich auf der Seite der Regel anwenden.
        Treffen mehrere Regeln zu, gilt die obere. Was von Hand eingeordnet wurde, ändert keine Regel.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Regeln nennen Gegenseiten und Stichwörter und sind deshalb verschlüsselt.
            Melden Sie sich neu an, um sie zu sehen.
        </p>
    <?php else: ?>
        <?php if ($darfBuchen): ?>
            <p class="knopfreihe">
                <a class="knopf knopf-primaer" href="/app/buchungen/regeln/neu">Neue Regel</a>
            </p>
        <?php endif; ?>

        <?php if ($regeln === []): ?>
            <div class="leer">Es gibt noch keine Regel. Am schnellsten entsteht eine aus einer Buchung: dort „Regel daraus machen“.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption><?= e((string) count($regeln)) ?> <?= count($regeln) === 1 ? 'Regel' : 'Regeln' ?>, die obere gewinnt</caption>
                    <thead>
                        <tr>
                            <th scope="col">Regel</th>
                            <th scope="col">Wenn</th>
                            <th scope="col">Dann</th>
                            <th scope="col" class="zahl">Buchungen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regeln as $regel): ?>
                            <?php $anzahl = $betroffen[$regel->id] ?? 0; ?>
                            <tr>
                                <td data-label="Regel">
                                    <a href="/app/buchungen/regeln/<?= e((string) $regel->id) ?>"><?= e($regel->label) ?></a>
                                    <?php if (!$regel->active): ?>
                                        <span class="marke">inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Wenn">
                                    <?php if ($regel->stichwort !== ''): ?>
                                        Zweck enthält „<?= e($regel->stichwort) ?>“<br>
                                    <?php endif; ?>
                                    <?php if ($regel->gegenseite !== ''): ?>
                                        <?= $regel->gegenseiteIstIban() ? 'IBAN der Gegenseite ' . e(Iban::formatieren($regel->gegenseite)) : 'Gegenseite enthält „' . e($regel->gegenseite) . '“' ?><br>
                                    <?php endif; ?>
                                    <span class="gedaempft"><?= match ($regel->direction) {
                                        BankTransactionDirection::Ausgabe => 'nur Ausgaben',
                                        BankTransactionDirection::Einnahme => 'nur Einnahmen',
                                        null => 'Einnahmen und Ausgaben',
                                    } ?></span>
                                </td>
                                <td data-label="Dann">
                                    <?php if ($regel->noReceipt): ?>
                                        <span class="marke">kein Beleg nötig</span>
                                    <?php endif; ?>
                                    <?php if ($regel->categoryId !== null): ?>
                                        <?= isset($kategorien[$regel->categoryId]) ? e($kategorien[$regel->categoryId]->name) : $leer ?>
                                    <?php endif; ?>
                                </td>
                                <td class="zahl tabelle-kurz" data-label="Buchungen">
                                    <?php if ($anzahl === 0): ?>
                                        0
                                    <?php else: ?>
                                        <a href="/app/buchungen?regel=<?= e((string) $regel->id) ?>&amp;von=&amp;bis="><?= e((string) $anzahl) ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="schmal">
    <h3>Einnahmen ohne Beleg</h3>
    <p class="gedaempft">
        Standardmäßig brauchen Einnahmen keinen Beleg, Ausgaben schon. Die Einstellung gilt für neue Buchungen
        und für Buchungen, deren Regel zurückgenommen wird – bestehende Buchungen bleiben, wie sie sind.
    </p>
    <?php if ($darfBuchen): ?>
        <form method="post" action="/app/buchungen/regeln/standard" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <fieldset>
                <legend>Neue Einnahmen</legend>
                <label class="feld-ankreuz">
                    <input type="radio" name="einnahme_beleg_noetig" value="0"<?= $belegStandard->einnahmeBelegNoetig ? '' : ' checked' ?>> brauchen keinen Beleg
                </label>
                <label class="feld-ankreuz">
                    <input type="radio" name="einnahme_beleg_noetig" value="1"<?= $belegStandard->einnahmeBelegNoetig ? ' checked' : '' ?>> brauchen einen Beleg
                </label>
            </fieldset>
            <p class="knopfreihe">
                <button type="submit" class="knopf">Speichern</button>
            </p>
        </form>
    <?php else: ?>
        <p>Neue Einnahmen <?= $belegStandard->einnahmeBelegNoetig ? 'brauchen einen Beleg' : 'brauchen keinen Beleg' ?>.</p>
    <?php endif; ?>
</section>
