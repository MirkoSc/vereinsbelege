<?php

/**
 * Backups page (issue #112, docs/spec/06-betrieb.md section 2): list,
 * "Backup jetzt erstellen" and download.
 *
 * Only components from /admin/designsystem, no page-specific CSS. The step
 * chain runs in public/js/backups.js - never an inline script (CLAUDE.md
 * section 4). The table turns into cards below 48 rem (.tabelle-karten).
 *
 * @var string $csrf
 * @var list<array{name: string, groesse: int, geaendert_am: string, mit_config: bool}> $backups
 * @var bool $laeuft
 * @var array<string, mixed>|null $stand
 * @var int $behalten
 */

$groesse = static function (int $bytes): string {
    $einheiten = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $wert = (float) $bytes;
    while ($wert >= 1024.0 && $i < count($einheiten) - 1) {
        $wert /= 1024.0;
        $i++;
    }

    return number_format($wert, $i === 0 ? 0 : 1, ',', '.') . ' ' . $einheiten[$i];
};
$datum = static function (string $wert): string {
    $zeit = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wert);

    return $zeit === false ? $wert : $zeit->format('d.m.Y H:i');
};
?>
<section class="schmal">
    <h2>Backups</h2>

    <p class="gedaempft">
        Ein Backup enthält die Datenbank und die verschlüsselten Belegdateien.
        Fachliche Daten liegen darin nur verschlüsselt; zum Lesen braucht es
        den Wiederherstellungsschlüssel oder ein Benutzerkonto mit Freigabe.
        Eingespielt wird ein Backup im Installer. Aufbewahrt werden die
        letzten <?= e($behalten) ?> Backups – mit Belegdateien ist jedes so
        groß wie der Belegbestand, also auf den Speicherplatz achten.
    </p>

    <p class="hinweis hinweis-warnung" id="backup-warnung-ohne-config">
        <strong>Ohne <code>config.php</code></strong> (Standard) fehlt im Backup
        der Server-Schlüssel. Auf einem anderen Server eingespielt, kann sich
        dann niemand anmelden, bis ein Admin im Installer sein Konto mit dem
        Wiederherstellungsschlüssel und seinem Passwort neu verknüpft; die
        Mail-Adressen der übrigen Benutzer, Authenticator-Geheimnisse,
        KI-API-Schlüssel und das SMTP-Passwort gehen verloren. Deshalb
        <code>shared/config.php</code> getrennt beim Wiederherstellungsschlüssel
        aufbewahren.
    </p>

    <h3>Backup jetzt erstellen</h3>
    <noscript>
        <p class="hinweis hinweis-fehler">Zum Erstellen eines Backups wird JavaScript benötigt.</p>
    </noscript>
    <form method="post" action="/admin/backups" id="backup-erstellen" class="formular"
          data-csrf="<?= e($csrf) ?>" data-laeuft="<?= $laeuft ? '1' : '0' ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="feld-ankreuz">
            <input type="checkbox" name="mit_config" value="1" aria-describedby="backup-config-hilfe">
            <code>config.php</code> mitsichern
        </label>
        <p class="feld-hilfe" id="backup-config-hilfe">
            Enthält den Server-Schlüssel: Betriebsdaten (Mail-Adressen,
            KI-API-Schlüssel, SMTP-Passwort) sind damit lesbar. Nur für Backups,
            die sicher verwahrt werden. Herunterladen geht dann nur mit Ihrem
            Passwort.
        </p>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer" id="backup-start"<?= $laeuft ? ' hidden' : '' ?>>
                Backup jetzt erstellen
            </button>
            <button type="button" class="knopf" id="backup-fortsetzen"<?= $laeuft ? '' : ' hidden' ?>>
                Laufendes Backup fortsetzen
            </button>
        </p>
    </form>

    <div id="backup-verlauf" hidden>
        <p id="backup-status" aria-live="polite"></p>
        <p id="backup-fehler" class="hinweis hinweis-fehler" hidden></p>
    </div>

    <?php if (($stand ?? null) !== null && $stand['phase'] !== 'fertig'): ?>
        <form method="post" action="/admin/backups/abbrechen">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <?php if (!$laeuft): ?>
                    <span class="gedaempft">Ein abgebrochenes Backup belegt noch Platz.</span>
                <?php endif; ?>
                <button type="submit" class="knopf knopf-still">Backup-Erstellung abbrechen</button>
            </p>
        </form>
    <?php endif; ?>

    <h3>Vorhandene Backups</h3>
    <?php if ($backups === []): ?>
        <p class="gedaempft">Noch keine Backups vorhanden.</p>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle tabelle-karten">
                <caption>Neueste zuerst</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Erstellt</th>
                        <th scope="col" class="zahl">Größe</th>
                        <th scope="col">Inhalt</th>
                        <th scope="col">Herunterladen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td data-label="Name"><code><?= e($backup['name']) ?></code></td>
                            <td data-label="Erstellt"><?= e($datum($backup['geaendert_am'])) ?></td>
                            <td data-label="Größe" class="zahl"><?= e($groesse($backup['groesse'])) ?></td>
                            <td data-label="Inhalt">
                                <?php if ($backup['mit_config']): ?>
                                    <span class="marke marke-warnung">mit config.php</span>
                                <?php else: ?>
                                    <span class="marke">ohne config.php</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Herunterladen">
                                <?php if ($backup['mit_config']): ?>
                                    <form method="post" action="/admin/backups/<?= e($backup['name']) ?>" class="formular">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <label>Ihr Passwort
                                            <input type="password" name="passwort" required autocomplete="current-password">
                                        </label>
                                        <button type="submit" class="knopf">Herunterladen</button>
                                    </form>
                                <?php else: ?>
                                    <a class="knopf" href="/admin/backups/<?= e($backup['name']) ?>" download>Herunterladen</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
