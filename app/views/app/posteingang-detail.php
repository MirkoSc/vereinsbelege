<?php

/**
 * One submission in the inbox (issue #27/M4-5): what the submitter entered,
 * the pages, and - for `document.edit` with an unlocked vault - the
 * decisions the status model allows from here (App\Domain\InboxAction).
 *
 * Pages stream decrypted from /app/posteingang/{id}/datei/{blob}. Images
 * show inline; a PDF opens in the browser's own viewer in a new tab - the
 * CSP (object-src 'none', frame-ancestors 'none') rules out embedding it.
 * Page images of a PDF original are rendered by `public/js/rasterung.js`
 * with pdf.js (issue #30/M4-8) while this page or the inbox list is open;
 * this view does not show them yet (no gallery here), only mounts the
 * script (rasterung-mount.php).
 *
 * @var \App\Service\Inbox\InboxEintrag $eintrag
 * @var list<array{blobId: int, mime: string, seite: int, pdf: bool}> $seiten
 * @var list<\App\Domain\InboxAction> $aktionen
 * @var bool $darfEntscheiden
 * @var array<int, string> $kostenstellen active ones, plus the document's own
 * @var array<int, string> $alleKostenstellen
 * @var bool $entsperrt
 * @var \DateTimeImmutable $heute
 * @var string $csrf
 * @var list<string> $scripts
 * @var string $pdfjsSrc
 * @var string $pdfjsWorkerSrc
 * @var string $pdfjsWasmSrc
 */

use App\Domain\DocumentStatus;
use App\Domain\Erstattungsart;
use App\Domain\Iban;
use App\Domain\InboxAction;
use App\Service\Inbox\Posteingang;
use App\Service\Upload\MagicBytes;

