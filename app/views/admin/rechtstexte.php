<?php

/**
 * Admin page for the legal texts (issue #80/M13-3, E-46): Impressum,
 * Datenschutzerklärung and the notice on /einreichen, in one form. Only
 * components from /admin/designsystem.
 *
 * @var string $csrf
 * @var list<array{art: \App\Service\Rechtstexte\Rechtstext, text: string, istVorlage: bool, platzhalter: list<string>}> $felder
 * @var list<\App\Service\Ki\KiAnbieter> $kiAnbieter usable AI profiles (active, with key)
 * @var ?string $fehler
 */
?>
<section class="schmal">
    <h2>Rechtstexte</h2>

    <p class="hinweis hinweis-warnung">
        Die mitgelieferten Texte sind <strong>Vorlagen</strong> und keine Rechtsberatung. Der Verein muss sie
        prüfen, die Platzhalter in eckigen Klammern ausfüllen und bei Bedarf rechtlich beraten lassen.
    </p>

    <p class="gedaempft">
        Formatierung: Leerzeile = neuer Absatz, <code>## Überschrift</code>, <code>- Aufzählung</code>,
        <code>**fett**</code>, <code>[Linktext](https://…)</code> bzw. <code>[Linktext](/datenschutz)</code>.
        HTML wird nicht übernommen, sondern als Text angezeigt. Ein leeres Feld stellt die Vorlage wieder her.
    </p>

    <?php if ($kiAnbieter !== []): ?>
        <h3>Genutzte KI-Anbieter</h3>
        <p>Die Datenschutzerklärung sollte diese Anbieter nennen; mit Cloud-Anbietern (z. B. OpenAI, Anthropic)
            braucht der Verein einen Vertrag zur Auftragsverarbeitung (AV-Vertrag).</p>
        <ul>
            <?php foreach ($kiAnbieter as $anbieter): ?>
                <li><?= e($anbieter->name) ?> <span class="gedaempft">(<?= e(parse_url($anbieter->baseUrl, PHP_URL_HOST) ?: $anbieter->baseUrl) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/rechtstexte" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <?php foreach ($felder as $feld): ?>
            <?php $id = 'rechtstext-' . $feld['art']->value; ?>
            <label for="<?= e($id) ?>"><?= e($feld['art']->titel()) ?>
                <?php if ($feld['istVorlage']): ?>
                    <span class="marke marke-warnung">Vorlage</span>
                <?php elseif ($feld['platzhalter'] !== []): ?>
                    <span class="marke marke-warnung">Platzhalter offen</span>
                <?php else: ?>
                    <span class="marke marke-ok">Angepasst</span>
                <?php endif; ?>
                <textarea id="<?= e($id) ?>" name="<?= e($feld['art']->value) ?>" rows="14"
                          aria-describedby="<?= e($id) ?>-hilfe"><?= e($feld['text']) ?></textarea>
            </label>
            <p class="feld-hilfe" id="<?= e($id) ?>-hilfe">
                <?php if ($feld['art']->pfad() !== null): ?>
                    Öffentlich unter <a href="<?= e($feld['art']->pfad()) ?>"><?= e($feld['art']->pfad()) ?></a>.
                <?php else: ?>
                    Steht auf <a href="/einreichen">/einreichen</a> über der Datenschutz-Checkbox.
                <?php endif; ?>
                <?php if ($feld['platzhalter'] !== []): ?>
                    Noch offen: <?= e(implode(', ', array_slice($feld['platzhalter'], 0, 8))) ?><?= count($feld['platzhalter']) > 8 ? ' …' : '' ?>
                <?php endif; ?>
            </p>
        <?php endforeach; ?>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
        </p>
    </form>
</section>
