<?php

/**
 * Invitation link (issue #20/M3-7, docs/spec/01-sicherheit.md section 2
 * "Einladung": "Token 72 h"). Purely procedural - the link, its lifetime,
 * what comes next; no business content (CLAUDE.md section 4), and not even
 * who invited or which roles: a mail can be forwarded.
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose. $link is built
 * by App\Admin\UserController from the checked base URL
 * (App\Service\Mail\PublicUrl) and a hex token - nothing user-typed.
 *
 * @var string $link
 * @var int $gueltigStunden
 * @var string $vereinsname
 */
?>
Sie wurden zur Belegverwaltung von <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?> eingeladen.

Über diesen Link legen Sie Ihr Passwort fest:

<?= $link ?>


Der Link ist <?= $gueltigStunden ?> Stunden gültig und kann nur einmal verwendet werden.

Bei der ersten Anmeldung richten Sie einen zweiten Faktor ein (App oder Code per E-Mail).
Belege sehen Sie, sobald eine Administratorin oder ein Administrator Ihren Zugang für den
Tresor freigegeben hat. Erwarten Sie diese Einladung nicht, ignorieren Sie die Mail einfach.
