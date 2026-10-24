<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * A Nextcloud target the form cannot take; the message is German and meant
 * for the page (App\Service\Export\Nextcloud\NextcloudZiel::pruefen()).
 */
final class NextcloudZielFehler extends \RuntimeException
{
}
