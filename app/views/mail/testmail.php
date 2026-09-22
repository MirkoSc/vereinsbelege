<?php

/**
 * Test mail sent from /admin/mail (issue #14). Purely technical - no
 * business content, like every mail template (CLAUDE.md section 4,
 * docs/spec/06-betrieb.md section 3).
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose.
 *
 * @var string $vereinsname
 * @var \DateTimeImmutable $zeit
 */
?>
Diese Testmail wurde über die Mail-Einstellungen der Verwaltung von <?= $vereinsname ?> ausgelöst.

Zeitpunkt: <?= $zeit->format('d.m.Y H:i') ?> Uhr

Wenn diese Mail ankommt, sind Server, Port, Verschlüsselung und Zugangsdaten richtig eingestellt.
