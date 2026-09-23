<?php

/**
 * Accepting an invitation: setting the first password (issue #20/M3-7,
 * docs/spec/01-sicherheit.md section 2 "Einladung").
 *
 * Only components from /admin/designsystem, no page-specific CSS, no
 * JavaScript. The token travels on as a hidden field.
 *
 * @var string $csrf
 * @var string $token
 * @var list<string> $fehler
 * @var bool $ungueltig the link itself is unusable - no form
 */
?>
<section class="schmal">
    <h2>Einladung annehmen</h2>

    <?php foreach ($fehler as $meldung): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($meldung) ?></p>
    <?php endforeach; ?>

    <?php if (!$ungueltig): ?>
        <p>Legen Sie ein Passwort für Ihren Zugang fest. Danach melden Sie sich an und richten einen
            zweiten Faktor ein (App oder Code per E-Mail).</p>

        <div class="hinweis hinweis-info">
            <p>Belege sehen Sie erst, wenn ein Administrator Ihren Zugang für den Tresor freigegeben hat.
                Die Administratoren werden darüber automatisch benachrichtigt.</p>
        </div>

        <form method="post" action="/anmelden/einladung" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <label for="einladung-passwort">Passwort
                <input type="password" id="einladung-passwort" name="passwort"
                       autocomplete="new-password" minlength="12" required autofocus>
            </label>
            <p class="feld-hilfe">Mindestens 12 Zeichen. Ein langer Satz ist sicherer als Sonderzeichen.</p>

            <label for="einladung-passwort-wiederholung">Passwort wiederholen
                <input type="password" id="einladung-passwort-wiederholung" name="passwort_wiederholung"
                       autocomplete="new-password" minlength="12" required>
            </label>

            <p><button type="submit" class="knopf knopf-primaer">Passwort festlegen</button></p>
        </form>
    <?php endif; ?>
</section>
