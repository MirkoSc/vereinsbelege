<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * One file inside the ZIP cannot be unpacked - wrong checksum, broken
 * deflate stream, larger than announced (issue #77/M12-3). The import skips
 * that file and goes on with the next one. German message, without the file
 * name: the caller knows which entry it was.
 */
final class ZipEintragDefekt extends \RuntimeException
{
}
