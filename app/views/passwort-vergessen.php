<?php

/**
 * "Passwort vergessen" - asking for a reset link (issue #18/M3-5,
 * docs/spec/01-sicherheit.md sections 2 and 3).
 *
 * Only components from /admin/designsystem, no page-specific CSS, no
 * JavaScript.
 *
 * The confirmation after sending is one and the same sentence whether the
 * address has an account or not - nothing here may hint at which it was.
 * The warning about the vault comes BEFORE the request, not after it
 * ("UI erklärt das vorab").
 *
 * @var string $csrf
 * @var string|null $fehler
 * @var string $email
 * @var bool $gesendet
 */
?>
<section class="schmal">
    <h2>Passwort vergessen</h2>

    <?php if ($gesendet): ?>
        <p class="hinweis hinweis-ok" role="status">
            Wenn zu dieser Adresse ein Konto besteht, ist jetzt eine Mail mit einem Link unterwegs.
            Der Link ist 30 Minuten gültig und kann nur einmal verwendet werden.
        </p>
        <p><a href="/anmelden">Zurück zur Anmeldung</a></p>
    <?php else: ?>
        <?php if ($fehler !== null): ?>
            <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
        <?php endif; ?>

        <div class="hinweis hinweis-warnung">
            <p><strong>Bitte vorher lesen:</strong> Ihre Belege sind mit einem Schlüssel verschlüsselt,
                den nur Ihr bisheriges Passwort öffnet. Nach dem Zurücksetzen erhält Ihr Zugang einen
                neuen Schlüssel – Belege sehen Sie erst wieder, wenn ein Administrator Ihren Zugang
                für den Tresor erneut freigibt.</p>
            <p>Sind Sie der einzige Administrator, kann Sie niemand mehr freigeben. Setzen Sie das
                Passwort trotzdem zurück und entsperren Sie den Tresor danach unter Administration →
                Tresor mit dem Wiederherstellungsschlüssel des Vereins.</p>
        </div>

        <form method="post" action="/anmelden/passwort-vergessen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label for="vergessen-email">E-Mail-Adresse
                <input type="email" id="vergessen-email" name="email" value="<?= e($email) ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
            </label>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Link anfordern</button>
                <a href="/anmelden">Abbrechen</a>
            </p>
        </form>
    <?php endif; ?>
</section>
