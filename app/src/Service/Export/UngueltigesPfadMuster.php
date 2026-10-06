<?php

declare(strict_types=1);

namespace App\Service\Export;

/**
 * A path pattern the export cannot use (issue #75/M12-1). The message is
 * German and meant for the admin page - it names the problem in the
 * pattern, which holds no business data.
 */
final class UngueltigesPfadMuster extends \InvalidArgumentException
{
}
