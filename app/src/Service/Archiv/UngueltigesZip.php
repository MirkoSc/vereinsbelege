<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * The uploaded file cannot be read as a ZIP at all (issue #77/M12-3). The
 * message is German and shown as it is - it never names a file inside the
 * archive, only what is wrong with the archive as a whole.
 */
final class UngueltigesZip extends \RuntimeException
{
}
