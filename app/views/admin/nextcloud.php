<?php

/**
 * Admin page for the Nextcloud filing of the export (issue #95,
 * docs/spec/05-auswertung-und-export.md section 2 "Nextcloud-Ablage"):
 * WebDAV address, user, app password; connection test; removal.
 *
 * Only components from /admin/designsystem, no page-specific CSS/JS. The
 * values are vault data: without the unlocked vault the page only says
 * whether a target is stored. The password is never shown.
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var bool $eingerichtet
 * @var string $url
 * @var string $benutzer
 * @var ?string $fehler
 */

use App\Service\Export\Nextcloud\NextcloudZiel;

?>
<section class="schmal">
    <h2>Nextcloud-Ablage</h2>

    <p><a href="/admin/export">← Export</a></p>

    <p class="gedaempft">
        Statt das ZIP herunter- und wieder hochzuladen, kann ein Export direkt in einen Ordner der
        Vereins-Nextcloud gelegt werden – mit denselben Ordnern und Dateinamen wie im ZIP und der
        <code>index.csv</code>. Vorhandene Dateien werden nie überschrieben.
    </p>

    <p>
        Status:
        <?php if ($eingerichtet): ?>
            <span class="marke marke-ok">eingerichtet</span>
        <?php else: ?>
            <span class="marke">nicht eingerichtet</span>
        <?php endif; ?>
    </p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Adresse und Zugangsdaten liegen verschlüsselt im Tresor. Melden Sie sich neu an, um sie
            einzusehen oder zu ändern.
        </p>
    <?php else: ?>
        <form method="post" action="/admin/export/nextcloud" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label for="nextcloud-url">WebDAV-Adresse des Ordners <span class="pflicht" aria-hidden="true">*</span>
                <input type="url" id="nextcloud-url" name="url" value="<?= e($url) ?>" required
                       maxlength="<?= e((string) NextcloudZiel::URL_MAX) ?>" inputmode="url" spellcheck="false"
                       autocomplete="off" aria-describedby="nextcloud-url-hilfe"<?= $fehler !== null ? ' aria-invalid="true"' : '' ?>>
            </label>
            <p class="feld-hilfe" id="nextcloud-url-hilfe">
                Nur https://. In der Nextcloud unter „Dateien → Einstellungen → WebDAV“ steht die Adresse
                der eigenen Dateien; daran den Ordner anhängen, z. B.
                <code>https://cloud.example.org/remote.php/dav/files/kasse/Belege</code>. Der Ordner muss
                schon existieren; darin entsteht je Export ein Ordner wie „Belege_2026“.
            </p>

            <label for="nextcloud-benutzer">Benutzer <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="nextcloud-benutzer" name="benutzer" value="<?= e($benutzer) ?>" required
                       maxlength="<?= e((string) NextcloudZiel::BENUTZER_MAX) ?>" spellcheck="false" autocomplete="off">
            </label>

            <label for="nextcloud-passwort">App-Passwort<?= $eingerichtet ? '' : ' <span class="pflicht" aria-hidden="true">*</span>' ?>
                <input type="password" id="nextcloud-passwort" name="passwort" value="" autocomplete="off"
                       maxlength="<?= e((string) NextcloudZiel::PASSWORT_MAX) ?>" spellcheck="false"
                       aria-describedby="nextcloud-passwort-hilfe"<?= $eingerichtet ? '' : ' required' ?>>
            </label>
            <p class="feld-hilfe" id="nextcloud-passwort-hilfe">
                Bitte ein eigenes App-Passwort anlegen (Nextcloud: „Persönliche Einstellungen → Sicherheit →
                Neues App-Passwort erstellen“), nicht das Anmeldepasswort.
                <?php if ($eingerichtet): ?>
                    Ein Passwort ist hinterlegt und wird nicht angezeigt. Leer lassen, um es zu behalten.
                <?php endif; ?>
                Es liegt verschlüsselt im Tresor und ist nur in einer angemeldeten Sitzung nutzbar.
            </p>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Speichern</button>
            </p>
        </form>

        <?php if ($eingerichtet): ?>
            <h3>Verbindung</h3>
            <form method="post" action="/admin/export/nextcloud/testen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="feld-hilfe">Prüft, ob die Nextcloud erreichbar ist, die Anmeldung klappt und der Ordner existiert.</p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf">Verbindung testen</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($eingerichtet): ?>
        <h3>Entfernen</h3>
        <form method="post" action="/admin/export/nextcloud/entfernen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="feld-hilfe">Löscht Adresse und Zugangsdaten. Bereits abgelegte Dateien bleiben in der Nextcloud.</p>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-gefahr">Nextcloud-Ablage entfernen</button>
            </p>
        </form>
    <?php endif; ?>
</section>
