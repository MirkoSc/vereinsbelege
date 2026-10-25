<?php

/**
 * Start page of the user area: the dashboard (issue #72/M11-2, docs/spec/
 * 05-auswertung-und-export.md section 1 "Dashboard") - income, expenses and
 * balance of one calendar year (= fiscal year, E-16), balances per account
 * and the counts of what is still to do, each block only with its right
 * (App\Service\Report\Dashboard). Without $kennzahlen (a route table that
 * does not wire the dashboard) only the quick links show.
 *
 * Only components from /admin/designsystem. Every figure is text - colour
 * is never the only signal (05 section 1).
 *
 * @var bool $posteingang whether the account may open the inbox
 * @var bool $erfassen whether the account may capture receipts
 * @var bool $entsperrt whether the vault is unlocked
 * @var \App\Service\Report\DashboardKennzahlen|null $kennzahlen
 * @var int|null $vorjahr the year before, null at the first offered year
 * @var int|null $folgejahr the year after, null for the last year the account may see
 * @var int|null $standardJahr the year /app opens with, when another one is shown
 */

use App\Service\Processing\Betrag;

$kennzahlen ??= null;
$geld = static fn(int $cent, string $waehrung = 'EUR'): string => Betrag::format($cent) . ' ' . ($waehrung === 'EUR' ? '€' : $waehrung);
$datum = static fn(\DateTimeImmutable $d): string => $d->format('d.m.Y');
?>
<section>
    <h2><?= $kennzahlen === null ? 'Belegverwaltung' : e('Übersicht ' . $kennzahlen->jahr) ?></h2>
    <?php if ($kennzahlen !== null): ?>
        <nav class="knopfreihe" aria-label="Geschäftsjahr wählen">
            <?php if ($vorjahr !== null): ?>
                <a class="knopf knopf-still" href="/app?jahr=<?= e((string) $vorjahr) ?>" rel="prev">‹ <?= e((string) $vorjahr) ?></a>
            <?php endif; ?>
            <?php if ($folgejahr !== null): ?>
                <a class="knopf knopf-still" href="/app?jahr=<?= e((string) $folgejahr) ?>" rel="next"><?= e((string) $folgejahr) ?> ›</a>
            <?php endif; ?>
            <?php if ($standardJahr !== null): ?>
                <a class="knopf knopf-still" href="/app">Zurück zu <?= e((string) $standardJahr) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php if (($posteingang ?? false) || ($erfassen ?? false)): ?>
        <p class="knopfreihe">
            <?php if ($posteingang ?? false): ?>
                <a class="knopf knopf-primaer" href="/app/posteingang">Zum Posteingang</a>
            <?php endif; ?>
            <?php if ($erfassen ?? false): ?>
                <a class="knopf" href="/app/belege/neu">Belege erfassen</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>

