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
 * @var bool $versionVeraltet the worker runs an older release than this installation (issue #248)
 * @var ?string $serverVersion
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
 * @var ?array{gesamt: int, mitText: int, inErkennung: int, beimWorker: int, fehlgeschlagen: int, offen: int} $bestandStand
 * @var bool $bestandLaeuft
 * @var list<\App\Domain\ArchiveImportRecord> $importLaeufe
 * @var ?\App\Service\Worker\MailEingangEinstellungen $mailEingang
 * @var ?array{zeit: \DateTimeImmutable, fehler: ?string, eingereicht: int, abgelehnt: int} $mailStatus
 * @var list<\App\Domain\ImapSicherheit> $sicherheiten
 */

use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Service\Worker\WorkerRouting;

$typName = static fn(string $typ): string => match ($typ) {
    'ocr' => 'Texterkennung (OCR)',
    'render_pages' => 'PDF-Seiten rendern',
    'ai_extract' => 'KI-Auslesen',
    default => $typ,
};

// Error codes of the worker's mailbox run (worker/src/Mail/MailEingang.php).
$mailFehler = static fn(string $code): string => match ($code) {
    'imap_verbindung' => 'Server nicht erreichbar',
    'imap_tls' => 'TLS fehlgeschlagen (Zertifikat oder Servername prüfen)',
    'imap_starttls_fehlt' => 'Server bietet kein STARTTLS an',
    'imap_anmeldung' => 'Anmeldung abgelehnt (Benutzer/Passwort prüfen)',
    'imap_ordner' => 'Ordner nicht gefunden',
    'imap_protokoll' => 'Unerwartete Antwort des Servers',
    'webhoster' => 'Einreichen beim Webhoster fehlgeschlagen',
    'mail_limit' => 'Mengengrenze je Stunde erreicht – der Rest folgt später',
    default => $code,
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
            <?php elseif ($versionVeraltet ?? false): ?>
                <span class="marke marke-warnung">Worker-Version veraltet</span>
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
            <?php if ($protokollOk && ($versionVeraltet ?? false)): ?>
                <p class="hinweis hinweis-warnung">
                    Der Worker läuft mit Version <?= e($worker->appVersion) ?>, diese Installation mit
                    <?= e((string) ($serverVersion ?? '')) ?>. Er arbeitet weiter; bitte auf dem Worker-Rechner
                    <code>docker compose pull &amp;&amp; docker compose up -d</code> ausführen.
                </p>
            <?php endif; ?>
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
                <?php if ($bestandStand['fehlgeschlagen'] > 0): ?>
                    · Texterkennung gescheitert (in diesem Lauf nicht erneut): <?= e((string) $bestandStand['fehlgeschlagen']) ?>
                <?php endif; ?>
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
    <?php if ($mailEingang !== null): ?>
        <h3 id="mail-eingang">E-Mail-Eingang</h3>
        <p class="gedaempft">
            Der Worker ruft ein eigenes Postfach des Vereins per IMAP ab und legt jede Mail mit Anhängen
            (PDF, Foto als JPEG/PNG, E-Rechnung als XML) als Beleg in den Posteingang – verschlüsselt
            wie eine Einreichung über das Formular. Absender und Betreff landen nur verschlüsselt im
            Beleg. Verarbeitete Mails werden markiert und, falls ein Zielordner angegeben ist, dorthin
            verschoben – nie gelöscht. Am besten ein Postfach nur für Belege verwenden.
        </p>
        <?php if ($mailStatus !== null): ?>
            <p>
                Letzter Abruf: <?= e($mailStatus['zeit']->format('d.m.Y H:i')) ?>
                <?php if ($mailStatus['fehler'] === null): ?>
                    <span class="marke marke-ok">in Ordnung</span>
                <?php else: ?>
                    <span class="marke marke-fehler"><?= e($mailFehler($mailStatus['fehler'])) ?></span>
                <?php endif; ?>
                <br><span class="gedaempft">
                    Seit dem Start des Workers eingereicht: <?= e((string) $mailStatus['eingereicht']) ?>,
                    nicht übernommen (markiert): <?= e((string) $mailStatus['abgelehnt']) ?>
                </span>
            </p>
        <?php endif; ?>
        <?php if ($mailEingang->aktiv && !$modulAktiv): ?>
            <p class="hinweis hinweis-info">Der Abruf beginnt, sobald das Worker-Modul eingeschaltet ist.</p>
        <?php elseif ($mailEingang->aktiv && $worker !== null && !in_array(WorkerFaehigkeit::Mail, $worker->faehigkeiten, true)): ?>
            <p class="hinweis hinweis-info">Der Worker meldet keinen E-Mail-Eingang – bitte den Worker aktualisieren.</p>
        <?php endif; ?>
        <form method="post" action="/admin/worker/mail-eingang" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="feld-ankreuz">
                <input type="checkbox" name="aktiv" value="1"<?= $mailEingang->aktiv ? ' checked' : '' ?>>
                E-Mail-Eingang aktiv
            </label>

            <label for="mail-eingang-host">IMAP-Server
                <input type="text" id="mail-eingang-host" name="host" value="<?= e($mailEingang->host) ?>"
                       autocomplete="off" spellcheck="false" placeholder="z. B. imap.example.org">
            </label>

            <fieldset>
                <legend>Verschlüsselung</legend>
                <?php foreach ($sicherheiten as $sicherheit): ?>
                    <label class="feld-ankreuz">
                        <input type="radio" name="sicherheit" value="<?= e($sicherheit->value) ?>"
                               <?= $sicherheit === $mailEingang->sicherheit ? 'checked' : '' ?>>
                        <?= e($sicherheit->label()) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="feld-hilfe">Ohne Verschlüsselung verbindet sich der Worker nicht; das Zertifikat wird immer geprüft.</p>

            <label for="mail-eingang-port" class="feld-kurz">Port
                <input type="text" id="mail-eingang-port" name="port" inputmode="numeric"
                       value="<?= e((string) $mailEingang->port) ?>">
            </label>

            <label for="mail-eingang-benutzer">Benutzername
                <input type="text" id="mail-eingang-benutzer" name="benutzer" value="<?= e($mailEingang->benutzer) ?>"
                       autocomplete="off" spellcheck="false">
            </label>

            <label for="mail-eingang-passwort">Passwort
                <input type="password" id="mail-eingang-passwort" name="passwort" autocomplete="new-password"
                       aria-describedby="mail-eingang-passwort-hilfe">
            </label>
            <p class="feld-hilfe" id="mail-eingang-passwort-hilfe">
                Leer lassen, um das gespeicherte Passwort zu behalten. Aktuell
                <?= $mailEingang->passwort !== '' ? 'gesetzt' : 'nicht gesetzt' ?>.
                Es geht nur verschlüsselt an den Worker.
            </p>

            <label for="mail-eingang-ordner">Abgerufener Ordner
                <input type="text" id="mail-eingang-ordner" name="ordner" value="<?= e($mailEingang->ordner) ?>"
                       autocomplete="off" spellcheck="false">
            </label>

            <label for="mail-eingang-ziel">Zielordner für verarbeitete Mails
                <input type="text" id="mail-eingang-ziel" name="ziel_ordner" value="<?= e($mailEingang->zielOrdner) ?>"
                       autocomplete="off" spellcheck="false" placeholder="optional, z. B. Belege/Erledigt"
                       aria-describedby="mail-eingang-ziel-hilfe">
            </label>
            <p class="feld-hilfe" id="mail-eingang-ziel-hilfe">
                Optional. Der Ordner muss im Postfach schon existieren. Ohne Zielordner bleiben die Mails
                markiert im abgerufenen Ordner.
            </p>

            <label for="mail-eingang-absender">Erlaubte Absender
                <textarea id="mail-eingang-absender" name="absender" rows="4" spellcheck="false"
                          aria-describedby="mail-eingang-absender-hilfe"><?= e($mailEingang->absender->alsText()) ?></textarea>
            </label>
            <p class="feld-hilfe" id="mail-eingang-absender-hilfe">
                Optional, eine Adresse oder <code>@domain.de</code> je Zeile. Leer = alle Absender. Mails
                anderer Absender bleiben markiert im Postfach liegen.
            </p>

            <label for="mail-eingang-intervall" class="feld-kurz">Abruf alle (Minuten)
                <input type="number" id="mail-eingang-intervall" name="abruf_minuten"
                       value="<?= e((string) $mailEingang->abrufMinuten) ?>"
                       min="<?= e((string) \App\Service\Worker\MailEingangEinstellungen::ABRUF_MINUTEN_MIN) ?>"
                       max="<?= e((string) \App\Service\Worker\MailEingangEinstellungen::ABRUF_MINUTEN_MAX) ?>" step="1">
            </label>
            <p class="feld-hilfe">
                Es gelten die Grenzen der Einreichung (Seiten, Dateigröße, Gesamtgröße). Mails darüber,
                ohne passenden Anhang oder als Spam markiert werden nicht übernommen und bleiben markiert
                im Postfach. Der Webhoster nimmt je Stunde höchstens
                <?= e((string) $mailGrenze['mails']) ?> Mails und <?= e((string) $mailGrenze['mb']) ?> MB an;
                was darüber liegt, wartet im Postfach auf den nächsten Abruf.
            </p>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">E-Mail-Eingang speichern</button>
            </p>
        </form>
    <?php endif; ?>
</section>
