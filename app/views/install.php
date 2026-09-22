<?php
/**
 * @var array<string, string> $errors
 * @var array<string, string> $values
 * @var string $schritt one of 'schluessel', 'schluessel_offen', or unset for the default form/restore/fertig
 */
$schritt = $schritt ?? '';
?>
<section class="schmal">
    <h2>Vereinsbelege installieren</h2>

    <?php if (($fertig ?? false) === true): ?>
        <p class="hinweis hinweis-ok">
            Installation abgeschlossen. Datenbank, erster Admin und Tresor sind angelegt, die Konfiguration
            liegt in <code>shared/config.php</code>.
        </p>
        <?php if (($setupUebrig ?? false) === true): ?>
            <p class="hinweis hinweis-warnung">
                <strong>Bitte noch von Hand erledigen:</strong> <code>setup.php</code> konnte nicht
                gelöscht werden und liegt noch im Web-Verzeichnis. Bitte per FTP entfernen.
            </p>
        <?php endif; ?>
        <p>
            Die Fachbereiche kommen mit den nächsten Meilensteinen. Bis dahin gibt es die Startseite,
            die Anmeldung und die Update-Seite.
        </p>
        <p><a class="knopf knopf-primaer" href="/">Zur Startseite</a></p>

    <?php elseif ($schritt === 'schluessel' || $schritt === 'schluessel_offen'): ?>
        <div class="karte">
            <?php if ($schritt === 'schluessel'): ?>
                <h3>Wiederherstellungsschlüssel</h3>
                <p class="hinweis hinweis-warnung nicht-drucken">
                    Dieser Schlüssel wird nur dieses eine Mal angezeigt. Er ersetzt jedes vergessene
                    Admin-Passwort - ohne ihn und ohne ein Admin-Passwort sind die Vereinsdaten
                    unwiederbringlich verloren.
                </p>
                <div class="schluessel-gruppen" aria-label="Wiederherstellungsschlüssel, sieben Gruppen zu acht Zeichen">
                    <?php foreach ($schluesselGruppen as $gruppe): ?>
                        <span class="schluessel-gruppe"><?= e($gruppe) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="nur-druck">Vereinsbelege – Wiederherstellungsschlüssel. Im Vereinstresor aufbewahren.</p>
                <p class="nicht-drucken">
                    <button type="button" id="schluessel-drucken" class="knopf">Zum Aufbewahren drucken</button>
                </p>
                <p class="nicht-drucken">
                    Jetzt ausdrucken oder alle sieben Gruppen von Hand abschreiben und an einem sicheren
                    Ort (Vereinstresor) aufbewahren. Danach unten die letzte Gruppe eintippen, um zu
                    bestätigen, dass der Schlüssel gesichert ist.
                </p>
            <?php else: ?>
                <h3>Wiederherstellungsschlüssel bestätigen</h3>
                <p class="hinweis hinweis-warnung">
                    Der Wiederherstellungsschlüssel wurde noch nicht bestätigt. Aus Sicherheitsgründen
                    wird er kein zweites Mal angezeigt - liegt er nicht mehr vor, bitte „Neu beginnen“
                    wählen.
                </p>
            <?php endif; ?>

            <?php if (isset($errors['schluessel'])): ?>
                <p class="hinweis hinweis-fehler"><?= e($errors['schluessel']) ?></p>
            <?php endif; ?>
            <?php if (isset($errors['csrf'])): ?>
                <p class="hinweis hinweis-fehler"><?= e($errors['csrf']) ?></p>
            <?php endif; ?>
            <?php if (isset($errors['db'])): ?>
                <p class="hinweis hinweis-fehler"><?= e($errors['db']) ?></p>
            <?php endif; ?>

            <form method="post" action="/install/schluessel" class="nicht-drucken">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="schluessel-letzte-gruppe">Letzte Gruppe des Wiederherstellungsschlüssels
                    <input type="text" id="schluessel-letzte-gruppe" name="schluessel_letzte_gruppe"
                        autocomplete="off" autocapitalize="characters" spellcheck="false">
                </label>
                <p><button type="submit" class="knopf knopf-primaer">Bestätigen und Installation abschließen</button></p>
            </form>

            <form method="post" action="/install/neu" class="nicht-drucken">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p>
                    <button type="submit" class="knopf knopf-gefahr">Neu beginnen</button>
                </p>
                <p class="feld-hilfe">
                    Entfernt den noch unbestätigten Admin und Tresor wieder, damit das Formular erneut
                    ausgefüllt werden kann. Nichts davon wurde bisher irgendwo gespeichert, das der
                    Vereinstresor nicht ohnehin verlangt.
                </p>
            </form>
        </div>

    <?php elseif (($restore ?? false) === true): ?>
        <div id="wiederherstellung" data-csrf="<?= e($csrf) ?>">
            <p>
                Das Backup<?= ($backupVersion ?? null) !== null ? ' (Version ' . e($backupVersion) . ')' : '' ?>
                wird eingespielt. Bitte diese Seite geöffnet lassen.
            </p>
            <?php if (($serverKeyUebernommen ?? false) === true): ?>
                <p class="hinweis hinweis-ok">
                    Das Backup enthält den Server-Schlüssel der alten Installation. Er wird
                    übernommen, damit verschlüsselte Betriebsdaten lesbar bleiben.
                </p>
            <?php else: ?>
                <p class="hinweis hinweis-warnung">
                    Das Backup enthält keinen Server-Schlüssel. Es wird ein neuer erzeugt;
                    mit dem alten Schlüssel verschlüsselte Betriebsdaten (Mail-Adressen, API-Schlüssel)
                    sind dann nicht mehr lesbar.
                </p>
            <?php endif; ?>

            <p id="wiederherstellung-status" role="status">Starte …</p>
            <progress id="wiederherstellung-fortschritt" max="100" value="0"></progress>
            <p id="wiederherstellung-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
            <p id="wiederherstellung-fertig" hidden>
                <a class="knopf knopf-primaer" href="/">Zur Startseite</a>
            </p>
        </div>
    <?php else: ?>
        <?php $modusAktuell = ($values['modus'] ?? 'frisch') === 'restore' ? 'restore' : 'frisch'; ?>
        <p>
            Trage die Zugangsdaten der Datenbank ein, die du im Kundenmenü des Hosters
            angelegt hast. Die Installation legt darin alle Tabellen an, erzeugt den ersten
            Admin-Zugang samt Tresor und schreibt die Konfiguration samt Server-Schlüssel nach
            <code>shared/config.php</code> – außerhalb des öffentlich erreichbaren Bereichs.
        </p>

        <?php if (isset($errors['csrf'])): ?>
            <p class="hinweis hinweis-fehler"><?= e($errors['csrf']) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['db'])): ?>
            <p class="hinweis hinweis-fehler"><?= e($errors['db']) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['backup'])): ?>
            <p class="hinweis hinweis-fehler"><?= e($errors['backup']) ?></p>
        <?php endif; ?>

        <form method="post" action="/install" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <fieldset>
                <legend>Was soll passieren?</legend>
                <label class="feld-ankreuz">
                    <input type="radio" name="modus" value="frisch" id="modus-frisch"
                        <?= $modusAktuell !== 'restore' ? 'checked' : '' ?>>
                    Frische Installation
                </label>
                <label class="feld-ankreuz">
                    <input type="radio" name="modus" value="restore" id="modus-restore"
                        <?= $modusAktuell === 'restore' ? 'checked' : '' ?>>
                    Backup einspielen
                </label>
                <div id="backup-upload"<?= $modusAktuell !== 'restore' ? ' hidden' : '' ?>>
                    <label for="backup-datei">Backup-ZIP (nur bei „Backup einspielen“)
                        <input type="file" id="backup-datei" name="backup" accept=".zip,application/zip"
                            <?= $modusAktuell !== 'restore' ? 'disabled' : '' ?>>
                    </label>
                    <p class="feld-hilfe">
                        Das ZIP stammt aus der Update-Kette oder von <code>bin/backup.php</code>. Enthält es
                        die <code>config.php</code>, wird der Server-Schlüssel übernommen; Benutzer und
                        Tresor kommen dann aus dem Backup, das Formular unten entfällt.
                    </p>
                </div>
            </fieldset>

            <fieldset id="erster-zugang"<?= $modusAktuell === 'restore' ? ' hidden' : '' ?>>
                <legend>Erster Zugang</legend>
                <?php $adminDisabled = $modusAktuell === 'restore' ? 'disabled' : ''; ?>
                <?php if (isset($errors['admin_email'])): ?>
                    <p class="hinweis hinweis-fehler"><?= e($errors['admin_email']) ?></p>
                <?php endif; ?>
                <label for="admin-email">E-Mail-Adresse
                    <input type="email" id="admin-email" name="admin_email" autocomplete="username"
                        value="<?= e($values['admin_email'] ?? '') ?>" <?= $adminDisabled ?>>
                </label>
                <label for="admin-name">Name
                    <input type="text" id="admin-name" name="admin_name" autocomplete="name"
                        value="<?= e($values['admin_name'] ?? '') ?>" <?= $adminDisabled ?>>
                </label>
                <?php if (isset($errors['admin_name'])): ?>
                    <p class="feld-fehler"><?= e($errors['admin_name']) ?></p>
                <?php endif; ?>
                <label for="admin-password">Passwort
                    <input type="password" id="admin-password" name="admin_password" autocomplete="new-password"
                        <?= $adminDisabled ?>>
                </label>
                <p class="feld-hilfe">Mindestens 12 Zeichen, kein häufig verwendetes Passwort.</p>
                <?php if (isset($errors['admin_password'])): ?>
                    <p class="feld-fehler"><?= e($errors['admin_password']) ?></p>
                <?php endif; ?>
                <label for="admin-password-wiederholung">Passwort wiederholen
                    <input type="password" id="admin-password-wiederholung" name="admin_password_wiederholung"
                        autocomplete="new-password" <?= $adminDisabled ?>>
                </label>
                <?php if (isset($errors['admin_password_wiederholung'])): ?>
                    <p class="feld-fehler"><?= e($errors['admin_password_wiederholung']) ?></p>
                <?php endif; ?>
                <p class="feld-hilfe">
                    Zu diesem Zugang gehört der Tresor, der alle Belege verschlüsselt - der
                    Wiederherstellungsschlüssel danach ist der einzige Weg zurück, falls das Passwort
                    verloren geht.
                </p>
            </fieldset>

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

            <p><button type="submit" class="knopf knopf-primaer">Installation starten</button></p>
        </form>
    <?php endif; ?>
</section>
