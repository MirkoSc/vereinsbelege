<?php
/**
 * Second factor of the login (issue #17/M3-4, docs/spec/01-sicherheit.md
 * section 3) - shown once the password is right and
 * App\Service\Account\PendingLogin holds the rest until this page answers
 * it. Only components from /admin/designsystem, no page-specific CSS, no
 * JavaScript.
 *
 * @var string $csrf
 * @var \App\Service\Account\MfaMethod $mfaMethod
 * @var string|null $fehler
 * @var string|null $hinweis
 */
$istTotp = $mfaMethod === \App\Service\Account\MfaMethod::Totp;
?>
<section class="schmal">
    <h2>Anmeldung bestätigen</h2>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php elseif ($hinweis !== null): ?>
        <p class="hinweis hinweis-info" role="status"><?= e($hinweis) ?></p>
    <?php endif; ?>

    <p>
        <?= $istTotp
            ? 'Bitte geben Sie den Code aus Ihrer Authenticator-App ein.'
            : 'Bitte geben Sie den Code ein, der Ihnen per E-Mail zugesendet wurde.' ?>
    </p>

    <form method="post" action="/anmelden/bestaetigen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="bestaetigen-code">Code
            <input type="text" id="bestaetigen-code" name="code" inputmode="numeric" autocomplete="one-time-code"
                   autocapitalize="none" spellcheck="false" required autofocus>
        </label>

        <label class="feld-ankreuz">
            <input type="checkbox" name="geraet_merken"> Dieses Gerät 30 Tage merken
        </label>

        <p><button type="submit" class="knopf knopf-primaer">Bestätigen</button></p>
    </form>

    <?php if ($istTotp): ?>
        <form method="post" action="/anmelden/code-senden" class="nicht-drucken">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="feld-hilfe">Kein Zugriff auf die Authenticator-App?
                <button type="submit" class="knopf knopf-still">Code stattdessen per E-Mail senden</button>
            </p>
        </form>
    <?php else: ?>
        <form method="post" action="/anmelden/code-senden">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="feld-hilfe">Keinen Code erhalten?
                <button type="submit" class="knopf knopf-still">Code erneut senden</button>
            </p>
        </form>
    <?php endif; ?>

    <details>
        <summary>Backup-Code verwenden</summary>
        <form method="post" action="/anmelden/backup-code" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label for="bestaetigen-backup-code">Backup-Code
                <input type="text" id="bestaetigen-backup-code" name="code" autocomplete="off"
                       autocapitalize="characters" spellcheck="false">
            </label>
            <p class="feld-hilfe">Jeder Backup-Code funktioniert nur einmal.</p>
            <p><button type="submit" class="knopf">Mit Backup-Code anmelden</button></p>
        </form>
    </details>
</section>
