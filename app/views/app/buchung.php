<?php

/**
 * One booking (M9-5, issue #63, docs/spec/04-bank-und-abgleich.md
 * section 1, E-17): the form for a new manual booking or for changing one
 * for whoever holds `bank.book`; an imported booking - and every booking for
 * readers without `bank.book` - as a table of the stored values.
 *
 * Only components from /admin/designsystem, no JavaScript: the category
 * list is grouped by direction and the server checks that both fit; the
 * receipt question defaults to the direction (E-17) unless chosen. A
 * suggested "Kassendifferenz" carries the cash count it came from as a
 * hidden field, so saving leads back to the cash box.
 *
 * M9-6 (issue #64): the table says where receipt status and category come
 * from - default, a rule (linked) or by hand -, and an imported booking
 * gets the form "Einordnung" (category, receipt) plus "Regel daraus
 * machen" for whoever holds `bank.book`.
 *
 * @var string $csrf
 * @var \App\Domain\BankTransaction|null $buchung null for a new one
 * @var array<string, string> $felder
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 * @var bool $bearbeitbar the form is shown (bank.book and a manual or new booking)
 * @var bool $darfBuchen holds `bank.book`
 * @var list<\App\Domain\BankAccount> $kontoAuswahl active accounts, plus the booking's own
 * @var array<int, \App\Domain\BankAccount> $konten every account by id
 * @var list<\App\Domain\Category> $kategorieAuswahl active categories, plus the booking's own
 * @var array<int, \App\Domain\Category> $kategorien every category by id
 * @var array<string, string> $einordnung kategorie, beleg of the "Einordnung" form
 * @var \App\Domain\AssignmentRule|null $regel the rule that set receipt status or category
 * @var \App\Service\Bank\BelegStandard $belegStandard
 * @var \App\Domain\CashCount|null $kassensturz the cash count a suggested difference comes from
 * @var \DateTimeImmutable $heute
 */

use App\Domain\BankAccountKind;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSetBy;
use App\Domain\CategoryDirection;
use App\Domain\Iban;
use App\Service\Bank\Buchungen;
use App\Service\Processing\Betrag;

$neu = $buchung === null;
$ziel = $neu ? '/app/buchungen' : '/app/buchungen/' . $buchung->id;
$fehlerAn = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="buchung-fehler"' : '';
$wert = static fn(string $feld): string => e($felder[$feld] ?? '');
$gewaehlt = static fn(string $feld, string $wert): string => ($felder[$feld] ?? '') === $wert ? ' selected' : '';
$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
$leer = '<span class="gedaempft">–</span>';

