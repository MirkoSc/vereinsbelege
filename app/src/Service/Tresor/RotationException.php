<?php

declare(strict_types=1);

namespace App\Service\Tresor;

/**
 * A vault key rotation step that cannot happen right now (issue #90). The
 * message is German and meant for the admin page - it never carries data.
 */
final class RotationException extends \RuntimeException
{
}
