<?php

/**
 * Login form (M3-3, issue #16, docs/spec/01-sicherheit.md section 3).
 *
 * Only components from /admin/designsystem, no page-specific CSS, no
 * JavaScript: a form with two fields does not need any, and inline script
 * would not survive the CSP anyway (CLAUDE.md section 4).
 *
 * The error message is one and the same sentence for every way an attempt
 * can fail (App\Service\Account\LoginFailure). Nothing on this page may hint
 * at whether an address has an account here - not the text, not a field
 * marked red, not a missing one.
 *
 * @var string $csrf
 * @var string|null $fehler
 * @var string|null $hinweis
 * @var string $email
 * @var string|null $weiter
 */
?>
<section class="schmal">
    <h2>Anmelden</h2>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php elseif ($hinweis !== null): ?>
        <p class="hinweis hinweis-info" role="status"><?= e($hinweis) ?></p>
    <?php endif; ?>

    <form method="post" action="/anmelden" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if ($weiter !== null): ?>
            <input type="hidden" name="weiter" value="<?= e($weiter) ?>">
        <?php endif; ?>

        <label for="anmelden-email">E-Mail-Adresse
            <input type="email" id="anmelden-email" name="email" value="<?= e($email) ?>"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </label>

        <label for="anmelden-passwort">Passwort
            <input type="password" id="anmelden-passwort" name="passwort"
                   autocomplete="current-password" required>
        </label>

        <p><button type="submit" class="knopf knopf-primaer">Anmelden</button></p>
    </form>

    <p class="feld-hilfe">
        Nach der Anmeldung wird der Tresor in Ihrer Sitzung entsperrt – nur
        so sind Belege lesbar. Er schließt sich beim Abmelden, nach
        30 Minuten ohne Aktivität und spätestens nach 12 Stunden.
    </p>
</section>
