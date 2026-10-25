<?php

/**
 * The Kassenprüfung (issue #74/M11-4, docs/spec/05-auswertung-und-export.md
 * section 1, E-52): balances per account at the start and end of the
 * period, the bookings without a receipt, the cases decided by hand, and
 * every booking with its receipt status - a printable page, each table also
 * as CSV. Only components from /admin/designsystem; every status is text,
 * colour never the only signal.
 *
 * @var bool $entsperrt
 * @var bool $darfBank
 * @var bool $darfCsv
 * @var \App\Service\Report\ZeitraumAuswahl $auswahl
 * @var \App\Service\Report\KassenpruefungBericht|null $bericht
 * @var int $erstesJahr
 * @var int $letztesJahr
 */

use App\App\KassenpruefungController;
use App\Service\Processing\Betrag;
use App\Service\Report\KassenpruefungCsv;
use App\Service\Report\Pruefbuchung;

$geld = static fn(int $cent, string $waehrung = 'EUR'): string => Betrag::format($cent) . ' ' . (strtoupper($waehrung) === 'EUR' ? '€' : strtoupper($waehrung));
$datum = static fn(\DateTimeImmutable $d): string => $d->format('d.m.Y');
$aktion = KassenpruefungController::PFAD;
$csvLink = static fn(string $teil): string => $aktion . '/' . $teil . '.csv?' . http_build_query($auswahl->query());
$sichtbar = $bericht?->sichtbar;
$buchungZelle = static function (Pruefbuchung $p): string {
    $b = $p->buchung;
    $html = '<a href="/app/buchungen/' . e((string) $b->id) . '">' . e($b->counterpartyName !== '' ? $b->counterpartyName : 'ohne Gegenseite') . '</a>';
    if ($b->purpose !== '') {
        $html .= '<br><span class="gedaempft">' . e($b->purpose) . '</span>';
    }

    return $html;
};
?>
<section>
    <h2>Kassenprüfung</h2>
    <p class="gedaempft nicht-drucken">
        Vollständigkeitsbericht für einen Zeitraum: Salden je Konto am Anfang und Ende, Buchungen ohne Beleg,
        von Hand entschiedene Fälle und alle Buchungen mit ihrem Belegstatus. Zum Ausdrucken oder als CSV für Excel.
    </p>
    <?php
    $erstesJahr ??= 2000;
    $letztesJahr ??= (int) date('Y') + 1;
    require __DIR__ . '/../partials/zeitraum-filter.php';
    ?>
    <p class="nur-druck">
        Zeitraum <?= e($datum(($sichtbar ?? $auswahl->zeitraum)->von)) ?> bis <?= e($datum(($sichtbar ?? $auswahl->zeitraum)->bis)) ?>,
        erstellt am <?= e(date('d.m.Y H:i')) ?>
    </p>
</section>

<?php if (!$darfBank): ?>
    <p class="hinweis hinweis-info">
        Die Kassenprüfung zeigt Konten und Buchungen. Dafür braucht Ihr Konto zusätzlich das Recht „Konten und Buchungen sehen“.
    </p>
<?php elseif (!$entsperrt): ?>
    <p class="hinweis hinweis-info">
        Beträge und Buchungen sind verschlüsselt und nur mit entsperrtem Tresor lesbar. Melden Sie sich neu an, um den Bericht zu sehen.
    </p>
<?php elseif ($bericht !== null && $sichtbar === null): ?>
    <div class="leer">Dieser Zeitraum liegt außerhalb des für Sie freigegebenen Zeitraums.</div>
