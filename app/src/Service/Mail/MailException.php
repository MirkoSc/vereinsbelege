<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Thrown by anything in this namespace that fails to send or build a mail.
 *
 * Messages carry only structural information (an SMTP step name, a reply
 * code) - never a credential, a recipient address or a subject (CLAUDE.md
 * section 4). That is what lets the message travel all the way into an
 * admin flash message (App\Admin\MailController::test()).
 */
final class MailException extends \RuntimeException
{
}
