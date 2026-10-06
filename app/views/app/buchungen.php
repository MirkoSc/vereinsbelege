<?php

/**
 * Bookings (M9-5, issue #63, docs/spec/04-bank-und-abgleich.md section 1,
 * E-17): every booking of the accounts and cash boxes the reader may see,
 * newest first, with filters and the sums of what is shown.
 *
 * A plain GET form, no JavaScript. Without dates the list shows the current
 * year; emptying both date fields lifts that. The table becomes cards below
 * 48 rem (.tabelle-karten), so nothing scrolls sideways at 360 px. Income
 * without a receipt carries the mark "kein Beleg nötig", a booking entered
 * by hand the mark "manuell".
 *
 * @var bool $entsperrt
 * @var \App\Service\Bank\BuchungFilter $filter
 * @var bool $filterAktiv
 * @var array<int, \App\Domain\BankAccount> $konten
 * @var array<int, \App\Domain\Category> $kategorien
 * @var list<\App\Domain\BankTransaction> $buchungen
 * @var int $einnahmen sum of the income shown, in cents
 * @var int $ausgaben sum of the expenses shown, in cents (negative)
 * @var bool $darfBuchen holds `bank.book`
 */

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;
use App\Service\Bank\BuchungFilter;
use App\Service\Processing\Betrag;

