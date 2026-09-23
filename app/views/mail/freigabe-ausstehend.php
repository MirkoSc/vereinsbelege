<?php

/**
 * Notice to the admins who can grant the vault (issue #20/M3-7,
 * docs/spec/01-sicherheit.md section 2 "Freigabe": "Admins bekommen dazu eine
 * Mail (ohne fachlichen Inhalt)"). Deliberately without the account's name or
 * address - the page behind the link shows who, to somebody logged in.
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose. $link is built
 * from the checked base URL (App\Service\Mail\PublicUrl), or null when there
 * is none.
 *
 * @var string|null $link
 * @var string $vereinsname
 */
?>
In der Belegverwaltung von <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?> wartet ein Zugang auf die Freigabe für den Tresor.

<?php if ($link !== null): ?>
Freigaben erteilen Sie nach der Anmeldung hier:

<?= $link ?>

<?php else: ?>
Freigaben erteilen Sie nach der Anmeldung unter Verwaltung → Tresor.
<?php endif; ?>

Prüfen Sie vor der Freigabe, ob Sie den Zugang erwarten – erst mit der Freigabe kann er Belege sehen.
