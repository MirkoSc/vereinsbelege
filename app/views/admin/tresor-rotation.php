<?php

/**
 * Vault key rotation (issue #90, docs/spec/01-sicherheit.md section 2
 * "Schlüsselrotation"). Only components from /admin/designsystem; the step
 * chain runs from public/js/tresor-rotation.js (no inline script, CSP).
 *
 * @var string $csrf
 * @var \App\Service\Tresor\RotationStand $stand
 * @var bool $entsperrt
 * @var bool $ringVollstaendig
 * @var list<string>|null $schluesselGruppen
 */
$zahl = static fn(int $n): string => number_format($n, 0, ',', '.');
?>
<section class="schmal">
    <h2>Tresor-Schlüssel wechseln</h2>

    <?php if (!$stand->laeuft): ?>
        <p class="gedaempft">
            Der Tresor-Schlüssel schützt alle Belege, Buchungen und Lieferanten. Ein Wechsel ist sinnvoll, wenn
            der Wiederherstellungsschlüssel in falsche Hände geraten sein könnte oder ein Administrator mit
            Tresor-Zugang ausgeschieden ist, dem nicht mehr vertraut wird.
        </p>

        <div class="karte">
            <h3>Was beim Wechsel passiert</h3>
            <ul>
                <li>Ein neuer Tresor-Schlüssel wird erzeugt. Alle Benutzer mit Freigabe erhalten automatisch auch
                    eine Freigabe für den neuen Schlüssel – niemand muss dafür sein Passwort eingeben.</li>
                <li>Sie erhalten einen <strong>neuen Wiederherstellungsschlüssel</strong>, den Sie ausdrucken und
                    bestätigen müssen.</li>
                <li>Danach werden alle Datenschlüssel in kurzen Schritten auf den neuen Schlüssel umgestellt. Die
                    Daten selbst bleiben unverändert.</li>
                <li>Solange der Wechsel läuft, sind Belege, Konten und Einstellungen für alle gesperrt. Die
                    öffentliche Einreichung funktioniert weiter.</li>
                <li>Zum Abschluss werden alle Sitzungen beendet; alle melden sich neu an.</li>
            </ul>
        </div>

        <p class="hinweis hinweis-warnung">
            Den <strong>alten</strong> Wiederherstellungsschlüssel nicht vernichten: Backups, die vor dem Wechsel
            entstanden sind, lassen sich nur mit ihm wiederherstellen. Am besten vorher noch ein Backup erstellen.
        </p>

        <?php if (!$entsperrt): ?>
            <p class="hinweis hinweis-fehler" role="alert">
                Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte ab- und wieder anmelden.
            </p>
        <?php else: ?>
            <form method="post" action="/admin/tresor/rotation/starten" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label class="feld-ankreuz">
                    <input type="checkbox" name="verstanden" value="1" required>
                    Ich habe die Folgen gelesen und kann den neuen Wiederherstellungsschlüssel jetzt sicher
                    aufbewahren.
                </label>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer">Schlüsselwechsel starten</button>
                    <a href="/admin/tresor">Abbrechen</a>
                </p>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p class="hinweis hinweis-warnung" role="status">
            Der Tresor-Schlüssel wird gewechselt (Generation <?= e((string) $stand->alteVersion) ?> →
            <?= e((string) $stand->neueVersion) ?>). Bis zum Abschluss ist die Anwendung für alle anderen gesperrt.
        </p>

        <?php if (!$ringVollstaendig): ?>
            <p class="hinweis hinweis-fehler" role="alert">
                Ihre Sitzung hält nicht beide Tresor-Schlüssel. Bitte ab- und wieder anmelden – danach geht es
                hier weiter.
            </p>
        <?php endif; ?>

        <div class="karte">
            <h3>1. Neuer Wiederherstellungsschlüssel</h3>
            <?php if ($stand->schluesselBestaetigt): ?>
                <p><span class="marke marke-ok">Bestätigt</span></p>
            <?php elseif ($schluesselGruppen !== null): ?>
                <p class="hinweis hinweis-warnung nicht-drucken">
                    Dieser Schlüssel ersetzt jedes vergessene Admin-Passwort für alle Daten ab jetzt. Ausdrucken oder
                    von Hand abschreiben und im Vereinstresor aufbewahren – <strong>neben</strong> dem alten
                    Schlüssel, der für ältere Backups gebraucht wird.
                </p>
                <div class="schluessel-gruppen" aria-label="Neuer Wiederherstellungsschlüssel, sieben Gruppen zu acht Zeichen">
                    <?php foreach ($schluesselGruppen as $gruppe): ?>
                        <span class="schluessel-gruppe"><?= e($gruppe) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="nur-druck">
                    Vereinsbelege – Wiederherstellungsschlüssel, Tresor-Generation <?= e((string) $stand->neueVersion) ?>.
                    Im Vereinstresor aufbewahren.
                </p>
                <p class="nicht-drucken">
                    <button type="button" id="rotation-drucken" class="knopf">Zum Aufbewahren drucken</button>
                </p>
                <form method="post" action="/admin/tresor/rotation/bestaetigen" class="formular nicht-drucken">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label for="rotation-gruppe">Letzte Gruppe des neuen Schlüssels
                        <input type="text" id="rotation-gruppe" name="letzte_gruppe" maxlength="9"
                               autocomplete="off" autocapitalize="characters" spellcheck="false" required>
                    </label>
                    <p class="knopfreihe">
                        <button type="submit" class="knopf knopf-primaer">Schlüssel bestätigen</button>
                    </p>
                </form>
            <?php else: ?>
                <p class="gedaempft">Noch nicht bestätigt. Anzeigen kann ihn nur eine Sitzung mit beiden Schlüsseln.</p>
            <?php endif; ?>
        </div>

        <div class="karte nicht-drucken" id="rotation" data-csrf="<?= e($csrf) ?>">
            <h3>2. Daten umschlüsseln</h3>
            <p id="rotation-stand" aria-live="polite">
                <?php if (!$stand->schluesselBestaetigt): ?>
                    Erst nach der Bestätigung des neuen Wiederherstellungsschlüssels möglich – bis dahin wird nichts
                    an den neuen Schlüssel versiegelt.
                <?php elseif ($stand->offen() === 0): ?>
                    Alle Datenschlüssel sind umgestellt.
                <?php else: ?>
                    Noch <?= e($zahl($stand->offen())) ?> Datensätze mit dem alten Schlüssel.
                <?php endif; ?>
            </p>
            <p class="knopfreihe">
                <button type="button" id="rotation-start" class="knopf"<?= $stand->umgeschluesselt() || !$ringVollstaendig || !$stand->schluesselBestaetigt ? ' hidden' : '' ?>>
                    Umschlüsseln starten
                </button>
            </p>
            <?php if ($stand->indexeVerworfen > 0): ?>
                <p class="hinweis hinweis-warnung">
                    Bei <?= e($zahl($stand->indexeVerworfen)) ?> Datensätzen ließen sich die Suchschlüssel nicht neu
                    berechnen; sie wurden geleert (Duplikat- und IBAN-Erkennung fehlt dort). Das steht auch im Audit-Log.
                </p>
            <?php endif; ?>
            <p id="rotation-fehler" class="hinweis hinweis-fehler" hidden></p>
        </div>

        <?php if (!$stand->schluesselBestaetigt): ?>
            <form method="post" action="/admin/tresor/rotation/abbrechen" class="formular nicht-drucken">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="gedaempft">
                    Solange der neue Schlüssel nicht bestätigt ist, lässt sich der Wechsel ohne Folgen abbrechen –
                    es gilt dann weiter der bisherige Schlüssel.
                </p>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-still">Schlüsselwechsel abbrechen</button>
                </p>
            </form>
        <?php endif; ?>

        <div class="karte nicht-drucken">
            <h3>3. Abschließen</h3>
            <p class="gedaempft">
                Löscht die Freigaben für den alten Schlüssel und beendet alle Sitzungen. Erst möglich, wenn der neue
                Wiederherstellungsschlüssel bestätigt und alles umgeschlüsselt ist.
            </p>
            <form method="post" action="/admin/tresor/rotation/abschliessen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer" id="rotation-abschliessen"<?= $stand->kannAbschliessen() && $ringVollstaendig ? '' : ' disabled' ?>>
                        Schlüsselwechsel abschließen
                    </button>
                </p>
            </form>
        </div>
    <?php endif; ?>
</section>
