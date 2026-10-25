<?php

/**
 * Admin page "Worker" (docs/spec/07-worker.md sections 4 and 6): pairing
 * code, fingerprint confirmation, unpairing (issue #51/M7b-1); module
 * switch, status, queue, last errors, throughput, "OCR nachholen" (#53);
 * OCR backfill of the imported archive (#88).
 *
 * Only components from /admin/designsystem, no page-specific CSS/JS.
 *
 * @var string $csrf
 * @var ?\App\Domain\Worker $worker
 * @var string $fingerabdruck
 * @var bool $protokollOk
 * @var ?string $code
 * @var ?\DateTimeImmutable $codeBis
 * @var ?\DateTimeImmutable $offenerCodeBis
 * @var string $adresse
 * @var bool $modulAktiv
 * @var int $fallbackMinuten
 * @var bool $online
 * @var array{wartend: array<string, array{offen: int, laeuft: int}>, fertig: int, fehler: list<array{id: int, typ: string, fehler: string, am: \DateTimeImmutable}>} $uebersicht
 * @var int $defekte
 * @var int $nachholbar
 * @var bool $ocrVerfuegbar
 * @var ?\App\Domain\Job $bestandJob
 * @var ?array{import: int, von: int, bis: int, nach: int, uebergeben: int, ausgelassen: int} $bestandZustand
 * @var ?array{gesamt: int, mitText: int, inErkennung: int, beimWorker: int, offen: int} $bestandStand
 * @var bool $bestandLaeuft
 * @var list<\App\Domain\ArchiveImportRecord> $importLaeufe
 */

use App\Domain\WorkerStatus;
use App\Service\Worker\WorkerRouting;

$typName = static fn(string $typ): string => match ($typ) {
    'ocr' => 'Texterkennung (OCR)',
    'image_cleanup' => 'Bildaufbereitung',
    'render_pages' => 'PDF-Seiten rendern',
    'ai_extract' => 'KI-Auslesen',
    default => $typ,
};

