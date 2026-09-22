<?php

/**
 * The e-mail second factor / login code (issue #17/M3-4,
 * docs/spec/01-sicherheit.md section 3: "E-Mail-Code: 6 Ziffern, 10 min
 * gültig"). Purely technical - no business content, like every mail
 * template (CLAUDE.md section 4, docs/spec/06-betrieb.md section 3).
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose.
 *
 * @var string $code
 * @var int $gueltigMinuten
 */
?>
Ihr Anmeldecode für Vereinsbelege lautet:

<?= $code ?>

Der Code ist <?= $gueltigMinuten ?> Minuten gültig und kann nur einmal verwendet werden.

Wenn Sie diesen Code nicht selbst angefordert haben, ignorieren Sie diese Mail und ändern Sie vorsichtshalber Ihr Passwort.
