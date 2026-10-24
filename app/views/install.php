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
        <?php if (($restore ?? false) === true): ?>
            <p class="hinweis hinweis-ok">
                Wiederherstellung abgeschlossen. Das Backup ist eingespielt, die Konfiguration liegt in
                <code>shared/config.php</code>.
            </p>
        <?php else: ?>
            <p class="hinweis hinweis-ok">
                Installation abgeschlossen. Datenbank, erster Admin und Tresor sind angelegt, die Konfiguration
                liegt in <code>shared/config.php</code>.
            </p>
        <?php endif; ?>
        <?php if (is_array($zugangVerknuepft ?? null)): ?>
            <div class="hinweis hinweis-warnung">
                <p>
                    <strong>Das Konto <?= e($zugangVerknuepft['email']) ?> ist neu verknüpft.</strong>
                    Anmeldung mit dieser Adresse und dem bisherigen Passwort<?= $zugangVerknuepft['mfaZurueckgesetzt'] ? '; der zweite Faktor wird beim ersten Anmelden neu eingerichtet, die Authenticator-App ist gelöscht' : '' ?>.
                    <?= $zugangVerknuepft['freigabeErteilt'] ? 'Der Tresor ist für das Konto freigegeben.' : '' ?>
                </p>
                <p>Weil das Backup keinen Server-Schlüssel enthielt, gilt für die übrigen Betriebsdaten:</p>
                <ul>
                    <li>
                        <?php if ($zugangVerknuepft['unlesbareKonten'] === 0): ?>
                            Es gibt keine weiteren Benutzerkonten.
                        <?php else: ?>
                            <?= e((string) $zugangVerknuepft['unlesbareKonten']) ?>
                            <?= $zugangVerknuepft['unlesbareKonten'] === 1 ? 'weiteres Benutzerkonto hat' : 'weitere Benutzerkonten haben' ?>
                            eine unlesbare E-Mail-Adresse und können sich nicht anmelden, bis unter
                            <em>Verwaltung → Benutzer</em> die Adresse neu eingetragen ist. Eingerichtete
                            Authenticator-Apps dieser Konten funktionieren nicht mehr.
                        <?php endif; ?>
                    </li>
                    <li>API-Schlüssel der KI-Anbieter und das SMTP-Passwort müssen neu eingetragen werden.</li>
                    <li>Noch nicht versendete Mails aus der Warteschlange sind verloren.</li>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (($setupUebrig ?? false) === true): ?>
            <p class="hinweis hinweis-warnung">
                <strong>Bitte noch von Hand erledigen:</strong> <code>setup.php</code> konnte nicht
                gelöscht werden und liegt noch im Web-Verzeichnis. Bitte per FTP entfernen.
            </p>
        <?php endif; ?>
        <p>
            Als Nächstes in der Anwendung anmelden und unter „Admin“ den Systemcheck, die
            Mail-Einstellungen und die KI-Anbieter einrichten.
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

    <?php elseif ($schritt === 'zugang'): ?>
        <div class="karte">
            <h3>Zugang zur wiederhergestellten Installation</h3>
            <div class="hinweis hinweis-warnung">
                <p>
                    <strong>Das Backup ist eingespielt, enthielt aber keinen Server-Schlüssel</strong>
                    (<code>config.php</code>). Mit einem neuen Schlüssel findet die Anmeldung kein Konto mehr:
                    die E-Mail-Adressen, Namen und Authenticator-Geheimnisse aller Benutzer sind damit
                    verschlüsselt. Ohne einen der beiden Wege unten kann sich danach <strong>niemand</strong>
                    anmelden.
                </p>
            </div>

            <?php foreach (['csrf', 'db'] as $feld): ?>
                <?php if (isset($errors[$feld])): ?>
                    <p class="hinweis hinweis-fehler" role="alert"><?= e($errors[$feld]) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>

            <h4>Weg 1: Server-Schlüssel der alten Installation</h4>
            <p>
                Liegt die <code>shared/config.php</code> der alten Installation noch vor (getrennt gesicherte
                Kopie), übernimmt der Installer deren Schlüssel; alle Konten bleiben dann wie zuvor.
            </p>
            <?php if (isset($errors['server_key'])): ?>
                <p class="hinweis hinweis-fehler" role="alert"><?= e($errors['server_key']) ?></p>
            <?php endif; ?>
            <form method="post" action="/install/serverschluessel" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="config-datei">config.php hochladen
                    <input type="file" id="config-datei" name="config" accept=".php,text/plain,application/x-php">
                </label>
                <label for="server-key">… oder den Wert von <code>server_key</code> eintragen
                    <input type="text" id="server-key" name="server_key" autocomplete="off" spellcheck="false">
                </label>
                <p class="feld-hilfe">
                    Der Schlüssel wird nur übernommen, wenn sich damit ein Benutzerkonto des Backups lesen
                    lässt. Die Datei wird nicht ausgeführt, nur gelesen.
                </p>
                <p><button type="submit" class="knopf knopf-primaer">Schlüssel übernehmen und abschließen</button></p>
            </form>

            <h4>Weg 2: Admin-Konto mit dem Wiederherstellungsschlüssel neu verknüpfen</h4>
            <p>
                Der ausgedruckte Wiederherstellungsschlüssel des Vereins (sieben Gruppen) weist die Berechtigung
                nach; das <strong>bisherige Passwort</strong> bestimmt, welches Admin-Konto gemeint ist. Das Konto
                bekommt die eingegebene Adresse und den Namen neu, eine eingerichtete Authenticator-App wird
                zurückgesetzt. Die Adressen der übrigen Benutzer trägt der Admin danach unter
                <em>Verwaltung → Benutzer</em> neu ein.
            </p>
            <?php if (isset($errors['zugang'])): ?>
                <p class="hinweis hinweis-fehler" role="alert"><?= e($errors['zugang']) ?></p>
            <?php endif; ?>
            <form method="post" action="/install/zugang">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="zugang-schluessel">Wiederherstellungsschlüssel
                    <input type="text" id="zugang-schluessel" name="schluessel" autocomplete="off"
                        autocapitalize="characters" spellcheck="false" required>
                </label>
                <label for="zugang-email">E-Mail-Adresse des Admin-Kontos
                    <input type="email" id="zugang-email" name="admin_email" autocomplete="username"
                        value="<?= e($values['admin_email'] ?? '') ?>" required>
                </label>
                <label for="zugang-name">Name
                    <input type="text" id="zugang-name" name="admin_name" autocomplete="name"
                        value="<?= e($values['admin_name'] ?? '') ?>" required>
                </label>
                <label for="zugang-password">Bisheriges Passwort
                    <input type="password" id="zugang-password" name="admin_password" autocomplete="current-password" required>
                </label>
                <p><button type="submit" class="knopf knopf-primaer">Konto verknüpfen und abschließen</button></p>
            </form>

            <form method="post" action="/install/neu">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p><button type="submit" class="knopf knopf-gefahr">Neu beginnen</button></p>
                <p class="feld-hilfe">
                    Zurück zum Formular, etwa um ein anderes Backup einzuspielen. Die bereits eingespielten
                    Daten bleiben in der Datenbank, bis das nächste Backup sie ersetzt.
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
                <div class="hinweis hinweis-warnung">
                    <p>
                        <strong>Das Backup enthält keinen Server-Schlüssel.</strong> Damit sind die E-Mail-Adressen,
                        Namen und Authenticator-Geheimnisse aller Benutzer verschlüsselt – mit einem neuen Schlüssel
                        könnte sich niemand anmelden.
                    </p>
                    <p>
                        Nach dem Einspielen folgt deshalb ein weiterer Schritt: entweder die getrennt gesicherte
                        <code>config.php</code> der alten Installation hochladen, oder ein Admin-Konto mit dem
                        Wiederherstellungsschlüssel und dem bisherigen Passwort neu verknüpfen. API-Schlüssel der
                        KI-Anbieter und das SMTP-Passwort sind in beiden Fällen nur mit dem alten Schlüssel lesbar.
                    </p>
                </div>
            <?php endif; ?>

            <p id="wiederherstellung-status" role="status">Starte …</p>
            <progress id="wiederherstellung-fortschritt" max="100" value="0"></progress>
            <p id="wiederherstellung-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
            <p id="wiederherstellung-fertig" hidden>
                <a class="knopf knopf-primaer" id="wiederherstellung-weiter" href="/">Zur Startseite</a>
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
                        Das ZIP stammt aus der Update-Kette oder von <code>bin/backup.php</code>. Benutzer und
                        Tresor kommen aus dem Backup, das Formular „Erster Zugang“ entfällt. Enthält das ZIP
                        die <code>config.php</code>, wird der Server-Schlüssel übernommen. <strong>Enthält es
                        keine</strong> (Standard), braucht es nach dem Einspielen die getrennt gesicherte
                        <code>config.php</code> oder den Wiederherstellungsschlüssel samt Admin-Passwort –
                        sonst kann sich niemand anmelden.
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