?>
<section class="schmal">
    <h2>Worker</h2>

    <p class="gedaempft">
        Der Worker ist ein optionales Zusatzmodul, z. B. auf einem Raspberry Pi: Er holt sich Aufgaben
        wie Texterkennung und KI-Auslesen selbst ab – er braucht keinen offenen Port und keine
        öffentliche Adresse. Er bekommt nie den Tresor-Schlüssel. Ohne Worker läuft alles wie bisher
        im Browser.
    </p>

    <h3>Modul</h3>
    <form method="post" action="/admin/worker/einstellungen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="feld-ankreuz">
            <input type="checkbox" name="aktiv" value="1"<?= $modulAktiv ? ' checked' : '' ?>>
            Worker-Modul aktiv – passende Aufgaben gehen an den Worker, solange er online ist
        </label>
        <label for="worker-fallback" class="feld-kurz">Rückfall auf den Browser nach (Minuten ohne Meldung)
            <input type="number" id="worker-fallback" name="fallback_minuten" value="<?= e((string) $fallbackMinuten) ?>"
                   min="<?= e((string) WorkerRouting::FALLBACK_MINUTEN_MIN) ?>" max="<?= e((string) WorkerRouting::FALLBACK_MINUTEN_MAX) ?>"
                   step="1" required aria-describedby="worker-fallback-hilfe">
        </label>
        <p class="feld-hilfe" id="worker-fallback-hilfe">
            Meldet sich der Worker so lange nicht, laufen seine offenen Aufgaben wieder im Browser; die
            Texterkennung entfällt dann und lässt sich später nachholen. Nach 24 Stunden ohne Meldung
            geht eine Alarm-Mail an die Verwaltung.
        </p>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
        </p>
    </form>

    <?php if ($code !== null): ?>
        <div class="karte">
            <h4>Kopplungscode</h4>
            <p><strong><code><?= e($code) ?></code></strong></p>
            <p class="gedaempft">
                Gültig bis <?= e($codeBis?->format('H:i') ?? '–') ?> Uhr, nur einmal verwendbar.
                Der Code wird nur jetzt angezeigt. Auf dem Worker ausführen:
            </p>
            <p><code>docker compose run --rm worker pair <?= e($adresse) ?> <?= e($code) ?></code></p>
            <p class="gedaempft">Danach diese Seite neu laden und den Fingerabdruck vergleichen.</p>
        </div>
    <?php endif; ?>

    <?php if ($worker === null): ?>
        <p>Status: <span class="marke">nicht gekoppelt</span></p>

        <?php if ($code === null && $offenerCodeBis !== null): ?>
            <p class="hinweis hinweis-info">
                Ein Kopplungscode ist bis <?= e($offenerCodeBis->format('H:i')) ?> Uhr gültig.
                Ein neuer Code ersetzt ihn.
            </p>
        <?php endif; ?>

        <form method="post" action="/admin/worker/koppeln">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Kopplungscode erzeugen</button>
            </p>
        </form>
    <?php else: ?>
        <p>
            Status:
            <?php if ($worker->status === WorkerStatus::Aktiv): ?>
                <span class="marke marke-ok"><?= e($worker->status->label()) ?></span>
            <?php else: ?>
                <span class="marke marke-warnung"><?= e($worker->status->label()) ?></span>
            <?php endif; ?>
            <?php if (!$protokollOk): ?>
                <span class="marke marke-fehler">Worker-Update nötig</span>
            <?php endif; ?>
            <?php if ($online): ?>
                <span class="marke marke-ok">online</span>
            <?php else: ?>
                <span class="marke marke-warnung">offline</span>
            <?php endif; ?>
        </p>

        <div class="karte">
            <h4><?= e($worker->name) ?></h4>
            <p>
                Fingerabdruck:<br>
                <strong><code><?= e($fingerabdruck) ?></code></strong>
            </p>
            <p class="gedaempft">
                Gekoppelt am <?= e($worker->gekoppeltAm->format('d.m.Y H:i')) ?> ·
                letzte Meldung <?= e($worker->letzterHeartbeat?->format('d.m.Y H:i') ?? '–') ?><br>
                Version <?= e($worker->appVersion === '' ? '–' : $worker->appVersion) ?>
                (Protokoll <?= e((string) $worker->protokollVersion) ?>) ·
                Fähigkeiten: <?= e($worker->faehigkeiten === [] ? '–' : implode(', ', array_map(static fn(\App\Domain\WorkerFaehigkeit $f): string => $f->label(), $worker->faehigkeiten))) ?>
            </p>
        </div>

        <?php if ($worker->status === WorkerStatus::Gekoppelt): ?>
            <h3>Fingerabdruck bestätigen</h3>
            <p class="gedaempft">
                Der Worker zeigt nach dem Koppeln seinen Fingerabdruck an. Erst wenn beide Anzeigen
                Zeichen für Zeichen übereinstimmen, bestätigen – sonst entkoppeln. Bis dahin holt der
                Worker keine Aufgaben ab.
            </p>
            <form method="post" action="/admin/worker/<?= e((string) $worker->id) ?>/bestaetigen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label class="feld-ankreuz">
                    <input type="checkbox" name="verglichen" value="1" required>
                    Der Fingerabdruck stimmt mit der Anzeige auf dem Worker überein.
                </label>
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer">Bestätigen</button>
                </p>
            </form>
        <?php endif; ?>

        <h3>Warteschlange</h3>
        <?php if ($uebersicht['wartend'] === []): ?>
            <p class="gedaempft">Keine Aufgaben für den Worker.</p>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <thead>
                        <tr>
                            <th scope="col">Aufgabe</th>
                            <th scope="col" class="zahl">wartend</th>
                            <th scope="col" class="zahl">in Arbeit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uebersicht['wartend'] as $typ => $anzahl): ?>
                            <tr>
                                <td data-label="Aufgabe"><?= e($typName($typ)) ?></td>
                                <td data-label="wartend" class="zahl"><?= e((string) $anzahl['offen']) ?></td>
                                <td data-label="in Arbeit" class="zahl"><?= e((string) $anzahl['laeuft']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="gedaempft">
            In den letzten 24 Stunden erledigt: <?= e((string) $uebersicht['fertig']) ?>
            <?php if ($defekte > 0): ?>
                · <span class="marke marke-fehler"><?= e((string) $defekte) ?> unbrauchbare Ergebnisse verworfen</span>
            <?php endif; ?>
        </p>

        <h3>Letzte Fehler</h3>
        <?php if ($uebersicht['fehler'] === []): ?>
            <p class="gedaempft">Keine.</p>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <thead>
                        <tr>
                            <th scope="col">Zeit</th>
                            <th scope="col">Aufgabe</th>
                            <th scope="col">Fehler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uebersicht['fehler'] as $fehler): ?>
                            <tr>
                                <td data-label="Zeit"><?= e($fehler['am']->format('d.m.Y H:i')) ?></td>
                                <td data-label="Aufgabe"><?= e($typName($fehler['typ'])) ?></td>
                                <td data-label="Fehler"><code><?= e($fehler['fehler']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3>Entkoppeln</h3>
        <p class="gedaempft">
            Sperrt den Zugang des Workers sofort; Aufgaben, die er gerade hält, werden freigegeben.
            Für einen neuen oder neu eingerichteten Worker danach neu koppeln.
        </p>
        <form method="post" action="/admin/worker/<?= e((string) $worker->id) ?>/entkoppeln">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-gefahr">Worker entkoppeln</button>
            </p>
        </form>
    <?php endif; ?>

    <h3>OCR nachholen</h3>
    <p class="gedaempft">
        Belege, deren Texterkennung ohne Worker ausgefallen ist: <?= e((string) $nachholbar) ?>
        (ohne Belege aus dem Archiv-Import – dafür gibt es den nächsten Abschnitt).
        Je Klick gehen bis zu <?= e((string) \App\Admin\WorkerController::NACHHOLEN_MAX) ?> davon an den Worker.
    </p>
    <?php if ($nachholbar > 0 && $ocrVerfuegbar): ?>
        <form method="post" action="/admin/worker/ocr-nachholen">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf">OCR nachholen</button>
            </p>
        </form>
    <?php elseif ($nachholbar > 0): ?>
        <p class="hinweis hinweis-info">Möglich, sobald das Modul aktiv ist und der Worker mit Texterkennung online ist.</p>
    <?php endif; ?>

    <h3>Archiv nachträglich durchsuchbar machen</h3>
    <p class="gedaempft">
        Belege aus dem Archiv-Import gehen nicht einzeln an den Worker, sondern hier gesammelt und
        gedrosselt: Es warten höchstens <?= e((string) \App\Service\Worker\BestandsOcr::NACHSCHUB) ?> Texterkennungen
        gleichzeitig beim Worker, neue Belege kommen immer zuerst dran. Festgeschriebene Belege bleiben
        unverändert – es kommt nur die Textebene für die Suche dazu, das Original bleibt erhalten.
    </p>
    <?php if ($bestandJob !== null && $bestandZustand !== null && $bestandStand !== null): ?>
        <?php
        $bestandLauf = $bestandZustand['import'] === 0 ? 'alle Archiv-Belege' : 'Import-Lauf ' . $bestandZustand['import'];
        $bestandErgebnis = match ($bestandJob->status) {
            \App\Domain\JobStatus::Fertig => 'abgeschlossen',
            \App\Domain\JobStatus::Uebersprungen => 'abgebrochen',
            \App\Domain\JobStatus::Fehler => 'mit Fehler beendet',
            default => '',
        };
        ?>
        <div class="karte">
            <h4>
                <?= e($bestandLauf) ?>
                <?php if ($bestandLaeuft): ?>
                    <span class="marke marke-ok">läuft</span>
                <?php else: ?>
                    <span class="marke"><?= e($bestandErgebnis) ?></span>
                <?php endif; ?>
            </h4>
            <progress max="<?= e((string) max(1, $bestandStand['gesamt'])) ?>" value="<?= e((string) $bestandStand['mitText']) ?>"
                      aria-label="Belege mit Textebene"></progress>
            <p>
                <?= e((string) $bestandStand['mitText']) ?> von <?= e((string) $bestandStand['gesamt']) ?> Belegen haben eine Textebene ·
                beim Worker: <?= e((string) $bestandStand['beimWorker']) ?> ·
                noch ohne: <?= e((string) $bestandStand['offen']) ?>
            </p>
            <p class="gedaempft">
                In diesem Lauf übergeben: <?= e((string) $bestandZustand['uebergeben']) ?>
                <?php if ($bestandZustand['ausgelassen'] > 0): ?>
                    · ausgelassen (nicht übergebbar, wird erneut versucht): <?= e((string) $bestandZustand['ausgelassen']) ?>
                <?php endif; ?>
            </p>
            <?php if ($bestandLaeuft): ?>
                <?php if (!$ocrVerfuegbar): ?>
                    <p class="hinweis hinweis-info">Wartet, bis der Worker mit Texterkennung wieder online ist – danach geht es von selbst weiter.</p>
                <?php endif; ?>
                <p class="feld-hilfe">
                    Läuft im Hintergrund, solange jemand mit Verwaltungsrechten angemeldet ist; nach einer Pause
                    geht es an derselben Stelle weiter. Die Zahlen aktualisieren sich beim Neuladen der Seite.
                </p>
                <form method="post" action="/admin/worker/bestand-ocr/abbrechen">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <p class="knopfreihe">
                        <button type="submit" class="knopf knopf-still">Abbrechen</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (!$bestandLaeuft): ?>
        <?php if ($importLaeufe === []): ?>
            <p class="gedaempft">Es gibt noch keinen Archiv-Import.</p>
        <?php elseif (!$ocrVerfuegbar): ?>
            <p class="hinweis hinweis-info">Möglich, sobald das Modul aktiv ist und der Worker mit Texterkennung online ist.</p>
        <?php else: ?>
            <form method="post" action="/admin/worker/bestand-ocr" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="worker-bestand-import">Welche Belege?
                    <select id="worker-bestand-import" name="import">
                        <?php foreach ($importLaeufe as $lauf): ?>
                            <option value="<?= e((string) $lauf->id) ?>">
                                Import vom <?= e(($lauf->startedAt ?? $lauf->createdAt)->format('d.m.Y')) ?> (<?= e((string) $lauf->stats->belege) ?> Belege)
                            </option>
                        <?php endforeach; ?>
                        <option value="alle">alle Archiv-Belege</option>
                    </select>
                </label>
                <p class="knopfreihe">
                    <button type="submit" class="knopf">Nachverarbeitung starten</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
