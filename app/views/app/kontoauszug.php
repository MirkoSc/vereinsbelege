<?php

/**
 * One statement import (M9-4, issue #62, docs/spec/04-bank-und-abgleich.md
 * section 4): the preview before anything is written, the running step
 * chain, or the result.
 *
 * Only components from /admin/designsystem; the tables turn into cards on
 * narrow screens (.tabelle-karten), so nothing scrolls sideways at 360 px.
 * The preview needs no
 * JavaScript; the running chain is driven by public/js/kontoauszug.js (CSP:
 * no inline script - the CSRF token and the counts come from data-*
 * attributes on #kontoauszug).
 *
 * @var string $csrf
 * @var int $id
 * @var \App\Service\Bank\Import\ImportVorschau|null $vorschau null when the stored file could not be read
 * @var list<\App\Domain\BankAccount> $konten active bank accounts, for "Welches Konto?"
 * @var string|null $fehler
 */

use App\Domain\BalanceCheck;
use App\Domain\BankImportStatus;
use App\Service\Bank\Import\Saldenpruefpunkt;
use App\Service\Processing\Betrag;

$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
$saldoMarke = static fn(?BalanceCheck $b): string => match ($b) {
    BalanceCheck::Ok => 'marke-ok',
    BalanceCheck::Abweichung => 'marke-warnung',
    default => '',
};
$aktion = static fn(string $was): string => '/app/konten/import/' . $id . '/' . $was;
?>
<section>
    <h2>Kontoauszug-Import</h2>

    <p><a href="/app/konten/import">← Alle Importe</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if ($vorschau === null): ?>
        <form method="post" action="<?= e($aktion('verwerfen')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-gefahr">Vorschau verwerfen</button>
            </p>
        </form>
    <?php else: ?>
        <?php
        $import = $vorschau->import;
        $status = $import->status;
        $z = $vorschau->zaehler;
        [$von, $bis] = $vorschau->datei->zeitraum() ?? [null, null];
        $saldo = $status === BankImportStatus::Vorschau ? $vorschau->salden->ergebnis : $import->balanceCheck;
        ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <caption>Datei</caption>
                <tbody>
                    <tr><th scope="row">Datei</th><td><?= e($vorschau->dateiname) ?></td></tr>
                    <tr><th scope="row">Format</th><td><?= e($vorschau->datei->format->istMt940() ? 'MT940' : 'CSV') ?></td></tr>
                    <tr>
                        <th scope="row">Konto</th>
                        <td><?= $vorschau->konto === null ? '<span class="marke marke-warnung">noch nicht gewählt</span>' : e($vorschau->konto->data->name) ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Zeitraum</th>
                        <td><?= $von === null || $bis === null ? '<span class="gedaempft">–</span>' : e($von->format('d.m.Y')) . ' – ' . e($bis->format('d.m.Y')) ?></td>
                    </tr>
                    <tr><th scope="row">Status</th><td><?= e($status->label()) ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php if ($status === BankImportStatus::Vorschau && $vorschau->konto === null): ?>
            <h3>Welches Konto?</h3>
            <p class="gedaempft">
                <?= $vorschau->datei->konto === null
                    ? 'Die Datei nennt ihr Konto nicht.'
                    : 'Das Konto der Datei ist bei keinem Bankkonto hinterlegt. Die Wahl wird gemerkt: weitere Dateien mit derselben Kontoangabe landen von selbst dort.' ?>
            </p>
            <?php if ($konten === []): ?>
                <p class="hinweis hinweis-info">Es gibt kein aktives Bankkonto. Bitte zuerst unter <a href="/app/konten">Konten</a> anlegen.</p>
            <?php else: ?>
                <form method="post" action="<?= e($aktion('konto')) ?>" class="formular">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label for="import-konto">Konto <span class="pflicht" aria-hidden="true">*</span>
                        <select id="import-konto" name="konto" required>
                            <option value="">Bitte wählen</option>
                            <?php foreach ($konten as $konto): ?>
                                <option value="<?= e((string) $konto->id) ?>"><?= e($konto->data->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="knopfreihe"><button type="submit" class="knopf knopf-primaer">Konto übernehmen</button></p>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($status === BankImportStatus::Laeuft): ?>
            <div id="kontoauszug" data-csrf="<?= e($csrf) ?>" data-id="<?= e((string) $id) ?>"
                 data-verarbeitet="<?= e((string) $import->nextIndex) ?>" data-gesamt="<?= e((string) $z->gesamt) ?>">
                <p class="hinweis hinweis-info" id="kontoauszug-stand" aria-live="polite">
                    Die Buchungen werden übernommen: <?= e((string) $import->nextIndex) ?> von <?= e((string) $z->gesamt) ?>.
                </p>
                <noscript>
                    <p class="hinweis hinweis-warnung">Das Übernehmen braucht JavaScript. Bitte aktivieren und die Seite neu laden.</p>
                </noscript>
                <p class="hinweis hinweis-fehler" id="kontoauszug-fehler" role="alert" hidden></p>
                <p class="knopfreihe">
                    <button type="button" class="knopf" id="kontoauszug-weiter" hidden>Fortsetzen</button>
                </p>
            </div>
        <?php elseif ($status === BankImportStatus::Fertig): ?>
            <p class="hinweis hinweis-ok">
                Übernommen: <?= e((string) $z->neu) ?> <?= $z->neu === 1 ? 'neue Buchung' : 'neue Buchungen' ?>, <?= e((string) $z->duplikat) ?> waren schon vorhanden.
            </p>
        <?php endif; ?>

        <h3>Buchungen</h3>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <caption><?= $status === BankImportStatus::Fertig ? 'Ergebnis' : 'Was der Import tut' ?></caption>
                <tbody>
                    <tr><th scope="row">Buchungen in der Datei</th><td class="zahl"><?= e((string) $z->gesamt) ?></td></tr>
                    <?php if ($vorschau->konto !== null): ?>
                        <tr><th scope="row"><?= $status === BankImportStatus::Fertig ? 'neu übernommen' : 'davon neu' ?></th><td class="zahl"><?= e((string) $z->neu) ?></td></tr>
                        <tr><th scope="row">davon schon vorhanden (Duplikat)</th><td class="zahl"><?= e((string) $z->duplikat) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($z->vorStichtag > 0): ?>
                        <tr>
                            <th scope="row">davon vor dem Stichtag <?= $vorschau->konto === null ? '' : e($vorschau->konto->openingDate->format('d.m.Y')) ?></th>
                            <td class="zahl"><?= e((string) $z->vorStichtag) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($z->fehler > 0): ?>
                        <tr><th scope="row">fehlerhafte Zeilen (nicht übernommen)</th><td class="zahl"><span class="marke marke-fehler"><?= e((string) $z->fehler) ?></span></td></tr>
                    <?php endif; ?>
                    <?php if ($z->vorgemerkt > 0): ?>
                        <tr><th scope="row">vorgemerkte Umsätze (übersprungen)</th><td class="zahl"><?= e((string) $z->vorgemerkt) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($z->vorStichtag > 0): ?>
            <p class="feld-hilfe">
                Buchungen vor dem Stichtag des Anfangssaldos werden mit übernommen, zählen aber nicht zum Kontostand ab
                dem Stichtag.
            </p>
        <?php endif; ?>
        <?php if ($z->vorgemerkt > 0): ?>
            <p class="feld-hilfe">Vorgemerkte Umsätze sind noch nicht gebucht und kommen mit dem nächsten Export wieder.</p>
        <?php endif; ?>

        <?php if ($vorschau->datei->fehler !== []): ?>
            <div class="hinweis hinweis-warnung">
                <p>Diese Zeilen passen nicht zum CSV-Format und werden <strong>nicht</strong> übernommen:</p>
                <ul>
                    <?php foreach ($vorschau->datei->fehler as $zeilenfehler): ?>
                        <li><?= e($zeilenfehler->meldung) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p>Ist das Format korrigiert, kommen sie mit einem neuen Import derselben Datei nach – alles andere erkennt der Import als schon vorhanden.</p>
            </div>
        <?php endif; ?>

        <h3>Saldenprüfung</h3>
        <p>
            <?php if ($saldo === null): ?>
                <span class="gedaempft">–</span>
            <?php else: ?>
                <span class="marke <?= e($saldoMarke($saldo)) ?>"><?= e($saldo->label()) ?></span>
            <?php endif; ?>
        </p>
        <?php
        $punkte = $vorschau->salden->punkte;
        if ($vorschau->salden->anschluss !== null) {
            $punkte[] = $vorschau->salden->anschluss;
        }
        ?>
        <?php if ($punkte !== []): ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption>Anfangssaldo + Umsätze = Schlusssaldo</caption>
                    <thead>
                        <tr>
                            <th scope="col">Prüfung</th>
                            <th scope="col" class="zahl">Anfang</th>
                            <th scope="col" class="zahl">Umsätze</th>
                            <th scope="col" class="zahl">Schluss</th>
                            <th scope="col" class="zahl">Differenz</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($punkte as $punkt): ?>
                            <?php /** @var Saldenpruefpunkt $punkt */ ?>
                            <tr>
                                <th scope="row" data-label="Prüfung"><?= e($punkt->bezeichnung) ?></th>
                                <td class="zahl tabelle-kurz" data-label="Anfang"><?= $betrag($punkt->anfangCent) ?></td>
                                <td class="zahl tabelle-kurz" data-label="Umsätze"><?= $betrag($punkt->umsaetzeCent) ?></td>
                                <td class="zahl tabelle-kurz" data-label="Schluss"><?= $betrag($punkt->schlussCent) ?></td>
                                <td class="zahl tabelle-kurz" data-label="Differenz">
                                    <?php if ($punkt->stimmt()): ?>
                                        <span class="marke marke-ok">stimmt</span>
                                    <?php elseif (!$punkt->waehrungStimmt): ?>
                                        <span class="marke marke-warnung">andere Währung</span>
                                    <?php else: ?>
                                        <span class="marke marke-warnung"><?= $betrag($punkt->differenzCent()) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($vorschau->salden->anschluss !== null): ?>
                <p class="feld-hilfe">
                    „Anschluss“ vergleicht den Anfangsstand der Datei mit dem Kontostand, den die Anwendung bis dahin kennt
                    (Anfangssaldo und bisher übernommene Buchungen). Eine Differenz heißt meist: ein Zeitraum davor fehlt noch.
                </p>
            <?php endif; ?>
        <?php elseif ($saldo === BalanceCheck::NichtVerfuegbar): ?>
            <p class="gedaempft">Diese Datei enthält keine Salden – geprüft werden kann nur ein Export mit Anfangs- und Schlusssaldo (MT940) oder mit einer Spalte „Saldo nach Buchung“.</p>
        <?php endif; ?>
        <?php if ($vorschau->anschlussHinweis !== null): ?>
            <p class="gedaempft"><?= e($vorschau->anschlussHinweis) ?></p>
        <?php endif; ?>

        <?php if ($vorschau->posten !== []): ?>
            <details>
                <summary>Alle <?= e((string) count($vorschau->posten)) ?> Buchungen anzeigen</summary>
                <div class="tabelle-rahmen">
                    <table class="tabelle tabelle-karten">
                        <caption>Buchungen der Datei, älteste zuerst</caption>
                        <thead>
                            <tr>
                                <th scope="col">Buchungstag</th>
                                <th scope="col" class="zahl">Betrag</th>
                                <th scope="col">Gegenseite</th>
                                <th scope="col">Verwendungszweck</th>
                                <?php if ($status === BankImportStatus::Vorschau): ?>
                                    <th scope="col">Import</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vorschau->posten as $zeile): ?>
                                <?php $u = $zeile->posten->umsatz; ?>
                                <tr>
                                    <td class="tabelle-kurz" data-label="Buchungstag"><?= e($u->buchungsdatum->format('d.m.Y')) ?></td>
                                    <td class="zahl tabelle-kurz" data-label="Betrag"><?= $betrag($u->cent) ?></td>
                                    <td data-label="Gegenseite"><?= e($u->details->name ?? '') ?></td>
                                    <td data-label="Verwendungszweck"><?= e(mb_strimwidth($u->details->verwendungszweck ?? '', 0, 80, '…')) ?></td>
                                    <?php if ($status === BankImportStatus::Vorschau): ?>
                                        <td class="tabelle-kurz" data-label="Import">
                                            <?php if ($zeile->duplikat === true): ?>
                                                <span class="marke">schon vorhanden</span>
                                            <?php elseif ($zeile->duplikat === false): ?>
                                                <span class="marke marke-ok">neu</span>
                                            <?php endif; ?>
                                            <?php if ($zeile->vorStichtag): ?>
                                                <span class="marke marke-warnung">vor Stichtag</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($status === BankImportStatus::Vorschau): ?>
            <div class="knopfreihe">
                <?php if ($vorschau->uebernehmbar()): ?>
                    <form method="post" action="<?= e($aktion('uebernehmen')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="knopf knopf-primaer">
                            <?= $vorschau->hatWarnungen() ? 'Trotz Warnungen übernehmen' : 'Übernehmen' ?>
                            (<?= e((string) $z->neu) ?> <?= $z->neu === 1 ? 'neue Buchung' : 'neue Buchungen' ?>)
                        </button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= e($aktion('verwerfen')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="knopf knopf-still">Verwerfen</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
