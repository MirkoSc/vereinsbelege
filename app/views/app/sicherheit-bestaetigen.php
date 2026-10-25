<?php
/**
 * Re-authentication before the second factor changes (security review N-08,
 * issue #246, docs/spec/01-sicherheit.md section 3): password plus the
 * current factor, the same three codes the login accepts. Only components
 * from /admin/designsystem, no page-specific CSS, no JavaScript.
 *
 * @var string $csrf
 * @var string $weiter one of App\App\SecurityController::WEITER_ERLAUBT
 * @var \App\Service\Account\MfaMethod|null $mfaMethod
 * @var string|null $fehler
 * @var string|null $hinweis
 */
$istTotp = $mfaMethod === \App\Service\Account\MfaMethod::Totp;
?>
<section class="schmal">
    <h2>Mit zweitem Faktor bestätigen</h2>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php elseif ($hinweis !== null): ?>
        <p class="hinweis hinweis-info" role="status"><?= e($hinweis) ?></p>
    <?php endif; ?>

    <p>
        Bevor Sie den zweiten Faktor oder die Backup-Codes ändern, bestätigen Sie bitte noch einmal,
        dass Sie es selbst sind: mit Ihrem Passwort und
        <?= $istTotp ? 'einem Code aus Ihrer Authenticator-App' : 'einem Code per E-Mail' ?>
        oder einem Backup-Code. Die Bestätigung gilt zehn Minuten.
    </p>

    <form method="post" action="/app/sicherheit/bestaetigen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="weiter" value="<?= e($weiter) ?>">

        <label for="neuanmeldung-passwort">Passwort
            <input type="password" id="neuanmeldung-passwort" name="passwort" autocomplete="current-password" required autofocus>
        </label>

        <label for="neuanmeldung-code">Code
            <input type="text" id="neuanmeldung-code" name="code" inputmode="numeric" autocomplete="one-time-code"
                   autocapitalize="none" spellcheck="false" required>
        </label>
        <p class="feld-hilfe">Ein Backup-Code funktioniert hier ebenfalls – und nur einmal.</p>

        <p><button type="submit" class="knopf knopf-primaer">Bestätigen</button></p>
    </form>

    <form method="post" action="/app/sicherheit/bestaetigen/code-senden">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="weiter" value="<?= e($weiter) ?>">
        <p class="feld-hilfe"><?= $istTotp ? 'Kein Zugriff auf die Authenticator-App?' : 'Keinen Code erhalten?' ?>
            <button type="submit" class="knopf knopf-still">Code per E-Mail senden</button>
        </p>
    </form>

    <p><a class="knopf knopf-still" href="/app/sicherheit">Abbrechen</a></p>
</section>
