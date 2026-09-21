<section class="schmal">
    <h2>Update</h2>

    <p class="hinweis hinweis-warnung">
        <strong>Dieser Bereich ist noch nicht geschützt.</strong> Anmeldung und Rechte
        kommen mit Meilenstein M3. Bis dahin gehört eine öffentlich erreichbare
        Installation zusätzlich hinter einen Passwortschutz des Hosters.
    </p>

    <?php if (($flash ?? null) !== null): ?>
        <p class="hinweis hinweis-ok"><?= e($flash) ?></p>
    <?php endif; ?>

    <?php if (($wartung ?? null) !== null): ?>
        <div class="hinweis hinweis-warnung">
            <p>
                <strong>Wartungsmodus ist aktiv</strong> (<?= e($wartung['grund']) ?><?php
                    if ($wartung['seit'] !== '') {
                        echo ', seit ' . e($wartung['seit']);
                    }
                ?>). Außerhalb von <code>/admin</code> antwortet die Seite mit 503.
            </p>
            <form method="post" action="/admin/wartung/aufheben">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf">Wartungsmodus aufheben</button>
            </form>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/update/kanal">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Release-Kanal
            <select name="kanal">
                <option value="stable" <?= $kanal === 'stable' ? 'selected' : '' ?>>
                    stable – nur reguläre Releases
                </option>
                <option value="beta" <?= $kanal === 'beta' ? 'selected' : '' ?>>
                    beta – auch Vorabversionen (Testinstanz)
                </option>
            </select>
        </label>
        <p><button type="submit" class="knopf knopf-still">Kanal speichern</button></p>
    </form>

    <div id="update" data-csrf="<?= e($csrf) ?>" data-schritte="<?= e(implode(',', $schritte)) ?>">
        <p>
            <button type="button" id="update-suchen" class="knopf">Nach Updates suchen</button>
            <button type="button" id="update-starten" class="knopf" hidden>Update starten</button>
        </p>

        <div id="update-verlauf" hidden>
            <p id="update-status" aria-live="polite"></p>
            <ul id="update-log"></ul>
            <p id="update-fehler" class="hinweis hinweis-fehler" hidden></p>
            <p id="update-aktionen" hidden>
                <button type="button" id="update-wiederholen" class="knopf">Schritt wiederholen</button>
                <button type="button" id="update-rollback" class="knopf knopf-gefahr">
                    Rollback auf vorheriges Release
                </button>
            </p>
        </div>
    </div>

    <?php if (($state ?? null) !== null): ?>
        <h3>Letzter Update-Status</h3>
        <p>
            Version <?= e($state->aktuelleVersion) ?><?php
                if ($state->zielVersion !== null) {
                    echo ' → ' . e($state->zielVersion);
                }
            ?>,
            Schritt: <?= e($state->abgeschlossenerSchritt ?? '–') ?>,
            <?= $state->fertig ? 'abgeschlossen' : 'offen' ?>
        </p>
        <?php if ($state->fehler !== null): ?>
            <p class="hinweis hinweis-fehler"><?= e($state->fehler) ?></p>
        <?php endif; ?>
        <?php if ($state->meldungen !== []): ?>
            <ul>
                <?php foreach ($state->meldungen as $meldung): ?>
                    <li><?= e($meldung) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="/admin/update/reset">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knopf knopf-still">Status zurücksetzen</button>
        </form>
    <?php endif; ?>
</section>