$kategorieGruppen = [];
foreach ($kategorieAuswahl as $kategorie) {
    $kategorieGruppen[$kategorie->direction->value][] = $kategorie;
}
$konto = $neu ? null : ($konten[$buchung->accountId] ?? null);
$standardText = 'nach Richtung (Ausgabe: Beleg nötig, Einnahme: ' . ($belegStandard->einnahmeBelegNoetig ? 'Beleg nötig' : 'kein Beleg nötig') . ')';
// Where a value comes from, for the table (M9-6). Empty for a manual booking: all of it is by hand.
$herkunft = static function (?BankTransactionSetBy $quelle) use ($buchung, $regel): string {
    if ($buchung === null || $buchung->istManuell()) {
        return '';
    }

    return match ($quelle) {
        BankTransactionSetBy::Regel => $regel === null
            ? ' <span class="gedaempft">(durch eine Regel)</span>'
            : ' <span class="gedaempft">(durch Regel <a href="/app/buchungen/regeln/' . e((string) $regel->id) . '">„' . e($regel->label) . '“</a>)</span>',
        BankTransactionSetBy::Manuell => ' <span class="gedaempft">(von Hand gesetzt)</span>',
        BankTransactionSetBy::Standard => ' <span class="gedaempft">(Standard für ' . ($buchung->direction === BankTransactionDirection::Ausgabe ? 'Ausgaben' : 'Einnahmen') . ')</span>',
        null => '',
    };
};
$einordnungKategorien = [];
if (!$neu) {
    foreach ($kategorieAuswahl as $kategorie) {
        if ($kategorie->direction === CategoryDirection::Beide || $kategorie->direction->value === $buchung->direction->value) {
            $einordnungKategorien[] = $kategorie;
        }
    }
}
?>
<section class="schmal">
    <h2><?= $neu ? 'Neue Buchung' : 'Buchung vom ' . e($buchung->bookingDate->format('d.m.Y')) ?></h2>

    <p><a href="/app/buchungen<?= $konto === null ? '' : '?konto=' . e((string) $konto->id) ?>">← Buchungen</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="buchung-fehler"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if ($kassensturz !== null): ?>
        <p class="hinweis hinweis-info">
            Vorschlag aus dem Kassensturz vom <?= e($kassensturz->countedOn->format('d.m.Y')) ?>:
            Die Differenz wird als Buchung „<?= e(Buchungen::KASSENDIFFERENZ) ?>“ erfasst, damit der Kassenbestand wieder stimmt.
            Bitte prüfen und speichern – oder abbrechen, wenn die Differenz anders erklärt wird.
        </p>
    <?php endif; ?>

    <?php if ($bearbeitbar): ?>
        <?php if ($kontoAuswahl === []): ?>
            <p class="hinweis hinweis-info">Es gibt kein aktives Konto und keine aktive Kasse. Bitte zuerst unter <a href="/app/konten">Konten</a> anlegen.</p>
        <?php else: ?>
            <form method="post" action="<?= e($ziel) ?>" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <?php if ($kassensturz !== null): ?>
                    <input type="hidden" name="kassensturz" value="<?= e((string) $kassensturz->id) ?>">
                <?php endif; ?>

                <label for="buchung-konto">Konto oder Kasse <span class="pflicht" aria-hidden="true">*</span>
                    <select id="buchung-konto" name="konto" required<?= $fehlerAn('konto') ?>>
                        <option value="">– bitte wählen –</option>
                        <?php foreach ($kontoAuswahl as $k): ?>
                            <option value="<?= e((string) $k->id) ?>"<?= $gewaehlt('konto', (string) $k->id) ?>>
                                <?= e($k->data->name) ?><?= $k->kind === BankAccountKind::Kasse ? ' (Kasse)' : '' ?><?= $k->active ? '' : ' (inaktiv)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label for="buchung-datum" class="feld-kurz">Datum <span class="pflicht" aria-hidden="true">*</span>
                    <input type="date" id="buchung-datum" name="datum" value="<?= $wert('datum') ?>" required
                           max="<?= e($heute->format('Y-m-d')) ?>"<?= $fehlerAn('datum') ?>>
                </label>

                <label for="buchung-richtung" class="feld-kurz">Richtung <span class="pflicht" aria-hidden="true">*</span>
                    <select id="buchung-richtung" name="richtung" required<?= $fehlerAn('richtung') ?>>
                        <option value="">– bitte wählen –</option>
                        <?php foreach (BankTransactionDirection::cases() as $richtung): ?>
                            <option value="<?= e($richtung->value) ?>"<?= $gewaehlt('richtung', $richtung->value) ?>><?= e($richtung->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label for="buchung-betrag" class="feld-kurz">Betrag (€) <span class="pflicht" aria-hidden="true">*</span>
                    <input type="text" id="buchung-betrag" name="betrag" value="<?= $wert('betrag') ?>" inputmode="decimal"
                           required<?= $fehlerAn('betrag') ?>>
                </label>
                <p class="feld-hilfe">Immer positiv eingeben, zum Beispiel 25,00 – ob Geld hinein- oder hinausgeht, sagt die Richtung.</p>

                <label for="buchung-kategorie">Kategorie <span class="pflicht" aria-hidden="true">*</span>
                    <select id="buchung-kategorie" name="kategorie" required<?= $fehlerAn('kategorie') ?>>
                        <option value="">– bitte wählen –</option>
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
                <p class="feld-hilfe">Eine Einnahme braucht eine Einnahme-Kategorie, eine Ausgabe eine Ausgabe-Kategorie.</p>

                <label for="buchung-zweck">Zweck <span class="pflicht" aria-hidden="true">*</span>
                    <input type="text" id="buchung-zweck" name="zweck" value="<?= $wert('zweck') ?>"
                           maxlength="<?= e((string) Buchungen::ZWECK_MAX) ?>" required<?= $fehlerAn('zweck') ?>>
                </label>
                <p class="feld-hilfe">Zum Beispiel „Getränkeverkauf Heimspiel“, „Spende in bar“ oder „Startgelder Turnier“.</p>

                <label for="buchung-gegenseite">Von wem / an wen
                    <input type="text" id="buchung-gegenseite" name="gegenseite" value="<?= $wert('gegenseite') ?>"
                           maxlength="<?= e((string) Buchungen::GEGENSEITE_MAX) ?>"<?= $fehlerAn('gegenseite') ?>>
                </label>

                <label for="buchung-beleg">Beleg
                    <select id="buchung-beleg" name="beleg"<?= $fehlerAn('beleg') ?>>
                        <option value=""<?= $gewaehlt('beleg', '') ?>><?= e($standardText) ?></option>
                        <option value="<?= e(Buchungen::BELEG_NOETIG) ?>"<?= $gewaehlt('beleg', Buchungen::BELEG_NOETIG) ?>>Beleg nötig</option>
                        <option value="<?= e(Buchungen::BELEG_NICHT_NOETIG) ?>"<?= $gewaehlt('beleg', Buchungen::BELEG_NICHT_NOETIG) ?>>kein Beleg nötig</option>
                    </select>
                </label>
                <p class="feld-hilfe">
                    Bareinnahmen wie Getränkeverkauf, Spenden in bar oder Eintritt brauchen keinen Beleg.
                    Ein Beleg kann später trotzdem zugeordnet werden.
                </p>

                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer">Speichern</button>
                    <a class="knopf" href="<?= $kassensturz !== null && $felder['konto'] !== '' ? '/app/konten/' . $wert('konto') : '/app/buchungen' ?>">Abbrechen</a>
                </p>
            </form>
        <?php endif; ?>
    <?php elseif (!$neu): ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <caption><?= $buchung->istManuell() ? 'Manuell erfasste Buchung' : 'Buchung aus einem Kontoauszug – so, wie die Bank sie meldet' ?></caption>
                <tbody>
                    <tr><th scope="row">Konto</th><td data-label="Konto"><?= $konto === null ? $leer : e($konto->data->name) ?></td></tr>
                    <tr><th scope="row">Buchungstag</th><td data-label="Buchungstag"><?= e($buchung->bookingDate->format('d.m.Y')) ?></td></tr>
                    <?php if ($buchung->valueDate !== null): ?>
                        <tr><th scope="row">Wertstellung</th><td data-label="Wertstellung"><?= e($buchung->valueDate->format('d.m.Y')) ?></td></tr>
                    <?php endif; ?>
                    <tr><th scope="row">Richtung</th><td data-label="Richtung"><?= e($buchung->direction->label()) ?></td></tr>
                    <tr><th scope="row">Betrag</th><td data-label="Betrag"><?= $betrag($buchung->amount) ?></td></tr>
                    <tr><th scope="row">Zweck</th><td data-label="Zweck"><?= $buchung->purpose === '' ? $leer : e($buchung->purpose) ?></td></tr>
                    <tr><th scope="row">Gegenseite</th><td data-label="Gegenseite"><?= $buchung->counterpartyName === '' ? $leer : e($buchung->counterpartyName) ?></td></tr>
                    <?php if ($buchung->counterpartyIban !== ''): ?>
                        <tr><th scope="row">IBAN der Gegenseite</th><td data-label="IBAN der Gegenseite"><?= e(Iban::formatieren($buchung->counterpartyIban)) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($buchung->bookingText !== ''): ?>
                        <tr><th scope="row">Buchungstext</th><td data-label="Buchungstext"><?= e($buchung->bookingText) ?></td></tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">Kategorie</th>
                        <td data-label="Kategorie"><?= $buchung->categoryId === null || !isset($kategorien[$buchung->categoryId]) ? $leer : e($kategorien[$buchung->categoryId]->name) . $herkunft($buchung->categorySource) ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Beleg</th>
                        <td data-label="Beleg">
                            <span class="marke<?= $buchung->docStatus === BankTransactionDocStatus::Fehlt ? ' marke-warnung' : ($buchung->docStatus === BankTransactionDocStatus::Zugeordnet ? ' marke-ok' : '') ?>"><?= e($buchung->docStatus->label()) ?></span><?= $herkunft($buchung->docSource) ?>
                        </td>
                    </tr>
                    <tr><th scope="row">Herkunft</th><td data-label="Herkunft"><?= e($buchung->source->label()) ?></td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!$neu && $darfBuchen && !$buchung->istManuell()): ?>
    <section class="schmal">
        <h3>Einordnung</h3>
        <p class="gedaempft">
            Kategorie und Beleg-Bedarf dieser Buchung – was hier von Hand gesetzt wird, ändert keine Regel mehr.
            Für wiederkehrende Buchungen wie Zinsen oder Kontoführung ist eine Regel bequemer.
        </p>
        <form method="post" action="/app/buchungen/<?= e((string) $buchung->id) ?>/einordnung" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label for="einordnung-kategorie">Kategorie
                <select id="einordnung-kategorie" name="kategorie"<?= $fehlerAn('kategorie') ?>>
                    <option value=""<?= ($einordnung['kategorie'] ?? '') === '' ? ' selected' : '' ?>>– keine –</option>
                    <?php foreach ($einordnungKategorien as $kategorie): ?>
                        <option value="<?= e((string) $kategorie->id) ?>"<?= ($einordnung['kategorie'] ?? '') === (string) $kategorie->id ? ' selected' : '' ?>><?= e($kategorie->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="einordnung-beleg">Beleg
                <select id="einordnung-beleg" name="beleg"<?= $fehlerAn('beleg') ?>>
                    <option value=""<?= ($einordnung['beleg'] ?? '') === '' ? ' selected' : '' ?>><?= e($standardText) ?></option>
                    <option value="<?= e(Buchungen::BELEG_NOETIG) ?>"<?= ($einordnung['beleg'] ?? '') === Buchungen::BELEG_NOETIG ? ' selected' : '' ?>>Beleg nötig</option>
                    <option value="<?= e(Buchungen::BELEG_NICHT_NOETIG) ?>"<?= ($einordnung['beleg'] ?? '') === Buchungen::BELEG_NICHT_NOETIG ? ' selected' : '' ?>>kein Beleg nötig</option>
                </select>
            </label>
            <?php if ($buchung->docStatus === BankTransactionDocStatus::Zugeordnet): ?>
                <p class="feld-hilfe">Ein Beleg ist zugeordnet – das bleibt so, egal was hier steht.</p>
            <?php endif; ?>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Einordnung speichern</button>
                <a class="knopf" href="/app/buchungen/regeln/neu?buchung=<?= e((string) $buchung->id) ?>">Regel daraus machen</a>
            </p>
        </form>
    </section>
<?php endif; ?>

<?php if (!$neu): ?>
    <section class="schmal">
        <p class="gedaempft">
            Erfasst am <?= e($buchung->createdAt->format('d.m.Y')) ?>,
            zuletzt geändert am <?= e($buchung->updatedAt->format('d.m.Y H:i')) ?>.
        </p>

        <?php if ($darfBuchen && $konto !== null && $konto->active): ?>
            <p class="knopfreihe">
                <a class="knopf" href="/app/buchungen/neu?konto=<?= e((string) $konto->id) ?>">Weitere Buchung auf „<?= e($konto->data->name) ?>“</a>
            </p>
        <?php endif; ?>

        <?php if ($bearbeitbar): ?>
            <h3>Buchung löschen</h3>
            <?php if ($buchung->docStatus === BankTransactionDocStatus::Zugeordnet): ?>
                <p class="gedaempft">Der Buchung ist ein Beleg zugeordnet – sie lässt sich deshalb nicht löschen.</p>
            <?php else: ?>
                <form method="post" action="/app/buchungen/<?= e((string) $buchung->id) ?>/loeschen" class="formular">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <p class="feld-hilfe">Nur für versehentlich erfasste Buchungen. Das Löschen wird im Audit-Log festgehalten.</p>
                    <p class="knopfreihe">
                        <button type="submit" class="knopf knopf-gefahr">Buchung löschen</button>
                    </p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
