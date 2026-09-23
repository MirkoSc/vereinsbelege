<?php

/**
 * Status badge of one inbox entry (issue #27/M4-5), shared by the list and
 * the detail page. A Wiedervorlage names its date and turns into a warning
 * once that date has come.
 *
 * @var \App\Domain\Document $document
 * @var \DateTimeImmutable $heute
 */

use App\Domain\DocumentStatus;

$faellig = $document->status === DocumentStatus::Wiedervorlage
    && $document->resubmitOn !== null
    && $document->resubmitOn->format('Y-m-d') <= $heute->format('Y-m-d');
$klasse = match ($document->status) {
    DocumentStatus::Eingegangen => 'marke',
    DocumentStatus::Wiedervorlage => $faellig ? 'marke marke-warnung' : 'marke',
    DocumentStatus::Abgelehnt, DocumentStatus::KiFehler => 'marke marke-fehler',
    default => 'marke marke-ok',
};
?>
<span class="<?= e($klasse) ?>"><?= e($document->status->bezeichnung()) ?><?php
    if ($document->status === DocumentStatus::Wiedervorlage && $document->resubmitOn !== null) {
        echo e(' ab ' . $document->resubmitOn->format('d.m.Y'));
    }
?></span>
