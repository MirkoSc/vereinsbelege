<?php

/**
 * Password change with the old password known (issue #18/M3-5,
 * docs/spec/01-sicherheit.md section 2). Only components from
 * /admin/designsystem, no page-specific CSS, no JavaScript.
 *
 * @var string $csrf
 * @var list<string> $fehler
 */
?>
<section class="schmal">
    <h2>Passwort ändern</h2>

    <?php foreach ($fehler as $meldung): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($meldung) ?></p>
    <?php endforeach; ?>

    <p class="gedaempft">
        Ihr Schlüssel wird mit dem neuen Passwort neu verpackt – die Freigabe
        für den Tresor bleibt erhalten. Andere Geräte, auf denen Sie angemeldet
        sind, werden abgemeldet.
    </p>

    <form method="post" action="/app/sicherheit/passwort" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="passwort-alt">Bisheriges Passwort
            <input type="password" id="passwort-alt" name="passwort_alt"
                   autocomplete="current-password" required autofocus>
        </label>

        <label for="passwort-neu">Neues Passwort
            <input type="password" id="passwort-neu" name="passwort"
                   autocomplete="new-password" minlength="12" required>
        </label>
        <p class="feld-hilfe">Mindestens 12 Zeichen. Ein langer Satz ist sicherer als Sonderzeichen.</p>

        <label for="passwort-wiederholung">Neues Passwort wiederholen
            <input type="password" id="passwort-wiederholung" name="passwort_wiederholung"
                   autocomplete="new-password" minlength="12" required>
        </label>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Passwort ändern</button>
            <a href="/app/sicherheit">Abbrechen</a>
        </p>
    </form>

    <p class="feld-hilfe">
        Passwort vergessen? Melden Sie sich ab und nutzen Sie auf der
        Anmeldeseite „Passwort vergessen?“ – dafür ist danach allerdings eine
        erneute Tresor-Freigabe nötig.
    </p>
</section>
