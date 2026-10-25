<?php

/**
 * Code integrity check (issue #79/M13-2, docs/spec/01-sicherheit.md
 * section 8): start/continue the check and the result of the last run.
 *
 * Only components from /admin/designsystem, no page-specific CSS. The step
 * chain runs in public/js/integritaet.js - never an inline script (CLAUDE.md
 * section 4). The path lists are plain lists, so 360 px needs no sideways
 * scrolling; long paths wrap inside <code>.
 *
 * @var string $csrf
 * @var string|null $hindernis why no check can run, or null
 * @var string|null $warnung the list names another version than current/VERSION
 * @var array<string, mixed>|null $stand the current or last run
 */

use App\Service\Integritaet\CodeIntegritaet;

$datum = static function (?string $wert): string {
    $zeit = $wert === null ? false : \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $wert);

    return $zeit === false ? (string) $wert : $zeit->format('d.m.Y H:i');
};
$ueberschrift = [
    'geaendert' => 'Geänderte Dateien',
    'fehlend' => 'Fehlende Dateien',
    'zusaetzlich' => 'Zusätzliche Dateien (nicht Teil des Releases)',
];
$erklaerung = [
    'geaendert' => 'Inhalt weicht von der Prüfsumme des Releases ab, die Datei ist nicht lesbar oder durch einen '
        . 'Verweis (Symlink) ersetzt; ein Verzeichnis mit „/“ am Ende ließ sich nicht auflisten.',
    'fehlend' => 'Gehört zum Release, liegt aber nicht in current/.',
    'zusaetzlich' => 'Liegt in current/, gehört aber nicht zum Release – zum Beispiel ein nachträglich hochgeladenes Skript.',
];
$fertig = $stand !== null && $stand['phase'] === CodeIntegritaet::PHASE_FERTIG;
$offen = $stand !== null && !$fertig;
?>
<section class="schmal">
    <h2>Code-Integrität</h2>

    <p class="gedaempft">
        Vergleicht jede Datei des installierten Releases (<code>current/</code>) mit den Prüfsummen, die das Release
        mitbringt. So fallen nachträglich veränderte, gelöschte oder hinzugefügte Dateien auf dem Webspace auf.
        Kein Schutz gegen einen versierten Angreifer – wer Dateien ändern kann, kann auch die Prüfsummenliste
        ändern –, aber plumpe Manipulationen und versehentliche Änderungen per FTP werden sichtbar. Jede Prüfung
        steht im Audit-Log.
    </p>

    <?php if ($hindernis !== null): ?>
        <p class="hinweis hinweis-info" id="integritaet-hindernis"><?= e($hindernis) ?></p>
    <?php else: ?>
        <?php if ($warnung !== null): ?>
            <p class="hinweis hinweis-warnung" id="integritaet-warnung"><?= e($warnung) ?></p>
        <?php endif; ?>
        <noscript>
            <p class="hinweis hinweis-fehler">Für die Prüfung wird JavaScript benötigt.</p>
        </noscript>
        <form method="post" action="/admin/integritaet" id="integritaet-pruefen" class="formular"
              data-csrf="<?= e($csrf) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer" id="integritaet-start">
                    <?= $stand === null ? 'Jetzt prüfen' : 'Neu prüfen' ?>
                </button>
                <button type="button" class="knopf" id="integritaet-fortsetzen"<?= $offen ? '' : ' hidden' ?>>
                    Laufende Prüfung fortsetzen
                </button>
            </p>
        </form>

        <div id="integritaet-verlauf" hidden>
            <p id="integritaet-status" aria-live="polite"></p>
            <p id="integritaet-fehler" class="hinweis hinweis-fehler" hidden></p>
        </div>
    <?php endif; ?>

    <?php if ($offen): ?>
        <p class="hinweis hinweis-warnung" id="integritaet-unvollstaendig">
            Die letzte Prüfung (begonnen <?= e($datum((string) $stand['gestartet'])) ?>) wurde nicht abgeschlossen –
            <?= e((int) $stand['position']) ?> von <?= e((int) $stand['gesamt']) ?> Dateien geprüft.
        </p>
    <?php endif; ?>

    <?php if ($fertig): ?>
        <h3>Ergebnis der letzten Prüfung</h3>
        <p class="gedaempft">
            <?= e($datum((string) $stand['beendet'])) ?>, Version <?= e((string) ($stand['version'] ?? '–')) ?>,
            <?= e((int) $stand['gesamt']) ?> Dateien laut Prüfsummenliste.
        </p>
        <?php if (CodeIntegritaet::unveraendert($stand)): ?>
            <p class="hinweis hinweis-ok" id="integritaet-ergebnis" role="status">
                <strong>Keine Abweichung:</strong> alle Dateien entsprechen dem Release, keine zusätzlichen Dateien.
            </p>
        <?php else: ?>
            <div class="hinweis hinweis-fehler" id="integritaet-ergebnis" role="status">
                <p>
                    <strong>Abweichungen gefunden:</strong>
                    <?= e((int) $stand['anzahl']['geaendert']) ?> geändert,
                    <?= e((int) $stand['anzahl']['fehlend']) ?> fehlend,
                    <?= e((int) $stand['anzahl']['zusaetzlich']) ?> zusätzlich.
                </p>
                <p>
                    Unerklärliche Abweichungen ernst nehmen: Zugangsdaten zum Webspace ändern und das Release über
                    „Update“ bzw. <code>setup.php</code> neu einspielen.
                </p>
            </div>
            <?php foreach (CodeIntegritaet::ARTEN as $art): ?>
                <?php $zahl = (int) $stand['anzahl'][$art]; ?>
                <?php if ($zahl > 0): ?>
                <h4><?= e($ueberschrift[$art]) ?> <span class="marke marke-fehler"><?= e($zahl) ?></span></h4>
                <p class="feld-hilfe"><?= e($erklaerung[$art]) ?></p>
                <ul id="integritaet-<?= e($art) ?>">
                    <?php foreach ((array) $stand['pfade'][$art] as $pfad): ?>
                        <li><code><?= e((string) $pfad) ?></code></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($zahl > count((array) $stand['pfade'][$art])): ?>
                    <p class="gedaempft">… und <?= e($zahl - count((array) $stand['pfade'][$art])) ?> weitere.</p>
                <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php elseif ($stand === null && $hindernis === null): ?>
        <p class="gedaempft">Noch keine Prüfung durchgeführt.</p>
    <?php endif; ?>
</section>
