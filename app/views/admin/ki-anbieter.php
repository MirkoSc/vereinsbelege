<?php

/**
 * AI provider profiles (M7-1, issue #41, docs/spec/03-erfassung-und-ki.md
 * section 6 "Client"): the list with default, status and "Verbindung
 * testen", and a new profile from a template.
 *
 * Only components from /admin/designsystem, no JavaScript. One .karte per
 * profile instead of a table: with name, address, model, status and three
 * actions a table hid the buttons behind a sideways scroll even on the
 * desktop. The actions are small POST forms in a <div class="knopfreihe">
 * (a <form> inside a <p> would close the paragraph). "Verbindung testen" waits for the provider (at most
 * App\Service\Ki\Verbindungstest::MAX_TIMEOUT_S) and answers with a flash
 * message.
 *
 * @var string $csrf
 * @var list<\App\Service\Ki\KiAnbieter> $profile default first
 * @var list<\App\Service\Ki\KiVorlage> $vorlagen
 */

$standard = null;
foreach ($profile as $profil) {
    if ($profil->isDefault) {
        $standard = $profil;
    }
}
?>
<section>
    <h2>KI-Anbieter</h2>

    <p class="gedaempft">
        Die KI liest Belege aus und schlägt Lieferant, Beträge und Kategorie vor. Angesprochen wird
        jeder Anbieter über dieselbe OpenAI-kompatible Schnittstelle; ein Profil legt Adresse,
        Modell und Fähigkeiten fest. Der API-Key wird verschlüsselt gespeichert und nie wieder
        angezeigt.
    </p>

    <?php if ($standard === null || !$standard->nutzbar()): ?>
        <p class="hinweis hinweis-warnung" role="status">
            KI-Funktionen sind ausgeschaltet: Das Standardprofil
            <?= $standard === null ? '' : '„' . e($standard->name) . '“ ' ?>hat
            <?= $standard !== null && !$standard->active ? 'den Status „inaktiv“' : 'keinen API-Key' ?>.
            Belege werden dann nur von Hand erfasst.
        </p>
    <?php endif; ?>

    <p class="hinweis hinweis-info">
        Datenschutz: Der Anbieter sieht die Belege im Klartext, auch Namen und IBANs von
        Einreichern. Für OpenAI und Anthropic ist ein Auftragsverarbeitungsvertrag nötig.
    </p>

    <form method="get" action="/admin/ki-anbieter/neu" class="formular">
        <label for="ki-vorlage">Neues Profil aus Vorlage
            <select id="ki-vorlage" name="vorlage">
                <?php foreach ($vorlagen as $vorlage): ?>
                    <option value="<?= e($vorlage->value) ?>"><?= e($vorlage->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Neues Profil</button>
        </p>
    </form>

    <?php if ($profile === []): ?>
        <div class="leer">Keine Profile vorhanden.</div>
    <?php endif; ?>
    <?php foreach ($profile as $profil): ?>
        <div class="karte">
            <h3>
                <a href="/admin/ki-anbieter/<?= e((string) $profil->id) ?>"><?= e($profil->name) ?></a>
                <?php if ($profil->isDefault): ?>
                    <span class="marke marke-ok">Standard</span>
                <?php endif; ?>
                <?php if (!$profil->active): ?>
                    <span class="marke">Inaktiv</span>
                <?php endif; ?>
                <?php if ($profil->keyGesetzt): ?>
                    <span class="marke">API-Key hinterlegt</span>
                <?php else: ?>
                    <span class="marke marke-warnung">Kein API-Key</span>
                <?php endif; ?>
            </h3>
            <p class="gedaempft">
                Modell: <?= e($profil->model) ?><br>
                <?= e($profil->baseUrl) ?>
            </p>
            <div class="knopfreihe">
                <form method="post" action="/admin/ki-anbieter/<?= e((string) $profil->id) ?>/testen">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="knopf" aria-label="Verbindung von „<?= e($profil->name) ?>“ testen"<?= $profil->keyGesetzt ? '' : ' disabled' ?>>Verbindung testen</button>
                </form>
                <?php if (!$profil->isDefault && $profil->active): ?>
                    <form method="post" action="/admin/ki-anbieter/<?= e((string) $profil->id) ?>/standard">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="knopf knopf-still" aria-label="„<?= e($profil->name) ?>“ als Standard">Als Standard</button>
                    </form>
                <?php endif; ?>
                <a class="knopf knopf-still" href="/admin/ki-anbieter/<?= e((string) $profil->id) ?>" aria-label="„<?= e($profil->name) ?>“ bearbeiten">Bearbeiten</a>
            </div>
        </div>
    <?php endforeach; ?>
</section>
