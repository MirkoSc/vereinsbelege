<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * One leaf part of a mail that may be an attachment (issue #87), decoded.
 * $typ is what the mail claims - the magic bytes decide (MailEingang).
 * $inline: shown in the mail body (a Content-ID, not marked as attachment)
 * - typically a logo in the signature.
 */
final readonly class MimeAnhang
{
    public function __construct(
        public string $name,
        public string $inhalt,
        public string $typ,
        public bool $inline,
    ) {
    }
}
