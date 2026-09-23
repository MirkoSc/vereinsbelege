<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * A verified form token (App\Service\Submission\FormToken): when it was
 * issued and the raw nonce it carries.
 *
 * hash() is what binds an upload to the visit that opened it
 * (`submission_upload.form_hash`, `submission.form_hash`): every request of
 * one page load carries the same token and therefore the same hash, without
 * the server having to remember anything about the token itself.
 */
final readonly class FormTokenData
{
    public function __construct(
        public string $nonce,
        public \DateTimeImmutable $issuedAt,
    ) {
    }

    /** @return string raw 32 bytes, for a BINARY(32) column */
    public function hash(): string
    {
        return hash('sha256', $this->nonce, true);
    }
}
