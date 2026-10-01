<?php

/**
 * Systemcheck (M3-10, issue #107, docs/spec/06-betrieb.md section 5): the
 * M0 assumptions, checked on the running system.
 *
 * Only components from /admin/designsystem plus the card layout of the table
 * (.tabelle-karten): below 48 rem every row becomes a card whose cells carry
 * their column name in data-label, so 360 px needs no sideways scrolling.
 * The copy button lives in public/js/systemcheck.js - no inline script
 * (CLAUDE.md section 4).
 *
 * @var \App\Service\SystemCheck\CheckStatus $gesamt
 * @var list<\App\Service\SystemCheck\CheckResult> $ergebnisse
 * @var string $json
 */

use App\Service\SystemCheck\CheckStatus;

$statusText = static fn(CheckStatus $status): string => match ($status) {
    CheckStatus::Ok => 'In Ordnung',
    CheckStatus::Warn => 'Warnung',
    CheckStatus::Fail => 'Fehler',
};
$hinweisKlasse = static fn(CheckStatus $status): string => match ($status) {
    CheckStatus::Ok => 'hinweis-ok',
    CheckStatus::Warn => 'hinweis-warnung',
    CheckStatus::Fail => 'hinweis-fehler',
};
$markeKlasse = static fn(CheckStatus $status): string => match ($status) {
    CheckStatus::Ok => 'marke-ok',
    CheckStatus::Warn => 'marke-warnung',
    CheckStatus::Fail => 'marke-fehler',
};
?>
<section>
    <h2>Systemcheck</h2>

    <p class="gedaempft">
        Gelten die Annahmen aus dem Hosting-Check noch? Der Hoster kann Einstellungen im Betrieb ändern, ohne dass
        es jemand merkt – bis ein Stacktrace Argumente ausgibt oder ein Jobschritt mitten im Schreiben abbricht.
        Die Seite zeigt nur Einstellungen, keine Zugangsdaten und keine Belegdaten.
    </p>

    <div class="hinweis <?= e($hinweisKlasse($gesamt)) ?>" id="systemcheck-gesamt" role="status">
        <p><strong>Gesamtstatus: <?= e($statusText($gesamt)) ?></strong> – der schlechteste Einzelstatus gilt.</p>
    </div>

    <div class="tabelle-rahmen">
        <table class="tabelle tabelle-karten">
            <caption>Geprüft beim Aufruf der Seite; zum erneuten Prüfen die Seite neu laden.</caption>
            <thead>
                <tr>
                    <th scope="col">Prüfpunkt</th>
                    <th scope="col">Erwartung</th>
                    <th scope="col">Ist-Wert</th>
                    <th scope="col">Status</th>
                    <th scope="col">Erläuterung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ergebnisse as $ergebnis): ?>
                    <tr>
                        <th scope="row" data-label="Prüfpunkt"><?= e($ergebnis->label) ?></th>
                        <td data-label="Erwartung" class="tabelle-kurz"><?= e($ergebnis->expected) ?></td>
                        <td data-label="Ist-Wert" class="tabelle-kurz"><?= e($ergebnis->actual) ?></td>
                        <td data-label="Status" class="tabelle-kurz">
                            <span class="marke <?= e($markeKlasse($ergebnis->status)) ?>"><?= e($statusText($ergebnis->status)) ?></span>
                        </td>
                        <td data-label="Erläuterung"><?= e($ergebnis->detail) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3>Ergebnis als JSON</h3>
    <p class="gedaempft">Zum Kopieren in ein Issue, wenn ein Befund vom erwarteten Wert abweicht.</p>
    <label for="systemcheck-json">Ergebnis (JSON)</label>
    <textarea id="systemcheck-json" rows="12" readonly spellcheck="false"><?= e($json) ?></textarea>
    <p class="knopfreihe">
        <button type="button" id="systemcheck-kopieren" class="knopf" hidden>In die Zwischenablage kopieren</button>
        <span id="systemcheck-kopiert" class="gedaempft" role="status" aria-live="polite"></span>
    </p>
</section>
