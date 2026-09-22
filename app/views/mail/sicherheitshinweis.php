<?php

/**
 * General security notice (docs/spec/01-sicherheit.md section 3:
 * "Sicherheits-Mails an den Nutzer: neues Gerät, Passwort geändert, 2FA
 * geändert, Tresor-Freigabe erteilt/entzogen"). `$ereignis` comes from a
 * fixed list of sentences in the caller
 * (App\Service\Mail\Mailer::sendeSicherheitshinweis()), never from user
 * input - no business content, like every mail template (CLAUDE.md
 * section 4).
 *
 * Plain text, not HTML: rendered by App\Service\Mail\MailTemplates, not
 * App\View\View, so there is no e() escaping here on purpose.
 *
 * @var string $ereignis
 * @var string $vereinsname
 */
?>
Sicherheitshinweis für Ihr Konto bei <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?>:

<?= $ereignis ?>

Wenn Sie das nicht selbst veranlasst haben, wenden Sie sich umgehend an eine Administratorin oder
einen Administrator des Vereins.
