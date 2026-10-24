<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status of the Nextcloud filing of one export run (`export_run_upload`,
 * migrations/034_nextcloud.sql, issue #95).
 */
enum NextcloudAblageStatus: string
{
    /** Started; the browser drives the steps until every file is there. */
    case Laeuft = 'laeuft';

    /** Every file of the run is in the cloud, index.csv last. */
    case Fertig = 'fertig';

    public function label(): string
    {
        return match ($this) {
            self::Laeuft => 'läuft',
            self::Fertig => 'abgelegt',
        };
    }
}
