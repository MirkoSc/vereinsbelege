<?php

/**
 * One bank account or cash box (M9-1, issue #59, docs/spec/
 * 04-bank-und-abgleich.md section 1): the form to create or change it for
 * whoever holds `bank.book`; everyone else with `bank.view` gets the same
 * fields disabled, without a form to send (like the review page shows a
 * checked receipt). A cash box also lists its cash counts.
 *
 * Only components from /admin/designsystem, no JavaScript. Which fields a
 * new account gets follows from its kind, chosen on the list page
 * (?art=bank|kasse) - a cash box has no bank connection.
 *
 * @var string $csrf
 * @var \App\Domain\BankAccountKind $art
 * @var \App\Domain\BankAccount|null $konto null for a new one
 * @var array<string, string> $felder
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 * @var int|null $konfliktId the other account of a duplicate IBAN
 * @var bool $darfPflegen holds `bank.book`
 * @var bool $saldoVerborgen the opening date lies outside the reader's period: no amount shown
 * @var int $verwendungen
 * @var list<\App\Domain\CashCount> $kassenstuerze within the reader's scope, newest first
 * @var int $offeneDifferenz counted minus the books for the latest count, in cents (0 = nothing to book)
 */

use App\Domain\BankAccountKind;
use App\Service\Bank\BankAccountService;
use App\Service\Processing\Betrag;

$neu = $konto === null;
$kasse = $art === BankAccountKind::Kasse;
$ziel = $neu ? '/app/konten' : '/app/konten/' . $konto->id;
$saldoName = $kasse ? 'Anfangsbestand' : 'Anfangssaldo';
$gesperrt = $darfPflegen ? '' : ' disabled';

// aria-invalid and the pointer to the message for the field at fault.
$fehlerAn = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="konto-fehler"' : '';
$wert = static fn(string $feld): string => e($felder[$feld] ?? '');
$betrag = static fn(int $cent): string => e(Betrag::format($cent)) . ' €';
?>
<section class="schmal">
    <h2><?= e($neu ? ($kasse ? 'Neue Kasse' : 'Neues Bankkonto') : $konto->data->name) ?></h2>

    <p><a href="/app/konten">← Alle Konten</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="konto-fehler">
            <?= e($fehler) ?>
            <?php if ($konfliktId !== null): ?>
                <a href="/app/konten/<?= e((string) $konfliktId) ?>">Zum anderen Konto</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (!$neu && !$konto->active): ?>
        <p class="hinweis hinweis-info">
            <?= $kasse
                ? 'Diese Kasse ist deaktiviert. Die Daten bleiben erhalten, ein Kassensturz ist nicht mehr möglich.'
                : 'Dieses Konto ist deaktiviert. Die Daten bleiben erhalten.' ?>
        </p>
    <?php endif; ?>

    <?php if ($darfPflegen): ?>
        <form method="post" action="<?= e($ziel) ?>" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <?php if ($neu): ?>
                <input type="hidden" name="art" value="<?= e($art->value) ?>">
            <?php endif; ?>
    <?php else: ?>
        <div class="formular">
    <?php endif; ?>

            <label for="konto-name">Name <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="konto-name" name="name" value="<?= $wert('name') ?>"
                       maxlength="<?= e((string) BankAccountService::NAME_MAX) ?>" required<?= $fehlerAn('name') . $gesperrt ?>>
            </label>
            <?php if ($darfPflegen): ?>
                <p class="feld-hilfe">
                    <?= $kasse ? 'Zum Beispiel „Barkasse“ oder „Vereinsheim-Kasse“.' : 'Zum Beispiel „Girokonto Sparkasse“ oder „Sparbuch VR Bank“.' ?>
                </p>
            <?php endif; ?>

            <?php if (!$kasse): ?>
                <fieldset>
                    <legend>Bankverbindung</legend>
                    <label for="konto-iban">IBAN
                        <input type="text" id="konto-iban" name="iban" value="<?= $wert('iban') ?>" maxlength="42"
                               autocomplete="off" spellcheck="false"<?= $fehlerAn('iban') . $gesperrt ?>>
                    </label>
                    <?php if ($darfPflegen): ?>
                        <p class="feld-hilfe">
                            Leerzeichen sind egal. Über die IBAN ordnet der Kontoauszug-Import die Datei dem Konto zu;
                            eine IBAN gehört zu genau einem Konto.
                        </p>
                    <?php endif; ?>
                    <label for="konto-bic">BIC
                        <input type="text" id="konto-bic" name="bic" value="<?= $wert('bic') ?>" maxlength="20"
                               autocomplete="off" spellcheck="false"<?= $fehlerAn('bic') . $gesperrt ?>>
                    </label>
                    <label for="konto-bank">Bank
                        <input type="text" id="konto-bank" name="bank" value="<?= $wert('bank') ?>"
                               maxlength="<?= e((string) BankAccountService::BANK_MAX) ?>"<?= $fehlerAn('bank') . $gesperrt ?>>
                    </label>
                </fieldset>
            <?php endif; ?>

            <fieldset>
                <legend><?= e($saldoName) ?></legend>
                <label for="konto-saldo" class="feld-kurz"><?= e($saldoName) ?> (€) <span class="pflicht" aria-hidden="true">*</span>
                    <input type="text" id="konto-saldo" name="opening_balance" value="<?= $wert('opening_balance') ?>"
                           inputmode="decimal" required<?= $fehlerAn('opening_balance') . $gesperrt ?>>
                </label>
                <label for="konto-stichtag" class="feld-kurz">Stichtag <span class="pflicht" aria-hidden="true">*</span>
                    <input type="date" id="konto-stichtag" name="opening_date" value="<?= $wert('opening_date') ?>"
                           required<?= $fehlerAn('opening_date') . $gesperrt ?>>
                </label>
                <p class="feld-hilfe">
                    <?php if ($saldoVerborgen): ?>
                        Der Stichtag liegt außerhalb Ihres Zeitraums – der <?= e($saldoName) ?> wird deshalb nicht angezeigt.
                    <?php else: ?>
                        <?= $kasse
                            ? 'Bargeld in der Kasse zu Beginn des Stichtags. Kassenbuchungen ab diesem Tag kommen hinzu.'
                            : 'Kontostand zu Beginn des Stichtags, wie auf dem Kontoauszug. Buchungen ab diesem Tag kommen hinzu.' ?>
                    <?php endif; ?>
                </p>
            </fieldset>

            <?php if (!$neu): ?>
                <label class="feld-ankreuz">
                    <input type="checkbox" name="active" value="1"<?= ($felder['active'] ?? '') === '1' ? ' checked' : '' ?><?= $gesperrt ?>>
                    <?= $kasse ? 'Kasse ist in Gebrauch (aktiv)' : 'Konto ist in Gebrauch (aktiv)' ?>
                </label>
                <?php if ($darfPflegen): ?>
                    <p class="feld-hilfe">
                        Ein deaktiviertes Konto bleibt mit allen Daten erhalten, wird aber nicht mehr angeboten.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

    <?php if ($darfPflegen): ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Speichern</button>
                <a class="knopf" href="/app/konten">Abbrechen</a>
            </p>
        </form>
    <?php else: ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!$neu): ?>
    <section class="schmal">
        <h3>Buchungen</h3>
        <p class="knopfreihe">
            <a class="knopf" href="/app/buchungen?konto=<?= e((string) $konto->id) ?>">Buchungen <?= $kasse ? 'der Kasse' : 'des Kontos' ?></a>
            <?php if ($darfPflegen && $konto->active): ?>
                <a class="knopf knopf-primaer" href="/app/buchungen/neu?konto=<?= e((string) $konto->id) ?>"><?= $kasse ? 'Kassenbuchung erfassen' : 'Buchung erfassen' ?></a>
            <?php endif; ?>
        </p>
    </section>
