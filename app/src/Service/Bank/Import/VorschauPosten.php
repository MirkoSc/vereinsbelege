<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

/**
 * One booking of the import preview (M9-4, issue #62): the posting and
 * what the import would do with it. Session only, never stored.
 */
final readonly class VorschauPosten
{
    /**
     * @param ?bool $duplikat whether the account already has it; null while
     *        no account is chosen
     */
    public function __construct(
        public ImportPosten $posten,
        public ?bool $duplikat,
        public bool $vorStichtag,
    ) {
    }
}