<?php if ($kennzahlen !== null): ?>
    <?php $entsperrt ??= false; ?>
    <?php if (!$entsperrt && ($kennzahlen->summenErlaubt || $kennzahlen->kontenErlaubt)): ?>
        <p class="hinweis hinweis-info">
            Beträge und Kontostände sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um sie zu sehen. Die Zahlen offener Vorgänge stehen trotzdem unten.
        </p>
    <?php endif; ?>

    <?php if ($kennzahlen->summenErlaubt && ($kennzahlen->summen !== null || $kennzahlen->sichtbar === null)): ?>
        <section aria-labelledby="dashboard-summen">
            <h3 id="dashboard-summen">Einnahmen und Ausgaben <?= e((string) $kennzahlen->jahr) ?></h3>
            <?php if ($kennzahlen->sichtbar === null): ?>
                <div class="leer">Dieses Jahr liegt außerhalb des für Sie freigegebenen Zeitraums.</div>
            <?php elseif ($kennzahlen->summen !== null): ?>
                <p class="gedaempft">
                    Nach Buchungen (Zahlungsfluss) vom <?= e($datum($kennzahlen->sichtbar->von)) ?>
                    bis <?= e($datum($kennzahlen->sichtbar->bis)) ?>, ohne Umbuchungen zwischen eigenen Konten.
                </p>
                <dl class="kennzahlen kennzahlen-geld">
                    <div class="kennzahl">
                        <dt>Einnahmen</dt>
                        <dd class="kennzahl-wert"><?= e($geld($kennzahlen->summen->einnahmen)) ?></dd>
                    </div>
                    <div class="kennzahl">
                        <dt>Ausgaben</dt>
                        <dd class="kennzahl-wert"><?= e($geld($kennzahlen->summen->ausgaben)) ?></dd>
                    </div>
                    <div class="kennzahl">
                        <dt>Saldo</dt>
                        <dd class="kennzahl-wert"><?= e(($kennzahlen->summen->saldo() > 0 ? '+' : '') . $geld($kennzahlen->summen->saldo())) ?></dd>
                        <dd class="klein gedaempft"><?= e(match (true) {
                            $kennzahlen->summen->saldo() > 0 => 'Überschuss',
                            $kennzahlen->summen->saldo() < 0 => 'Fehlbetrag',
                            default => 'ausgeglichen',
                        }) ?></dd>
                    </div>
                </dl>
                <?php if ($kennzahlen->unlesbar > 0): ?>
                    <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s nicht mitgezählt.', $kennzahlen->unlesbar, $kennzahlen->unlesbar === 1 ? 'Buchung ließ sich' : 'Buchungen ließen sich', $kennzahlen->unlesbar === 1 ? 'ist' : 'sind')) ?></p>
                <?php endif; ?>
                <?php if ($kennzahlen->fremdwaehrung > 0): ?>
                    <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s in einer anderen Währung als Euro %s nicht mitgezählt.', $kennzahlen->fremdwaehrung, $kennzahlen->fremdwaehrung === 1 ? 'Buchung' : 'Buchungen', $kennzahlen->fremdwaehrung === 1 ? 'ist' : 'sind')) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($kennzahlen->kontenErlaubt && $kennzahlen->kontostaende !== null): ?>
        <section aria-labelledby="dashboard-konten">
            <h3 id="dashboard-konten">Kontostände</h3>
            <?php if ($kennzahlen->stichtag === null): ?>
                <div class="leer">Dieses Jahr liegt außerhalb des für Sie freigegebenen Zeitraums.</div>
            <?php elseif ($kennzahlen->kontostaende === []): ?>
                <div class="leer">Zum <?= e($datum($kennzahlen->stichtag)) ?> gibt es noch kein Konto.</div>
            <?php else: ?>
                <div class="tabelle-rahmen">
                    <table class="tabelle">
                        <caption>Stand am Ende des <?= e($datum($kennzahlen->stichtag)) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col">Konto</th>
                                <th scope="col" class="zahl">Stand</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kennzahlen->kontostaende as $k): ?>
                                <tr>
                                    <td>
                                        <a href="/app/konten/<?= e((string) $k->konto->id) ?>"><?= e($k->konto->data->name) ?></a>
                                        <?php if (!$k->konto->active): ?>
                                            <span class="marke">inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="zahl"><?= e($geld($k->stand, strtoupper($k->konto->currency))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php $summe = $kennzahlen->kontenSumme(); ?>
                        <?php if ($summe !== null && count($kennzahlen->kontostaende) > 1): ?>
                            <tfoot>
                                <tr>
                                    <th scope="row">Summe</th>
                                    <td class="zahl"><strong><?= e($geld($summe)) ?></strong></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($kennzahlen->kontenUnlesbar > 0): ?>
                <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht lesen; die Stände können unvollständig sein.', $kennzahlen->kontenUnlesbar, $kennzahlen->kontenUnlesbar === 1 ? 'Eintrag ließ sich' : 'Einträge ließen sich')) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($kennzahlen->zaehler !== []): ?>
        <section aria-labelledby="dashboard-offen">
            <h3 id="dashboard-offen">Offene Vorgänge</h3>
            <p class="gedaempft">Stand heute, unabhängig vom gewählten Jahr.</p>
            <ul class="kennzahlen">
                <?php foreach ($kennzahlen->zaehler as $z): ?>
                    <li class="kennzahl">
                        <?php if ($z->pfad !== null): ?>
                            <a href="<?= e($z->pfad) ?>">
                                <span class="kennzahl-wert"><?= e((string) $z->anzahl) ?></span>
                                <span><?= e($z->bezeichnung) ?></span>
                            </a>
                        <?php else: ?>
                            <span class="kennzahl-wert"><?= e((string) $z->anzahl) ?></span>
                            <span><?= e($z->bezeichnung) ?></span>
                        <?php endif; ?>
                        <?php if ($z->zusatz !== null): ?>
                            <span class="klein gedaempft"><?= e($z->zusatz) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php elseif (!$kennzahlen->summenErlaubt && !$kennzahlen->kontenErlaubt && !($posteingang ?? false) && !($erfassen ?? false)): ?>
        <div class="leer">Für Ihr Konto sind keine Auswertungen freigeschaltet.</div>
    <?php endif; ?>
<?php elseif (!($posteingang ?? false) && !($erfassen ?? false)): ?>
    <div class="leer">Noch keine Belege.</div>
<?php endif; ?>
