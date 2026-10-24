<?php

/**
 * Admin page "Worker" (docs/spec/07-worker.md section 4, issue #51/M7b-1):
 * pairing code, fingerprint confirmation, unpairing. Status, queue and the
 * module switch follow with issue #53.
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
 */

use App\Domain\WorkerStatus;

?>
<section class="schmal">
    <h2>Worker</h2>

    <p class="gedaempft">
        Der Worker ist ein optionales Zusatzmodul, z. B. auf einem Raspberry Pi: Er holt sich Aufgaben
        wie Texterkennung und KI-Auslesen selbst ab – er braucht keinen offenen Port und keine
        öffentliche Adresse. Er bekommt nie den Tresor-Schlüssel. Ohne Worker läuft alles wie bisher
        im Browser.
    </p>

    <?php if ($code !== null): ?>
        <div class="karte">
            <h3>Kopplungscode</h3>
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
        </p>

        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption>Gekoppelter Worker</caption>
                <tbody>
                    <tr><th scope="row">Name</th><td><?= e($worker->name) ?></td></tr>
                    <tr><th scope="row">Fingerabdruck</th><td><code><?= e($fingerabdruck) ?></code></td></tr>
                    <tr><th scope="row">Gekoppelt am</th><td><?= e($worker->gekoppeltAm->format('d.m.Y H:i')) ?></td></tr>
                    <tr><th scope="row">Letzte Meldung</th><td><?= e($worker->letzterHeartbeat?->format('d.m.Y H:i') ?? '–') ?></td></tr>
                    <tr><th scope="row">Version</th><td><?= e($worker->appVersion === '' ? '–' : $worker->appVersion) ?> (Protokoll <?= e((string) $worker->protokollVersion) ?>)</td></tr>
                    <tr>
                        <th scope="row">Fähigkeiten</th>
                        <td><?= e($worker->faehigkeiten === [] ? '–' : implode(', ', array_map(static fn(\App\Domain\WorkerFaehigkeit $f): string => $f->label(), $worker->faehigkeiten))) ?></td>
                    </tr>
                </tbody>
            </table>
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
</section>
