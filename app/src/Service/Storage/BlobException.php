<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * A blob could not be stored, found or read. Carries technical reasons only -
 * no file name, no content, nothing from the receipt itself, because this
 * message may end up in the log.
 */
final class BlobException extends \RuntimeException
{
}
