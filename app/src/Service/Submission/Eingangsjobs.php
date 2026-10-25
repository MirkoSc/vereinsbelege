<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\JobExecutor;
use App\Repository\JobRepository;
use App\Service\Document\Duplikatindex;
use App\Service\Document\PdfErzeugung;
use App\Service\Document\Texterkennung;

/**
 * The session jobs every newly submitted receipt needs (docs/spec/
 * 03-erfassung-und-ki.md section 5): the PDF working copy (issue #26/M4-4),
 * the content index of duplicate detection (issue #40/M6-6) and the text
 * layer of digital PDFs (issue #45/M7-3). All need an unlocked vault, so
 * none can run where the receipt arrives. Shared by the public submission
 * and the mail intake (issue #87), so both start the same chain.
 */
final class Eingangsjobs
{
    public static function einreihen(JobRepository $jobs, int $documentId, \DateTimeImmutable $now): void
    {
        foreach ([PdfErzeugung::JOB_TYP, Duplikatindex::JOB_TYP, Texterkennung::JOB_TYP] as $typ) {
            $jobs->enqueue($typ, JobExecutor::Session, 'document', $documentId, now: $now);
        }
    }
}
