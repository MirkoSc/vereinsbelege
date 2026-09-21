<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * An upload that was refused for a reason the caller may show. Everything
 * else (a full disk, a broken rename) stays an ordinary exception and becomes
 * a 500 - those are our fault, not the client's.
 */
final class UploadException extends \RuntimeException
{
    public function __construct(public readonly UploadError $error)
    {
        // The exception message is for the log, the case carries the text for
        // the browser. Keep them the same so a log line stays readable.
        parent::__construct($error->value);
    }
}
