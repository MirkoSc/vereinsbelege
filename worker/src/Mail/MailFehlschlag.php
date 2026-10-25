<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * Handing in one mail failed for a reason of that mail (issue #87): it is
 * retried with the next run and set aside after a few attempts, the run
 * goes on with the next mail. The message is an error code.
 */
final class MailFehlschlag extends \RuntimeException
{
}
