<?php

/**
 * Setting a new password from a reset link (issue #18/M3-5,
 * docs/spec/01-sicherheit.md sections 2 and 3).
 *
 * Only components from /admin/designsystem, no page-specific CSS, no
 * JavaScript. The token travels on as a hidden field; the checkbox makes the
 * consequence for the vault an explicit decision rather than small print.
 *
 * @var string $csrf
 * @var string $token
 * @var list<string> $fehler
 * @var bool $ungueltig the link itself is unusable - no form, only the way to a new one
 */
?>
<section class="schmal">
    <h2>Neues Passwort festlegen</h2>

    <?php foreach ($fehler as $meldung): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($meldung) ?></p>
    <?php endforeach; ?>

    <?php if ($ungueltig): ?>
        <p><a class="knopf" href="/anmelden/passwort-vergessen">Neuen Link anfordern</a></p>
    <?php else: ?>
        <div class="hinweis hinweis-warnung">
            <p>Mit dem neuen Passwort bekommt Ihr Zugang einen neuen Schlüssel. Ihre bisherige
                Tresor-Freigabe verfällt dabei: Belege sehen Sie erst wieder, wenn ein Administrator
                Ihren Zugang erneut freigibt. Alle angemeldeten Sitzungen werden beendet.</p>
        </div>

        <form method="post" action="/anmelden/passwort-neu" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <label for="neu-passwort">Neues Passwort
                <input type="password" id="neu-passwort" name="passwort"
                       autocomplete="new-password" minlength="12" required autofocus>
            </label>
            <p class="feld-hilfe">Mindestens 12 Zeichen. Ein langer Satz ist sicherer als Sonderzeichen.</p>

            <label for="neu-passwort-wiederholung">Neues Passwort wiederholen
                <input type="password" id="neu-passwort-wiederholung" name="passwort_wiederholung"
                       autocomplete="new-password" minlength="12" required>
            </label>

            <label class="feld-ankreuz">
                <input type="checkbox" name="verstanden" value="1" required>
                Ich habe verstanden, dass danach eine erneute Freigabe durch einen Administrator nötig ist.
            </label>

            <p><button type="submit" class="knopf knopf-primaer">Passwort festlegen</button></p>
        </form>
    <?php endif; ?>
</section>
