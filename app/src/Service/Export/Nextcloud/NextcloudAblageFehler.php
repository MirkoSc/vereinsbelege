<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * The Nextcloud filing cannot go on (App\Service\Export\Nextcloud\
 * NextcloudAblage); the message is German, for the page, and names no file.
 */
final class NextcloudAblageFehler extends \RuntimeException
{
}
