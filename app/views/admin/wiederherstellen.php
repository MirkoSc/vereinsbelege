<?php

/**
 * Unlocking the vault with the paper recovery key (issue #22/M3-9,
 * docs/spec/01-sicherheit.md section 2 "Letzter Admin hat Passwort
 * vergessen"). Only components from /admin/designsystem, no page-specific
 * CSS, no JavaScript.
 *
 * The key itself is never redisplayed after a failed attempt - same
 * treatment as a password field, even though this one is plain text so it
 * can be checked group by group while typing.
 *
 * @var string $csrf
 * @var list<string> $fehler
 * @var bool $entsperrt
 */
?>
<section class="schmal">
    <h2>Tresor wiederherstellen</h2>

    <?php foreach ($fehler as $meldung): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($meldung) ?></p>
    <?php endforeach; ?>

    <?php if ($entsperrt): ?>
        <p class="hinweis hinweis-ok" role="status">
            Ihr Tresor ist in dieser Sitzung bereits entsperrt. Ein Wiederherstellungsschlüssel wird nicht gebraucht.
        </p>
        <p><a href="/admin/tresor">Zu den Tresor-Freigaben</a></p>
    <?php else: ?>
        <p class="gedaempft">
            Nur nötig, wenn Ihr Zugang keine Freigabe für den Tresor hat und niemand sonst eine erteilen
            kann – zum Beispiel nach „Passwort vergessen“, wenn Sie der einzige Administrator sind. Der
            Wiederherstellungsschlüssel wurde bei der Installation einmalig ausgedruckt und liegt im
            Vereinstresor.
        </p>

        <p class="hinweis hinweis-warnung">
            Dieser Schlüssel öffnet den Tresor jedes Benutzers. Nach der Eingabe erteilt er Ihrem eigenen
            Zugang eine dauerhafte Freigabe und entsperrt den Tresor für diese Sitzung. Jeder Versuch wird
            protokolliert, und alle Administratorinnen und Administratoren mit einer eigenen Freigabe
            erhalten eine Sicherheitsmail.
        </p>

        <form method="post" action="/admin/wiederherstellen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label for="wiederherstellen-schluessel">Wiederherstellungsschlüssel
                <input type="text" id="wiederherstellen-schluessel" name="schluessel"
                       autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus
                       aria-describedby="wiederherstellen-hilfe">
            </label>
            <p class="feld-hilfe" id="wiederherstellen-hilfe">
                Sieben Gruppen zu acht Zeichen, mit oder ohne Leerzeichen. Groß- und Kleinschreibung ist
                egal, „O“ gilt als „0“, „I“ und „L“ gelten als „1“.
            </p>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Tresor entsperren</button>
                <a href="/admin/tresor">Abbrechen</a>
            </p>
        </form>
    <?php endif; ?>
</section>
