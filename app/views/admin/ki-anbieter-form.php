<?php

/**
 * Create or change one AI provider profile (M7-1, issue #41, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Client").
 *
 * Only components from /admin/designsystem, no JavaScript. The API key
 * field is never filled in - it stays empty, empty means "unchanged", and
 * the checkbox below it removes the key (the same pattern as the SMTP
 * password on /admin/mail). `autocomplete="off"` keeps browsers from
 * offering a stored login password there.
 *
 * @var string $csrf
 * @var \App\Service\Ki\KiAnbieter|null $profil null for a new profile
 * @var array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool} $felder
 * @var string|null $fehler
 */

use App\Service\Ki\KiAnbieterService;
use App\Service\Ki\KiFaehigkeiten;

$neu = $profil === null;
$ziel = $neu ? '/admin/ki-anbieter' : '/admin/ki-anbieter/' . $profil->id;
?>
<section class="schmal">
    <h2><?= e($neu ? 'Neuer KI-Anbieter' : 'KI-Anbieter „' . $profil->name . '“') ?></h2>

    <p><a href="/admin/ki-anbieter">← Alle KI-Anbieter</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="ki-name">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="ki-name" name="name" value="<?= e($felder['name']) ?>"
                   maxlength="<?= e((string) KiAnbieterService::NAME_MAX) ?>" required>
        </label>

        <label for="ki-base-url">Basis-URL <span class="pflicht" aria-hidden="true">*</span>
            <input type="url" id="ki-base-url" name="base_url" value="<?= e($felder['base_url']) ?>"
                   maxlength="<?= e((string) KiAnbieterService::BASE_URL_MAX) ?>" required
                   inputmode="url" spellcheck="false" aria-describedby="ki-base-url-hilfe">
        </label>
        <p class="feld-hilfe" id="ki-base-url-hilfe">
            Nur https://. Ohne „/chat/completions“ – das hängt die Anwendung selbst an,
            z. B. https://api.openai.com/v1.
        </p>

        <label for="ki-model">Modell <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="ki-model" name="model" value="<?= e($felder['model']) ?>"
                   maxlength="<?= e((string) KiAnbieterService::MODEL_MAX) ?>" required spellcheck="false">
        </label>

        <label for="ki-api-key">API-Key
            <input type="password" id="ki-api-key" name="api_key" value="" autocomplete="off"
                   maxlength="<?= e((string) KiAnbieterService::KEY_MAX) ?>" spellcheck="false"
                   aria-describedby="ki-api-key-hilfe">
        </label>
        <p class="feld-hilfe" id="ki-api-key-hilfe">
            <?php if ($neu || !$profil->keyGesetzt): ?>
                Noch kein Key hinterlegt. Ohne Key wird dieses Profil nicht verwendet.
            <?php else: ?>
                Ein Key ist hinterlegt und wird nicht angezeigt. Leer lassen, um ihn zu behalten.
            <?php endif; ?>
            Der Key wird mit dem Server-Schlüssel verschlüsselt gespeichert.
        </p>
        <?php if (!$neu && $profil->keyGesetzt): ?>
            <label class="feld-ankreuz">
                <input type="checkbox" name="api_key_entfernen" value="1">
                Gespeicherten API-Key entfernen
            </label>
        <?php endif; ?>

        <fieldset>
            <legend>Fähigkeiten des Modells</legend>

            <label class="feld-ankreuz">
                <input type="checkbox" name="vision" value="1" <?= $felder['vision'] ? 'checked' : '' ?>>
                Verarbeitet Bilder (Vision)
            </label>

            <label class="feld-ankreuz">
                <input type="checkbox" name="json_schema" value="1" aria-describedby="ki-json-schema-hilfe" <?= $felder['json_schema'] ? 'checked' : '' ?>>
                Unterstützt JSON-Schema (response_format)
            </label>
            <p class="feld-hilfe" id="ki-json-schema-hilfe">
                Ohne JSON-Schema steht das Schema im Prompt und die Antwort wird nachträglich geprüft.
            </p>

            <label for="ki-max-images" class="feld-kurz">Bilder je Aufruf
                <input type="number" id="ki-max-images" name="max_images" value="<?= e($felder['max_images']) ?>"
                       min="0" max="<?= e((string) KiFaehigkeiten::MAX_IMAGES_GRENZE) ?>" step="1" required
                       aria-describedby="ki-max-images-hilfe">
            </label>
            <p class="feld-hilfe" id="ki-max-images-hilfe">Weitere Seiten eines Belegs gehen nur als Text mit.</p>

            <label for="ki-max-tokens" class="feld-kurz">Max. Tokens der Antwort
                <input type="number" id="ki-max-tokens" name="max_tokens" value="<?= e($felder['max_tokens']) ?>"
                       min="1" max="<?= e((string) KiFaehigkeiten::MAX_TOKENS_GRENZE) ?>" step="1" required>
            </label>
        </fieldset>

        <label for="ki-timeout" class="feld-kurz">Zeitlimit in Sekunden
            <input type="number" id="ki-timeout" name="timeout_s" value="<?= e($felder['timeout_s']) ?>"
                   min="<?= e((string) KiAnbieterService::TIMEOUT_MIN) ?>" max="<?= e((string) KiAnbieterService::TIMEOUT_MAX) ?>" step="1" required>
        </label>

        <label class="feld-ankreuz">
            <input type="checkbox" name="active" value="1" <?= $felder['active'] ? 'checked' : '' ?>>
            Aktiv
        </label>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
            <a class="knopf" href="/admin/ki-anbieter">Abbrechen</a>
        </p>
    </form>

    <?php if (!$neu): ?>
        <h3>Profil löschen</h3>
        <?php if ($profil->isDefault): ?>
            <p class="gedaempft">
                Das Standardprofil lässt sich nicht löschen. Zuerst ein anderes Profil zum Standard machen.
            </p>
        <?php else: ?>
            <form method="post" action="/admin/ki-anbieter/<?= e((string) $profil->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Profil löschen</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
