<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * The state of everything report input is built from (issue #72): changes
 * whenever any of it changes. AuswertungsCache keeps an entry only while
 * the fingerprint it was built under still holds.
 */
interface Datenstand
{
    /**
     * An opaque value; null when it cannot be determined - nothing is
     * cached then.
     */
    public function fingerabdruck(): ?string;
}
