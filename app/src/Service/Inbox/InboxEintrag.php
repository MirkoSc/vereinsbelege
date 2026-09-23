<?php

declare(strict_types=1);

namespace App\Service\Inbox;

use App\Domain\InboxItem;

/**
 * One inbox entry as the pages show it (issue #27/M4-5): the stored item
 * and, when the vault was unlocked, the decrypted submission and status
 * note. `daten` is null without a vault - the list still shows reference,
 * date, status and cost center, which are plaintext.
 */
final readonly class InboxEintrag
{
    public function __construct(
        public InboxItem $item,
        public ?EinreichungsDaten $daten,
        public ?string $notiz,
    ) {
    }
}
