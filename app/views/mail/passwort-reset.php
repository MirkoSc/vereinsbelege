<?php

/**
 * Password reset link (issue #18/M3-5, docs/spec/01-sicherheit.md
 * section 3: "Passwort-Reset: Token 32 Byte, nur Hash gespeichert, 30 min,
 * einmalig"). Purely technical - the link and its lifetime, no business
 * content (CLAUDE.md section 4).
 *
 * It says up front what the reset costs, the same as the page behind the
 * link: a new key pair, and no access to the vault until an admin grants it
 * again (docs/spec/01-sicherheit.md section 2).
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose. $link is built
 * by App\App\PasswordController from the checked base URL
 * (App\Service\Mail\PublicUrl) and a hex token - nothing user-typed.
 *
 * @var string $link
 * @var int $gueltigMinuten
 * @var string $vereinsname
 */
?>
Für Ihr Konto bei <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?> wurde ein neues Passwort angefordert.

Über diesen Link können Sie ein neues Passwort festlegen:

<?= $link ?>


Der Link ist <?= $gueltigMinuten ?> Minuten gültig und kann nur einmal verwendet werden.

Bitte beachten: Nach dem Zurücksetzen sehen Sie keine Belege, bis eine Administratorin oder ein
Administrator Ihren Zugang erneut für den Tresor freigibt. Kennen Sie Ihr Passwort doch noch,
ignorieren Sie diese Mail einfach – dann ändert sich nichts.
