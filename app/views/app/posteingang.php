<?php

/**
 * The inbox list (issue #27/M4-5): filters, then one row per submission,
 * newest first. Only components from /admin/designsystem; the table
 * scrolls sideways on narrow screens like every other table. A plain GET
 * form - no script needed, and the filtered list has its own URL.
 *
 * Name, description and refund are vault ciphertext: without an unlocked
 * vault the columns read "verschlüsselt", and the search is not offered.
 *
 * @var \App\Service\Inbox\InboxFilter $filter
 * @var list<\App\Service\Inbox\InboxAnsicht> $ansichten
 * @var array<int, string> $kostenstellen active ones, for the filter
 * @var array<int, string> $alleKostenstellen every one, for the rows
 * @var list<\App\Service\Inbox\InboxEintrag> $eintraege
 * @var bool $abgeschnitten
 * @var bool $entsperrt
 * @var \DateTimeImmutable $heute
 * @var list<string> $scripts
 * @var string $pdfjsSrc
 * @var string $pdfjsWorkerSrc
 * @var string $pdfjsWasmSrc
 * @var string $csrf
 */

use App\Service\Inbox\InboxFilter;

$kurz = static fn(string $text, int $max = 60): string => mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
?>
<section>
    <h2>Posteingang</h2>

    <p class="gedaempft">
        Alles, was über „Beleg einreichen“ hereinkommt. Annehmen gibt die Einreichung zur Auswertung frei;
        Ablehnen braucht einen Grund, die Einreichung bleibt erhalten; eine Wiedervorlage taucht am
        gewählten Tag wieder unter „Offen“ auf.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Namen, Beschreibungen und Belegbilder sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um sie zu sehen.
        </p>
    <?php endif; ?>

    <form method="get" action="/app/posteingang" class="formular">
        <label for="posteingang-ansicht">Anzeigen
            <select id="posteingang-ansicht" name="ansicht">
                <?php foreach ($ansichten as $ansicht): ?>
                    <option value="<?= e($ansicht->value) ?>"<?= $filter->ansicht === $ansicht ? ' selected' : '' ?>><?= e($ansicht->bezeichnung()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="posteingang-kostenstelle">Mannschaft/Bereich
            <select id="posteingang-kostenstelle" name="kostenstelle">
                <option value="">Alle</option>
                <option value="<?= e(InboxFilter::OHNE_KOSTENSTELLE) ?>"<?= $filter->kostenstelle === 0 ? ' selected' : '' ?>>Ohne Zuordnung</option>
                <?php foreach ($kostenstellen as $id => $name): ?>
                    <option value="<?= e((string) $id) ?>"<?= $filter->kostenstelle === $id ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="posteingang-von" class="feld-kurz">Eingang von
            <input type="date" id="posteingang-von" name="von" value="<?= e($filter->von?->format('Y-m-d') ?? '') ?>">
        </label>

        <label for="posteingang-bis" class="feld-kurz">bis
            <input type="date" id="posteingang-bis" name="bis" value="<?= e($filter->bis?->format('Y-m-d') ?? '') ?>">
        </label>

        <?php if ($entsperrt): ?>
            <label for="posteingang-suche">Suche in Name und Beschreibung
                <input type="search" id="posteingang-suche" name="suche" maxlength="100" value="<?= e($filter->suche) ?>">
            </label>
        <?php endif; ?>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Filtern</button>
            <?php if ($filter->toQuery() !== []): ?>
                <a class="knopf" href="/app/posteingang">Filter zurücksetzen</a>
            <?php endif; ?>
        </p>
    </form>

    <?php if ($eintraege === []): ?>
        <div class="leer">
            <?= $filter->eingeschraenkt() ? 'Keine passenden Einreichungen.' : match ($filter->ansicht) {
                \App\Service\Inbox\InboxAnsicht::Offen => 'Nichts zu tun – der Posteingang ist leer.',
                default => 'Keine Einreichungen in dieser Ansicht.',
            } ?>
        </div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption><?= e($filter->ansicht->bezeichnung()) ?>, neueste zuerst</caption>
                <thead>
                    <tr>
                        <th scope="col">Referenz</th>
                        <th scope="col">Eingang</th>
                        <th scope="col">Status</th>
                        <th scope="col">Name</th>
                        <th scope="col">Worum geht es?</th>
                        <th scope="col">Erstattung</th>
                        <th scope="col">Mannschaft/Bereich</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eintraege as $eintrag): ?>
                        <?php
                        $document = $eintrag->item->document;
                        $daten = $eintrag->daten;
                        ?>
                        <tr>
                            <td>
                                <a href="/app/posteingang/<?= e((string) $document->id) ?>"><?= e($eintrag->item->referenz ?? '#' . $document->id) ?></a>
                            </td>
                            <td><?= e($eintrag->item->eingegangenAm?->format('d.m.Y H:i') ?? '–') ?></td>
                            <td><?php require __DIR__ . '/posteingang-status.php'; ?></td>
                            <?php if ($daten === null): ?>
                                <td colspan="3"><span class="gedaempft">verschlüsselt</span></td>
                            <?php else: ?>
                                <td><?= e($kurz($daten->name, 40)) ?></td>
                                <td><?= e($kurz($daten->freitext, 40)) ?></td>
                                <td><?= e(match ($daten->erstattung) {
                                    \App\Domain\Erstattungsart::Ueberweisung => 'Überweisung',
                                    \App\Domain\Erstattungsart::Bar => 'Bar',
                                    \App\Domain\Erstattungsart::Keine => 'Keine',
                                    null => '–',
                                }) ?></td>
                            <?php endif; ?>
                            <td><?= e($document->costCenterId === null ? '–' : ($alleKostenstellen[$document->costCenterId] ?? '?')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($abgeschnitten): ?>
            <p class="gedaempft">Es gibt weitere, ältere Einreichungen – bitte über Zeitraum oder Suche eingrenzen.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/rasterung-mount.php'; ?>
</section>