$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
$belegMarke = static fn(BankTransactionDocStatus $s): string => match ($s) {
    BankTransactionDocStatus::Fehlt => 'marke marke-warnung',
    BankTransactionDocStatus::Zugeordnet => 'marke marke-ok',
    BankTransactionDocStatus::NichtNoetig => 'marke',
};
$gewaehlt = static fn(bool $ja): string => $ja ? ' selected' : '';
?>
<section>
    <h2>Buchungen</h2>

    <p class="gedaempft">
        Alle Buchungen der Bankkonten und Kassen – aus Kontoauszügen und von Hand erfasst.
        Einnahmen brauchen standardmäßig keinen Beleg.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Beträge, Zwecke und Gegenseiten sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um die Buchungen zu sehen.
        </p>
    <?php else: ?>
        <?php if ($darfBuchen): ?>
            <p class="knopfreihe">
                <a class="knopf knopf-primaer" href="/app/buchungen/neu<?= $filter->kontoId === null ? '' : '?konto=' . e((string) $filter->kontoId) ?>">Neue Buchung</a>
                <a class="knopf" href="/app/buchungen/neu?richtung=einnahme<?= $filter->kontoId === null ? '' : '&amp;konto=' . e((string) $filter->kontoId) ?>">Einnahme ohne Beleg</a>
            </p>
        <?php endif; ?>

        <form method="get" action="/app/buchungen" class="formular">
            <label for="buchungen-konto">Konto
                <select id="buchungen-konto" name="konto">
                    <option value="">Alle</option>
                    <?php foreach ($konten as $konto): ?>
                        <option value="<?= e((string) $konto->id) ?>"<?= $gewaehlt($filter->kontoId === $konto->id) ?>><?= e($konto->data->name) ?><?= $konto->active ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="buchungen-von" class="feld-kurz">Von
                <input type="date" id="buchungen-von" name="von" value="<?= e($filter->von?->format('Y-m-d') ?? '') ?>">
            </label>

            <label for="buchungen-bis" class="feld-kurz">Bis
                <input type="date" id="buchungen-bis" name="bis" value="<?= e($filter->bis?->format('Y-m-d') ?? '') ?>">
            </label>
            <p class="feld-hilfe">Ohne Angabe gilt das laufende Jahr; beide Felder leeren zeigt alle Jahre.</p>

            <label for="buchungen-richtung">Richtung
                <select id="buchungen-richtung" name="richtung">
                    <option value="">Alle</option>
                    <?php foreach (BankTransactionDirection::cases() as $richtung): ?>
                        <option value="<?= e($richtung->value) ?>"<?= $gewaehlt($filter->richtung === $richtung) ?>><?= $richtung === BankTransactionDirection::Einnahme ? 'Einnahmen' : 'Ausgaben' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="buchungen-beleg">Beleg
                <select id="buchungen-beleg" name="beleg">
                    <option value="">Alle</option>
                    <?php foreach (BankTransactionDocStatus::cases() as $status): ?>
                        <option value="<?= e($status->value) ?>"<?= $gewaehlt($filter->belegStatus === $status) ?>><?= e($status->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="buchungen-quelle">Herkunft
                <select id="buchungen-quelle" name="quelle">
                    <option value="">Alle</option>
                    <option value="<?= e(BankTransactionSource::Import->value) ?>"<?= $gewaehlt($filter->quelle === BankTransactionSource::Import) ?>>aus Kontoauszug</option>
                    <option value="<?= e(BankTransactionSource::Manuell->value) ?>"<?= $gewaehlt($filter->quelle === BankTransactionSource::Manuell) ?>>manuell erfasst</option>
                </select>
            </label>

            <label for="buchungen-kategorie">Kategorie
                <select id="buchungen-kategorie" name="kategorie">
                    <option value="">Alle</option>
                    <option value="<?= e(BuchungFilter::OHNE_KATEGORIE) ?>"<?= $gewaehlt($filter->kategorieId === 0) ?>>ohne Kategorie</option>
                    <?php foreach ($kategorien as $kategorie): ?>
                        <option value="<?= e((string) $kategorie->id) ?>"<?= $gewaehlt($filter->kategorieId === $kategorie->id) ?>><?= e($kategorie->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label for="buchungen-suche">Suche in Zweck und Gegenseite
                <input type="search" id="buchungen-suche" name="suche" value="<?= e($filter->suche) ?>"
                       maxlength="<?= e((string) BuchungFilter::SUCHE_MAX) ?>">
            </label>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Filtern</button>
                <?php if ($filterAktiv): ?>
                    <a class="knopf" href="/app/buchungen">Filter zurücksetzen</a>
                <?php endif; ?>
            </p>
        </form>

        <?php if ($buchungen === []): ?>
            <div class="leer"><?= $filterAktiv ? 'Keine passenden Buchungen.' : 'In diesem Jahr gibt es noch keine Buchungen.' ?></div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption>Summen der angezeigten Buchungen</caption>
                    <tbody>
                        <tr><th scope="row">Einnahmen</th><td class="zahl"><?= $betrag($einnahmen) ?></td></tr>
                        <tr><th scope="row">Ausgaben</th><td class="zahl"><?= $betrag($ausgaben) ?></td></tr>
                        <tr><th scope="row">Saldo</th><td class="zahl"><strong><?= $betrag($einnahmen + $ausgaben) ?></strong></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption><?= e((string) count($buchungen)) ?> <?= count($buchungen) === 1 ? 'Buchung' : 'Buchungen' ?>, neueste zuerst</caption>
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Konto</th>
                            <th scope="col">Zweck</th>
                            <th scope="col">Kategorie</th>
                            <th scope="col">Beleg</th>
                            <th scope="col" class="zahl">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($buchungen as $buchung): ?>
                            <?php $konto = $konten[$buchung->accountId] ?? null; ?>
                            <tr>
                                <td class="tabelle-kurz" data-label="Datum">
                                    <a href="/app/buchungen/<?= e((string) $buchung->id) ?>"><?= e($buchung->bookingDate->format('d.m.Y')) ?></a>
                                </td>
                                <td data-label="Konto"><?= $konto === null ? '<span class="gedaempft">–</span>' : e($konto->data->name) ?></td>
                                <td data-label="Zweck">
                                    <?= $buchung->purpose === '' ? '<span class="gedaempft">–</span>' : e(mb_strimwidth($buchung->purpose, 0, 80, '…')) ?>
                                    <?php if ($buchung->counterpartyName !== ''): ?>
                                        <br><span class="gedaempft"><?= e($buchung->counterpartyName) ?></span>
                                    <?php endif; ?>
                                    <?php if ($buchung->istManuell()): ?>
                                        <span class="marke">manuell</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Kategorie">
                                    <?= $buchung->categoryId === null || !isset($kategorien[$buchung->categoryId])
                                        ? '<span class="gedaempft">–</span>'
                                        : e($kategorien[$buchung->categoryId]->name) ?>
                                </td>
                                <td class="tabelle-kurz" data-label="Beleg">
                                    <span class="<?= e($belegMarke($buchung->docStatus)) ?>"><?= e($buchung->docStatus->label()) ?></span>
                                </td>
                                <td class="zahl tabelle-kurz" data-label="Betrag"><?= $betrag($buchung->amount) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
