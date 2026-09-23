<?php

/**
 * Notice to everyone who works the inbox (`document.edit`) about a new
 * public submission (issue #27/M4-5). Only the reference number - never the
 * submitter's name, text, IBAN or amounts (CLAUDE.md section 4); the page
 * behind the link shows them, to somebody logged in with an unlocked vault.
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose. $link is built
 * from the checked base URL (App\Service\Mail\PublicUrl), or null when there
 * is none.
 *
 * @var string $referenz
 * @var string|null $link
 * @var string $vereinsname
 */
?>
Bei <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?> ist eine neue Einreichung eingegangen: <?= $referenz ?>

<?php if ($link !== null): ?>
Sie finden sie nach der Anmeldung im Posteingang:

<?= $link ?>

<?php else: ?>
Sie finden sie nach der Anmeldung unter Posteingang.
<?php endif; ?>
