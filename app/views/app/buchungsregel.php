<?php

/**
 * One rule for bookings (M9-6, issue #64, docs/spec/04-bank-und-abgleich.md
 * section 5 "Stand M9-6"): the form for a new rule or for changing one for
 * whoever holds `bank.book`, the stored values read-only otherwise. Below,
 * for an existing rule: how many bookings it acts on (linked to the list),
 * "Auf N Buchungen anwenden" for the existing bookings no rule has touched
 * yet, switching it off and deleting it - both take its effect back.
 *
 * Only components from /admin/designsystem, no JavaScript: the server checks
 * that category and direction fit.
 *
 * @var string $csrf
 * @var \App\Domain\AssignmentRule|null $regel null for a new one
 * @var array<string, string> $felder
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 * @var bool $darfBuchen holds `bank.book`
 * @var list<\App\Domain\Category> $kategorieAuswahl active categories, plus the rule's own
 * @var array<int, \App\Domain\Category> $kategorien every category by id
 * @var int $betroffen bookings the rule acts on, within the reader's scope
 * @var int $kandidaten existing bookings it would act on if applied now
 */

use App\Domain\BankTransactionDirection;
use App\Domain\CategoryDirection;
use App\Service\Bank\Buchungsregeln;

$neu = $regel === null;
$ziel = $neu ? '/app/buchungen/regeln' : '/app/buchungen/regeln/' . $regel->id;
$fehlerAn = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="regel-fehler"' : '';
$wert = static fn(string $feld): string => e($felder[$feld] ?? '');
$gewaehlt = static fn(string $feld, string $wert): string => ($felder[$feld] ?? '') === $wert ? ' selected' : '';
$buchungen = static fn(int $n): string => $n === 1 ? '1 Buchung' : $n . ' Buchungen';

$kategorieGruppen = [];
foreach ($kategorieAuswahl as $kategorie) {
    $kategorieGruppen[$kategorie->direction->value][] = $kategorie;
}
?>
<section class="schmal">
    <h2><?= $neu ? 'Neue Buchungsregel' : 'Buchungsregel „' . e($regel->label) . '“' ?></h2>

    <p><a href="/app/buchungen/regeln">← Buchungsregeln</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="regel-fehler"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if (!$neu && !$regel->active): ?>
        <p class="hinweis hinweis-info">Diese Regel ist deaktiviert: Sie greift bei keinem Import und wirkt auf keine Buchung.</p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php $gesperrt = $darfBuchen ? '' : ' disabled'; ?>

        <label for="regel-bezeichnung">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="regel-bezeichnung" name="bezeichnung" value="<?= $wert('bezeichnung') ?>" required
                   maxlength="<?= e((string) Buchungsregeln::BEZEICHNUNG_MAX) ?>"<?= $fehlerAn('bezeichnung') . $gesperrt ?>>
        </label>
        <p class="feld-hilfe">Zum Beispiel „Kontoführungsgebühren“ oder „Zinsen Sparkonto“.</p>

        <fieldset>
            <legend>Wenn …</legend>
            <label for="regel-stichwort">Zweck oder Buchungstext enthält
                <input type="text" id="regel-stichwort" name="stichwort" value="<?= $wert('stichwort') ?>"
                       maxlength="<?= e((string) Buchungsregeln::MUSTER_MAX) ?>"<?= $fehlerAn('stichwort') . $gesperrt ?>>
            </label>
            <p class="feld-hilfe">Ein Wort oder Wortteil, Groß-/Kleinschreibung egal – etwa „Entgelt“, „Zinsen“ oder „Abschluss“.</p>

            <label for="regel-gegenseite">Gegenseite (Name oder IBAN)
                <input type="text" id="regel-gegenseite" name="gegenseite" value="<?= $wert('gegenseite') ?>"
                       maxlength="<?= e((string) Buchungsregeln::MUSTER_MAX) ?>"<?= $fehlerAn('gegenseite') . $gesperrt ?>>
            </label>
            <p class="feld-hilfe">Eine IBAN muss genau stimmen, ein Name muss im Namen der Gegenseite vorkommen. Ist beides angegeben, muss beides passen.</p>

            <label for="regel-richtung">Gilt für
                <select id="regel-richtung" name="richtung"<?= $fehlerAn('richtung') . $gesperrt ?>>
                    <option value=""<?= $gewaehlt('richtung', '') ?>>Einnahmen und Ausgaben</option>
                    <option value="<?= e(BankTransactionDirection::Ausgabe->value) ?>"<?= $gewaehlt('richtung', BankTransactionDirection::Ausgabe->value) ?>>nur Ausgaben</option>
                    <option value="<?= e(BankTransactionDirection::Einnahme->value) ?>"<?= $gewaehlt('richtung', BankTransactionDirection::Einnahme->value) ?>>nur Einnahmen</option>
                </select>
            </label>
        </fieldset>

        <fieldset>
            <legend>… dann</legend>
            <label class="feld-ankreuz">
                <input type="checkbox" name="kein_beleg" value="<?= e(Buchungsregeln::KEIN_BELEG) ?>"<?= ($felder['kein_beleg'] ?? '') === Buchungsregeln::KEIN_BELEG ? ' checked' : '' ?><?= $fehlerAn('kein_beleg') . $gesperrt ?>> kein Beleg nötig
            </label>

            <label for="regel-kategorie">Kategorie
                <select id="regel-kategorie" name="kategorie"<?= $fehlerAn('kategorie') . $gesperrt ?>>
                    <option value="">– keine –</option>
                    <?php foreach (CategoryDirection::cases() as $richtung): ?>
                        <?php if (($kategorieGruppen[$richtung->value] ?? []) !== []): ?>
                            <optgroup label="<?= e($richtung->gruppe()) ?>">
                                <?php foreach ($kategorieGruppen[$richtung->value] as $kategorie): ?>
                                    <option value="<?= e((string) $kategorie->id) ?>"<?= $gewaehlt('kategorie', (string) $kategorie->id) ?>><?= e($kategorie->name) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="feld-hilfe">Eine Ausgaben-Kategorie braucht „nur Ausgaben“, eine Einnahmen-Kategorie „nur Einnahmen“.</p>
        </fieldset>

        <?php if ($darfBuchen): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Speichern</button>
                <a class="knopf" href="/app/buchungen/regeln">Abbrechen</a>
            </p>
            <?php if (!$neu): ?>
                <p class="feld-hilfe">Beim Speichern wird die bisherige Wirkung zurückgenommen und die geänderte Regel auf dieselben Buchungen neu angewendet, soweit sie noch passt.</p>
            <?php endif; ?>
        <?php endif; ?>
    </form>