<?php elseif ($bericht !== null && $sichtbar !== null): ?>
    <?php $ohneBeleg = $bericht->ohneBeleg(); $manuell = $bericht->manuelleFaelle(); ?>

    <section aria-labelledby="kp-ueberblick">
        <h3 id="kp-ueberblick">Zeitraum <?= e($datum($sichtbar->von)) ?> bis <?= e($datum($sichtbar->bis)) ?></h3>
        <?php if ($sichtbar->von != $bericht->zeitraum->von || $sichtbar->bis != $bericht->zeitraum->bis): ?>
            <p class="hinweis hinweis-info">
                Gezeigt wird nur der für Sie freigegebene Teil des gewählten Zeitraums
                (<?= e($datum($bericht->zeitraum->von)) ?> bis <?= e($datum($bericht->zeitraum->bis)) ?>).
            </p>
        <?php endif; ?>
        <dl class="kennzahlen">
            <div class="kennzahl">
                <dt>Buchungen</dt>
                <dd class="kennzahl-wert"><?= e((string) count($bericht->buchungen)) ?></dd>
            </div>
            <div class="kennzahl">
                <dt>Ohne Beleg</dt>
                <dd class="kennzahl-wert"><?= e((string) count($ohneBeleg)) ?></dd>
            </div>
            <div class="kennzahl">
                <dt>Von Hand entschieden</dt>
                <dd class="kennzahl-wert"><?= e((string) count($manuell)) ?></dd>
            </div>
        </dl>
        <p class="knopfreihe nicht-drucken">
            <button type="button" class="knopf" data-drucken hidden>Drucken</button>
            <?php if ($darfCsv): ?>
                <?php foreach (KassenpruefungCsv::TEILE as $teil => $label): ?>
                    <a class="knopf knopf-still" href="<?= e($csvLink($teil)) ?>" download><?= e($label) ?> (CSV)</a>
                <?php endforeach; ?>
            <?php endif; ?>
        </p>
        <?php if ($bericht->unlesbar > 0): ?>
            <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln und %s nicht enthalten.', $bericht->unlesbar, $bericht->unlesbar === 1 ? 'Buchung oder Konto ließ sich' : 'Buchungen oder Konten ließen sich', $bericht->unlesbar === 1 ? 'ist' : 'sind')) ?></p>
        <?php endif; ?>
        <?php if ($bericht->zuordnungUnlesbar() > 0): ?>
            <p class="hinweis hinweis-warnung"><?= e(sprintf('Bei %d %s ließ sich eine Zuordnung nicht entschlüsseln – der Belegstatus ist dort nicht prüfbar.', $bericht->zuordnungUnlesbar(), $bericht->zuordnungUnlesbar() === 1 ? 'Buchung' : 'Buchungen')) ?></p>
        <?php endif; ?>
    </section>

    <section aria-labelledby="kp-salden">
        <h3 id="kp-salden">Salden je Konto</h3>
        <?php if ($bericht->salden === []): ?>
            <div class="leer">In diesem Zeitraum gibt es kein Konto.</div>
        <?php else: ?>
            <?php if ($bericht->uebertrag): ?>
                <p class="gedaempft">Der Anfangsbestand ist der Übertrag aus der Zeit vor dem für Sie freigegebenen Zeitraum.</p>
            <?php endif; ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption>Bestand am Anfang des <?= e($datum($sichtbar->von)) ?> und am Ende des <?= e($datum($sichtbar->bis)) ?>, Umbuchungen eingeschlossen</caption>
                    <thead>
                        <tr>
                            <th scope="col">Konto</th>
                            <th scope="col" class="zahl"><?= $bericht->uebertrag ? 'Anfang (Übertrag)' : 'Anfang' ?></th>
                            <th scope="col" class="zahl">Zugänge</th>
                            <th scope="col" class="zahl">Abgänge</th>
                            <th scope="col" class="zahl">Ende</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bericht->salden as $z): ?>
                            <?php $w = $z->konto->currency; $s = $z->saldo; ?>
                            <tr>
                                <th scope="row">
                                    <?= e($z->konto->data->name) ?>
                                    <?php if ($sichtbar->enthaelt($z->konto->openingDate)): ?>
                                        <br><span class="gedaempft klein">eröffnet am <?= e($datum($z->konto->openingDate)) ?> mit <?= e($geld($s->eroeffnung, $w)) ?></span>
                                    <?php endif; ?>
                                </th>
                                <td class="zahl"><?= e($geld($s->anfang, $w)) ?></td>
                                <td class="zahl"><?= e($geld($s->zugaenge, $w)) ?></td>
                                <td class="zahl"><?= e($geld($s->abgaenge, $w)) ?></td>
                                <td class="zahl"><strong><?= e($geld($s->ende, $w)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="kp-ohne-beleg">
        <h3 id="kp-ohne-beleg">Buchungen ohne Beleg</h3>
        <?php if ($ohneBeleg === []): ?>
            <div class="leer">Zu jeder Buchung, die einen Beleg braucht, ist einer zugeordnet.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e(count($ohneBeleg) === 1
                        ? '1 Buchung, die einen Beleg braucht, aber keinen oder nicht für den ganzen Betrag hat'
                        : sprintf('%d Buchungen, die einen Beleg brauchen, aber keinen oder nicht für den ganzen Betrag haben', count($ohneBeleg))) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Konto</th>
                            <th scope="col">Buchung</th>
                            <th scope="col" class="zahl">Betrag</th>
                            <th scope="col" class="zahl">Ohne Beleg</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ohneBeleg as $p): ?>
                            <tr>
                                <td><?= e($datum($p->buchung->bookingDate)) ?></td>
                                <td><?= e($p->konto) ?></td>
                                <td><?= $buchungZelle($p) ?><br><span class="marke marke-warnung"><?= e($p->buchung->docStatus->label()) ?></span></td>
                                <td class="zahl"><?= e($geld($p->buchung->amount, $p->buchung->currency)) ?></td>
                                <td class="zahl"><?= e($geld($p->ohneBeleg(), $p->buchung->currency)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="kp-manuell">
        <h3 id="kp-manuell">Von Hand entschiedene Fälle</h3>
        <p class="gedaempft">Belege, die eine Person einer Buchung von Hand zugeordnet hat, und Buchungen, die eine Person als „kein Beleg nötig“ markiert hat.</p>
        <?php if ($manuell === []): ?>
            <div class="leer">In diesem Zeitraum wurde nichts von Hand entschieden.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Konto</th>
                            <th scope="col">Buchung</th>
                            <th scope="col" class="zahl">Betrag</th>
                            <th scope="col">Fall</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manuell as $p): ?>
                            <tr>
                                <td><?= e($datum($p->buchung->bookingDate)) ?></td>
                                <td><?= e($p->konto) ?></td>
                                <td><?= $buchungZelle($p) ?></td>
                                <td class="zahl"><?= e($geld($p->buchung->amount, $p->buchung->currency)) ?></td>
                                <td>
                                    <?php if ($p->vonHandOhneBelegpflicht()): ?>
                                        <?= e(KassenpruefungCsv::FALL_OHNE_BELEGPFLICHT) ?>
                                    <?php endif; ?>
                                    <?php foreach ($p->zuordnungenVonHand() as $z): ?>
                                        <div>
                                            <?= e(KassenpruefungCsv::FALL_ZUORDNUNG) ?>:
                                            <?= e($z->beleg?->bezeichnung() ?? 'Beleg außerhalb Ihres Zugriffsbereichs') ?>,
                                            <?= e($geld($z->zuordnung->amount, $p->buchung->currency)) ?>,
                                            am <?= e($datum($z->zuordnung->createdAt)) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="kp-buchungen">
        <h3 id="kp-buchungen">Alle Buchungen</h3>
        <?php if ($bericht->buchungen === []): ?>
            <div class="leer">In diesem Zeitraum gibt es keine Buchung.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e(sprintf('%d %s, älteste zuerst', count($bericht->buchungen), count($bericht->buchungen) === 1 ? 'Buchung' : 'Buchungen')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Konto</th>
                            <th scope="col">Buchung</th>
                            <th scope="col" class="zahl">Betrag</th>
                            <th scope="col">Belegstatus</th>
                            <th scope="col">Belege</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bericht->buchungen as $p): ?>
                            <tr>
                                <td><?= e($datum($p->buchung->bookingDate)) ?></td>
                                <td><?= e($p->konto) ?></td>
                                <td>
                                    <?= $buchungZelle($p) ?>
                                    <?php if ($p->buchung->istManuell()): ?>
                                        <br><span class="marke">von Hand erfasst</span>
                                    <?php endif; ?>
                                </td>
                                <td class="zahl"><?= e($geld($p->buchung->amount, $p->buchung->currency)) ?></td>
                                <td>
                                    <?php if ($p->istUmbuchung()): ?>
                                        <span class="marke">Umbuchung</span>
                                    <?php elseif ($p->fehltBeleg()): ?>
                                        <span class="marke marke-warnung"><?= e($p->buchung->docStatus->label()) ?></span>
                                    <?php else: ?>
                                        <?= e($p->buchung->docStatus->label()) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($p->zuordnungen as $z): ?>
                                        <div><?= e($z->bezeichnung()) ?>, <?= e($geld($z->zuordnung->amount, $p->buchung->currency)) ?></div>
                                    <?php endforeach; ?>
                                    <?php if ($p->zuordnungUnlesbar): ?>
                                        <div class="marke marke-warnung">Zuordnung nicht lesbar</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
