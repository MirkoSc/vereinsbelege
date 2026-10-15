<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * An archive import cannot do what was asked (issue #77/M12-3): unknown,
 * not started, already finished. German message for the page.
 */
final class ArchivImportFehler extends \RuntimeException
{
}