$document = $eintrag->item->document;
$daten = $eintrag->daten;
$basis = '/app/posteingang/' . $document->id;
$referenz = $eintrag->item->referenz ?? '#' . $document->id;
?>
<section>
    <p><a href="/app/posteingang">← Zurück zum Posteingang</a></p>

    <h2>Einreichung <?= e($referenz) ?></h2>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Angaben und Belegbilder sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um sie zu sehen und über die Einreichung zu entscheiden.
        </p>
    <?php endif; ?>

    <div class="karte posteingang-angaben">
        <dl>
            <dt>Status</dt>
            <dd><?php require __DIR__ . '/posteingang-status.php'; ?></dd>

            <dt>Eingegangen</dt>
            <dd><?= e($eintrag->item->eingegangenAm?->format('d.m.Y H:i') ?? '–') ?></dd>

            <dt>Mannschaft/Bereich</dt>
            <dd><?= e($document->costCenterId === null ? 'keine Angabe' : ($alleKostenstellen[$document->costCenterId] ?? '?')) ?></dd>

            <?php if ($daten !== null): ?>
                <dt>Name</dt>
                <dd><?= e($daten->name) ?></dd>

                <dt>E-Mail</dt>
                <dd><?= $daten->email === null ? '–' : e($daten->email) ?></dd>

                <dt>Erstattung</dt>
                <dd>
                    <?= e($daten->erstattung?->bezeichnung() ?? '–') ?>
                    <?php if ($daten->erstattung === Erstattungsart::Ueberweisung): ?>
                        <br><span class="klein">IBAN <?= e($daten->iban === null ? '–' : Iban::formatieren($daten->iban)) ?></span>
                        <br><span class="klein">Kontoinhaber <?= e($daten->kontoinhaber ?? '–') ?></span>
                    <?php endif; ?>
                </dd>

                <dt>Worum geht es?</dt>
                <dd class="posteingang-freitext"><?= e($daten->freitext) ?></dd>
            <?php elseif ($entsperrt): ?>
                <dt>Angaben</dt>
                <dd class="gedaempft">Nicht lesbar (älterer Tresor oder beschädigt).</dd>
            <?php endif; ?>

            <?php if ($eintrag->notiz !== null): ?>
                <dt><?= $document->status === DocumentStatus::Abgelehnt ? 'Grund der Ablehnung' : 'Notiz' ?></dt>
                <dd class="posteingang-freitext"><?= e($eintrag->notiz) ?></dd>
            <?php elseif ($document->statusNoteEnc !== null && !$entsperrt): ?>
                <dt><?= $document->status === DocumentStatus::Abgelehnt ? 'Grund der Ablehnung' : 'Notiz' ?></dt>
                <dd class="gedaempft">verschlüsselt</dd>
            <?php endif; ?>
        </dl>
    </div>

    <?php if ($entsperrt): ?>
        <h3>Seiten</h3>
        <?php if ($seiten === []): ?>
            <div class="leer">Keine Seiten lesbar.</div>
        <?php else: ?>
            <ul class="posteingang-seiten">
                <?php foreach ($seiten as $seite): ?>
                    <?php $url = $basis . '/datei/' . $seite['blobId']; ?>
                    <li class="posteingang-seite">
                        <?php if ($seite['mime'] === MagicBytes::PDF): ?>
                            <a class="knopf" href="<?= e($url) ?>" target="_blank" rel="noopener">
                                <?= $seite['pdf'] ? 'Aufbereitetes PDF öffnen' : e('PDF öffnen (Seite ' . $seite['seite'] . ')') ?>
                            </a>
                        <?php else: ?>
                            <a href="<?= e($url) ?>" target="_blank" rel="noopener">
                                <img src="<?= e($url) ?>" alt="<?= e('Seite ' . $seite['seite']) ?>" loading="lazy">
                            </a>
                            <span class="klein gedaempft">Seite <?= e((string) $seite['seite']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($aktionen !== []): ?>
        <h3>Entscheiden</h3>

        <?php if (in_array(InboxAction::Annehmen, $aktionen, true)): ?>
            <form method="post" action="<?= e($basis) ?>/annehmen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="gedaempft">Gibt die Einreichung zur Auswertung frei.</p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer">Annehmen</button>
                </p>
            </form>
        <?php endif; ?>

        <?php if (in_array(InboxAction::Wiedervorlage, $aktionen, true)): ?>
            <form method="post" action="<?= e($basis) ?>/wiedervorlage" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <fieldset>
                    <legend>Wiedervorlage</legend>
                    <label for="wiedervorlage-datum" class="feld-kurz">Wieder vorlegen am <span class="pflicht">*</span>
                        <input type="date" id="wiedervorlage-datum" name="datum" required
                               min="<?= e($heute->format('Y-m-d')) ?>"
                               value="<?= e($heute->modify('+7 days')->format('Y-m-d')) ?>">
                    </label>
                    <label for="wiedervorlage-notiz">Notiz (optional)
                        <textarea id="wiedervorlage-notiz" name="notiz" maxlength="<?= e((string) Posteingang::NOTIZ_MAX) ?>"></textarea>
                    </label>
                    <p class="knopfreihe">
                        <button type="submit" class="knopf">Auf Wiedervorlage legen</button>
                    </p>
                </fieldset>
            </form>
        <?php endif; ?>

        <?php if (in_array(InboxAction::Ablehnen, $aktionen, true)): ?>
            <form method="post" action="<?= e($basis) ?>/ablehnen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <fieldset>
                    <legend>Ablehnen</legend>
                    <label for="ablehnen-grund">Grund <span class="pflicht">*</span>
                        <textarea id="ablehnen-grund" name="grund" required maxlength="<?= e((string) Posteingang::NOTIZ_MAX) ?>"></textarea>
                    </label>
                    <p class="gedaempft klein">Die Einreichung bleibt erhalten und ist unter „Abgelehnt“ zu finden.</p>
                    <p class="knopfreihe">
                        <button type="submit" class="knopf knopf-gefahr">Ablehnen</button>
                    </p>
                </fieldset>
            </form>
        <?php endif; ?>
    <?php elseif ($darfEntscheiden && $entsperrt && $document->status->istEndzustand()): ?>
        <p class="gedaempft">Über diese Einreichung ist entschieden – sie lässt sich nicht mehr ändern.</p>
    <?php endif; ?>

    <?php if ($darfEntscheiden && $document->status !== DocumentStatus::Festgeschrieben): ?>
        <form method="post" action="<?= e($basis) ?>/kostenstelle" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label for="posteingang-kostenstelle">Mannschaft/Bereich
                <select id="posteingang-kostenstelle" name="kostenstelle">
                    <option value="">keine Angabe</option>
                    <?php foreach ($kostenstellen as $id => $name): ?>
                        <option value="<?= e((string) $id) ?>"<?= $document->costCenterId === $id ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="knopfreihe">
                <button type="submit" class="knopf">Zuordnung speichern</button>
            </p>
        </form>
    <?php endif; ?>

    <?php require __DIR__ . '/rasterung-mount.php'; ?>
</section>
