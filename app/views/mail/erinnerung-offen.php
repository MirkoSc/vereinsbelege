<?php

/**
 * Reminder to everyone who works the inbox (`document.edit`) that receipts
 * are waiting (issue #249, docs/spec/06-betrieb.md section 4: "5 Belege
 * warten auf Prüfung – ohne Details"). Two counts, nothing of any receipt
 * (CLAUDE.md section 4).
 *
 * Plain text, rendered by App\Service\Mail\MailTemplates (no e() here on
 * purpose, as in the other mail templates). $link is the configured public
 * address, or null.
 *
 * @var string $vereinsname
 * @var int $posteingang
 * @var int $pruefung
 * @var string|null $link
 */
?>
Bei <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?> warten Belege auf Sie:

<?php if ($posteingang > 0): ?>
- <?= $posteingang ?> <?= $posteingang === 1 ? 'Einreichung wartet' : 'Einreichungen warten' ?> im Posteingang auf eine Entscheidung.
<?php endif; ?>
<?php if ($pruefung > 0): ?>
- <?= $pruefung ?> <?= $pruefung === 1 ? 'Beleg wartet' : 'Belege warten' ?> auf Prüfung.
<?php endif; ?>

<?php if ($link !== null): ?>
Nach der Anmeldung finden Sie sie hier:

<?= $link ?>

<?php else: ?>
Sie finden sie nach der Anmeldung auf der Startseite.
<?php endif; ?>
Diese Erinnerung kommt in dem Abstand, den die Verwaltung unter Admin → Mail eingestellt hat.