</section>

<?php if (!$neu): ?>
    <section class="schmal">
        <h3>Wirkung</h3>
        <p>
            Die Regel hat <?= $betroffen === 0 ? 'bisher keine Buchung' : '<a href="/app/buchungen?regel=' . e((string) $regel->id) . '&amp;von=&amp;bis=">' . e($buchungen($betroffen)) . '</a>' ?> eingeordnet.
        </p>

        <?php if ($darfBuchen && $regel->active): ?>
            <?php if ($kandidaten === 0): ?>
                <p class="gedaempft">Unter den bestehenden Buchungen ist keine weitere, die sie einordnen würde.</p>
            <?php else: ?>
                <form method="post" action="/app/buchungen/regeln/<?= e((string) $regel->id) ?>/anwenden" class="formular">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <p class="feld-hilfe">
                        Sie passt auf <?= e($buchungen($kandidaten)) ?> aus früheren Importen, die noch keine Regel eingeordnet hat.
                        Von Hand Eingeordnetes bleibt unberührt.
                    </p>
                    <p class="knopfreihe">
                        <button type="submit" class="knopf knopf-primaer">Auf <?= e($buchungen($kandidaten)) ?> anwenden</button>
                    </p>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($darfBuchen): ?>
            <form method="post" action="/app/buchungen/regeln/<?= e((string) $regel->id) ?>/aktiv" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="aktiv" value="<?= $regel->active ? '0' : '1' ?>">
                <p class="feld-hilfe">
                    <?= $regel->active
                        ? 'Deaktivieren nimmt die Wirkung zurück: Die Buchungen bekommen wieder den Standard ihrer Richtung, eine von der Regel gesetzte Kategorie entfällt.'
                        : 'Aktivieren lässt die Regel wieder bei Importen greifen; bestehende Buchungen danach hier anwenden.' ?>
                </p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf"><?= $regel->active ? 'Regel deaktivieren' : 'Regel aktivieren' ?></button>
                </p>
            </form>

            <h3>Regel löschen</h3>
            <form method="post" action="/app/buchungen/regeln/<?= e((string) $regel->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="feld-hilfe">Auch Löschen nimmt die Wirkung zurück. Das Löschen wird im Audit-Log festgehalten.</p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Regel löschen</button>
                </p>
            </form>
        <?php endif; ?>

        <p class="gedaempft">
            Angelegt am <?= e($regel->createdAt->format('d.m.Y')) ?>,
            zuletzt geändert am <?= e($regel->updatedAt->format('d.m.Y H:i')) ?>.
        </p>
    </section>
<?php endif; ?>
