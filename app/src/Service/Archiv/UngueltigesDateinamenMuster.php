<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * The file name pattern of the archive import cannot be used (issue
 * #77/M12-3). German message for the import page, like
 * App\Service\Export\UngueltigesPfadMuster.
 */
final class UngueltigesDateinamenMuster extends \InvalidArgumentException
{
}