<?php endif; ?>

<?php if (!$neu && $kasse): ?>
    <section>
        <h3>Kassenstürze</h3>
        <?php if ($offeneDifferenz !== 0): ?>
            <p class="hinweis hinweis-warnung">
                Beim letzten Kassensturz (<?= e($kassenstuerze[0]->countedOn->format('d.m.Y')) ?>) weicht der gezählte Bestand
                um <?= $betrag(abs($offeneDifferenz)) ?> von den Buchungen ab (<?= $offeneDifferenz < 0 ? 'Fehlbetrag' : 'Überschuss' ?>).
                <a href="/app/buchungen/neu?kassensturz=<?= e((string) $kassenstuerze[0]->id) ?>">Als Kassendifferenz buchen</a>
            </p>
        <?php endif; ?>
        <?php if ($darfPflegen && $konto->active): ?>
            <p class="knopfreihe">
                <a class="knopf knopf-primaer" href="/app/konten/<?= e((string) $konto->id) ?>/kassensturz">Kassensturz erfassen</a>
            </p>
        <?php endif; ?>
        <?php if ($kassenstuerze === []): ?>
            <div class="leer">Noch kein Kassensturz erfasst.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption><?= e((string) count($kassenstuerze)) ?> <?= count($kassenstuerze) === 1 ? 'Kassensturz' : 'Kassenstürze' ?>, neueste zuerst</caption>
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Ergebnis</th>
                            <th scope="col" class="zahl">Differenz</th>
                            <th scope="col" class="zahl">Soll</th>
                            <th scope="col" class="zahl">Gezählt</th>
                            <th scope="col">Notiz</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kassenstuerze as $zaehlung): ?>
                            <tr>
                                <td><?= e($zaehlung->countedOn->format('d.m.Y')) ?></td>
                                <td><span class="marke <?= e($zaehlung->ergebnis()->marke()) ?>"><?= e($zaehlung->ergebnis()->label()) ?></span></td>
                                <td class="zahl"><?= $betrag($zaehlung->differenz()) ?></td>
                                <td class="zahl"><?= $betrag($zaehlung->expected) ?></td>
                                <td class="zahl"><?= $betrag($zaehlung->counted) ?></td>
                                <td><?= $zaehlung->note === '' ? '<span class="gedaempft">–</span>' : e($zaehlung->note) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!$neu && $darfPflegen): ?>
    <section class="schmal">
        <p class="gedaempft">
            Angelegt am <?= e($konto->createdAt->format('d.m.Y')) ?>,
            zuletzt geändert am <?= e($konto->updatedAt->format('d.m.Y H:i')) ?>.
        </p>

        <h3><?= $kasse ? 'Kasse löschen' : 'Konto löschen' ?></h3>
        <?php if ($verwendungen > 0): ?>
            <p class="gedaempft">
                <?= $kasse ? 'Die Kasse' : 'Das Konto' ?> wird verwendet und lässt sich deshalb nicht löschen –
                stattdessen oben deaktivieren.
            </p>
        <?php else: ?>
            <form method="post" action="/app/konten/<?= e((string) $konto->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr"><?= $kasse ? 'Kasse löschen' : 'Konto löschen' ?></button>
                </p>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
