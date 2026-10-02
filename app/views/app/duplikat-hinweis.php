<?php

/**
 * Suspected duplicate (issue #40/M6-6), shared by the inbox detail and the
 * review page: which other documents look like the same receipt and why,
 * then - for `document.edit` with the unlocked vault - the two ways to
 * resolve it. Nothing here is vault data: references, dates, statuses and
 * the reason are plaintext structure; a document outside the viewer's
 * scope is only counted.
 *
 * @var \App\Service\Document\DuplikatVerdacht $duplikat
 * @var string $duplikatBasis the page's URL; the actions post below it
 * @var bool $duplikatAufloesbar
 * @var string $csrf
 */

use App\Domain\DocumentStatus;
use App\Domain\DuplikatGrund;

if (!$duplikat->besteht()) {
    return;
}
?>
<div class="hinweis hinweis-warnung" id="duplikat-hinweis">
    <p><strong>Möglicherweise doppelt eingereicht.</strong> Dieser Beleg gleicht:</p>
    <ul>
        <?php foreach ($duplikat->sichtbar as $kandidat): ?>
            <li>
                <a href="/app/posteingang/<?= e((string) $kandidat->documentId) ?>"><?= e($kandidat->referenz ?? '#' . $kandidat->documentId) ?></a>
                <span class="klein gedaempft">vom <?= e($kandidat->eingegangenAm->format('d.m.Y')) ?></span>
                <span class="<?= e(match ($kandidat->status) {
                    DocumentStatus::Festgeschrieben, DocumentStatus::Geprueft => 'marke marke-ok',
                    DocumentStatus::KiFehler => 'marke marke-fehler',
                    default => 'marke',
                }) ?>"><?= e($kandidat->status->bezeichnung()) ?></span>
                – <?= e(implode(' und ', array_map(static fn(DuplikatGrund $g): string => $g->bezeichnung(), $kandidat->gruende))) ?>
            </li>
        <?php endforeach; ?>
        <?php if ($duplikat->ausserhalb > 0): ?>
            <li class="gedaempft"><?= e($duplikat->ausserhalb === 1
                ? 'einem Beleg außerhalb Ihres Bereichs'
                : sprintf('%d Belegen außerhalb Ihres Bereichs', $duplikat->ausserhalb)) ?></li>
        <?php endif; ?>
    </ul>
    <?php if ($duplikatAufloesbar): ?>
        <p class="klein">
            Ist es derselbe Beleg, diesen als Duplikat verwerfen – er wird mit Begründung abgelehnt und bleibt
            erhalten. Sind es verschiedene Belege, beide bewusst behalten.
        </p>
        <div class="knopfreihe">
            <form method="post" action="<?= e($duplikatBasis) ?>/duplikat-verwerfen">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf knopf-gefahr">Als Duplikat verwerfen</button>
            </form>
            <form method="post" action="<?= e($duplikatBasis) ?>/duplikat-behalten">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf">Bewusst behalten</button>
            </form>
        </div>
    <?php endif; ?>
</div>
