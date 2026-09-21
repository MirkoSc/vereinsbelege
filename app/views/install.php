<section class="schmal">
    <h2>Vereinsbelege installieren</h2>

    <?php if (($fertig ?? false) === true): ?>
        <p class="hinweis hinweis-ok">
            Installation abgeschlossen. Die Datenbank ist angelegt und die Konfiguration
            liegt in <code>shared/config.php</code>.
        </p>
        <?php if (($setupUebrig ?? false) === true): ?>
            <p class="hinweis hinweis-warnung">
                <strong>Bitte noch von Hand erledigen:</strong> <code>setup.php</code> konnte nicht
                gelöscht werden und liegt noch im Web-Verzeichnis. Bitte per FTP entfernen.
            </p>
        <?php endif; ?>
        <p>
            Benutzerkonten, Tresor und die Fachbereiche kommen mit den nächsten
            Meilensteinen. Bis dahin gibt es die Startseite und die Update-Seite.
        </p>
        <p><a class="knopf" href="/">Zur Startseite</a></p>
    <?php else: ?>
        <p>
            Trage die Zugangsdaten der Datenbank ein, die du im Kundenmenü des Hosters
            angelegt hast. Die Installation legt darin alle Tabellen an und schreibt die
            Konfiguration samt Server-Schlüssel nach <code>shared/config.php</code> –
            außerhalb des öffentlich erreichbaren Bereichs.
        </p>

        <?php if (isset($errors['csrf'])): ?>
            <p class="hinweis hinweis-fehler"><?= e($errors['csrf']) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['db'])): ?>
            <p class="hinweis hinweis-fehler"><?= e($errors['db']) ?></p>
        <?php endif; ?>

        <form method="post" action="/install">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <h3>Datenbank</h3>
            <label>Host
                <input type="text" name="db_host" value="<?= e($values['db_host'] ?? 'localhost') ?>" required>
            </label>
            <label>Port
                <input type="number" name="db_port" value="<?= e($values['db_port'] ?? '3306') ?>">
            </label>
            <label>Datenbankname
                <input type="text" name="db_name" value="<?= e($values['db_name'] ?? '') ?>" required>
            </label>
            <label>Benutzer
                <input type="text" name="db_user" value="<?= e($values['db_user'] ?? '') ?>" required>
            </label>
            <label>Passwort
                <input type="password" name="db_password" autocomplete="new-password">
            </label>

            <h3>Updates</h3>
            <label>Release-Kanal
                <select name="kanal">
                    <option value="stable" <?= ($values['kanal'] ?? 'stable') !== 'beta' ? 'selected' : '' ?>>
                        stable – nur reguläre Releases
                    </option>
                    <option value="beta" <?= ($values['kanal'] ?? '') === 'beta' ? 'selected' : '' ?>>
                        beta – auch Vorabversionen (Testinstanz)
                    </option>
                </select>
            </label>

            <p><button type="submit" class="knopf">Installation starten</button></p>
        </form>
    <?php endif; ?>
</section>
