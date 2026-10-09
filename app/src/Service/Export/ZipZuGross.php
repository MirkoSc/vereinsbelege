<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * The archive would outgrow what a ZIP without ZIP64 can record: 4 GiB
 * or 65 535 files (App\Service\Export\ZipStrom). The export checks the
 * limits before the first byte; this is the guard for an estimate that was
 * wrong - the stream ends there instead of handing out a broken archive.
 */
final class ZipZuGross extends \RuntimeException
{
}
