<?php

/**
 * Bank accounts and cash boxes (M9-1, issue #59, docs/spec/
 * 04-bank-und-abgleich.md section 1): one table per kind, active accounts
 * first, then by name.
 *
 * Only components from /admin/designsystem; the tables scroll sideways on
 * narrow screens like every other table. Names, IBANs and opening balances
 * are vault data: without an unlocked vault there is nothing to show.
 *
 * @var bool $entsperrt
 * @var array<string, list<\App\Domain\BankAccount>> $gruppen kind value => accounts
 * @var list<int> $saldoVerborgen accounts whose opening date lies outside the reader's period
 * @var bool $darfPflegen holds `bank.book`
 */

use App\Domain\BankAccountKind;
use App\Domain\Iban;
use App\Service\Processing\Betrag;

?>
<section>
    <h2>Konten</h2>

    <p class="gedaempft">
        Bankkonten und Barkassen des Vereins, jeweils mit dem Anfangssaldo zu einem Stichtag.
        Für eine Kasse wird hier auch der Kassensturz erfasst.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Namen, Bankverbindungen und Beträge sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um die Konten zu sehen.
        </p>
    <?php else: ?>
        <?php if ($darfPflegen): ?>
            <p class="knopfreihe">
                <a class="knopf knopf-primaer" href="/app/konten/neu?art=bank">Neues Bankkonto</a>
                <a class="knopf" href="/app/konten/neu?art=kasse">Neue Kasse</a>
            </p>
        <?php endif; ?>

        <?php foreach (BankAccountKind::cases() as $art): ?>
            <?php $konten = $gruppen[$art->value] ?? []; ?>
            <h3><?= e($art->gruppe()) ?></h3>
            <?php if ($konten === []): ?>
                <div class="leer"><?= $art === BankAccountKind::Kasse ? 'Noch keine Kasse angelegt.' : 'Noch kein Bankkonto angelegt.' ?></div>
            <?php else: ?>
                <div class="tabelle-rahmen">
                    <table class="tabelle">
                        <caption><?= e((string) count($konten)) ?> <?= e(count($konten) === 1 ? $art->label() : $art->gruppe()) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <?php if ($art->hatBankverbindung()): ?>
                                    <th scope="col">IBAN</th>
                                    <th scope="col">Bank</th>
                                <?php endif; ?>
                                <th scope="col" class="zahl">Anfangssaldo</th>
                                <th scope="col">Stichtag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($konten as $konto): ?>
                                <tr>
                                    <td>
                                        <a href="/app/konten/<?= e((string) $konto->id) ?>"><?= e($konto->data->name) ?></a>
                                        <?php if (!$konto->active): ?>
                                            <span class="marke">inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($art->hatBankverbindung()): ?>
                                        <td><?= $konto->data->iban === '' ? '<span class="gedaempft">–</span>' : e(Iban::formatieren($konto->data->iban)) ?></td>
                                        <td><?= $konto->data->bank === '' ? '<span class="gedaempft">–</span>' : e($konto->data->bank) ?></td>
                                    <?php endif; ?>
                                    <td class="zahl">
                                        <?php if (in_array($konto->id, $saldoVerborgen, true)): ?>
                                            <span class="gedaempft">außerhalb Ihres Zeitraums</span>
                                        <?php else: ?>
                                            <?= e(Betrag::format($konto->openingBalance)) ?> €
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($konto->openingDate->format('d.m.Y')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
