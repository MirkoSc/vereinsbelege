<?php

/**
 * Admin page for the public submission's spam defence (issue #25/M4-3,
 * docs/spec/01-sicherheit.md section 5): rate/size/page limits and the
 * pause switch.
 *
 * Only components from /admin/designsystem, no page-specific CSS/JS - two
 * plain forms are enough here, the same shape as admin/mail.php.
 *
 * @var string $csrf
 * @var \App\Service\Submission\EinreichungsEinstellungen $einstellungen
 * @var ?\App\Service\Submission\UploadBudgetStand $budget
 * @var ?string $fehler
 */
?>
<section class="schmal">
    <h2>Einreichung</h2>

    <p class="gedaempft">
        <code>/einreichen</code> braucht keine Anmeldung und keinen Code
        (E-14) - der Schutz gegen Missbrauch läuft unsichtbar: unsichtbare
        Rechenaufgabe im Hintergrund, ein für Menschen verstecktes Feld und
        eine Mindest-Ausfülldauer fangen Bots ab, ohne echte Einreicher
        aufzuhalten. Taucht trotzdem Spam auf, lässt sich die Einreichung
        hier vorübergehend pausieren.
    </p>

    <p class="knopfreihe">
        Status:
        <?php if ($einstellungen->pausiert): ?>
            <span class="marke marke-warnung">Pausiert</span>
        <?php else: ?>
            <span class="marke marke-ok">Aktiv</span>
        <?php endif; ?>
    </p>

    <?php if ($budget !== null): ?>
        <?php $mb = \App\Service\SystemCheck\SystemCheck::formatBytes(...); ?>
        <p>
            Heute hochgeladen: <?= e($mb($budget->verbrauchtHeute)) ?> von <?= e($mb($budget->budgetHeute)) ?>
            <?php if ($budget->freiBytes !== null): ?>
                · freier Speicher: <?= e($mb($budget->freiBytes)) ?>
            <?php endif; ?>
        </p>
        <?php if ($budget->speicherKnapp()): ?>
            <p class="hinweis hinweis-warnung" role="status">
                Der freie Speicher liegt unter der Schwelle – öffentliche Uploads
                sind pausiert, bis wieder Platz ist. Angemeldete Funktionen laufen weiter.
            </p>
        <?php elseif ($budget->budgetErreicht()): ?>
            <p class="hinweis hinweis-warnung" role="status">
                Das Tagesbudget für öffentliche Uploads ist erreicht – bis Mitternacht
                lehnt <code>/einreichen</code> weitere Dateien ab.
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <p class="knopfreihe">
        <?php if ($einstellungen->pausiert): ?>
            <form method="post" action="/admin/einreichung/fortsetzen">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf knopf-primaer">Einreichung fortsetzen</button>
            </form>
        <?php else: ?>
            <form method="post" action="/admin/einreichung/pausieren">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf">Einreichung pausieren</button>
            </form>
        <?php endif; ?>
    </p>

    <h3>Grenzen</h3>
    <form method="post" action="/admin/einreichung" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="einreichung-limit-ip" class="feld-kurz">Einreichungen je IP und Stunde
            <input type="text" id="einreichung-limit-ip" name="limit_ip_stunde" inputmode="numeric"
                   value="<?= e((string) $einstellungen->limitProIpStunde) ?>">
        </label>

        <label for="einreichung-limit-gesamt" class="feld-kurz">Einreichungen insgesamt je Stunde
            <input type="text" id="einreichung-limit-gesamt" name="limit_gesamt_stunde" inputmode="numeric"
                   value="<?= e((string) $einstellungen->limitGesamtStunde) ?>">
        </label>

        <label for="einreichung-max-seiten" class="feld-kurz">Höchstens Seiten je Einreichung
            <input type="text" id="einreichung-max-seiten" name="max_seiten" inputmode="numeric"
                   value="<?= e((string) $einstellungen->maxSeiten) ?>">
        </label>

        <label for="einreichung-max-datei" class="feld-kurz">Höchstens MB je Datei
            <input type="text" id="einreichung-max-datei" name="max_datei_mb" inputmode="numeric"
                   value="<?= e((string) $einstellungen->maxDateiMb) ?>">
        </label>

        <label for="einreichung-max-gesamt" class="feld-kurz">Höchstens MB je Einreichung
            <input type="text" id="einreichung-max-gesamt" name="max_einreichung_mb" inputmode="numeric"
                   value="<?= e((string) $einstellungen->maxEinreichungMb) ?>">
        </label>

        <label for="einreichung-budget-ip" class="feld-kurz">Höchstens MB Uploads je Anschluss und Stunde
            <input type="text" id="einreichung-budget-ip" name="budget_ip_mb_stunde" inputmode="numeric"
                   value="<?= e((string) $einstellungen->budgetIpMbStunde) ?>">
        </label>

        <label for="einreichung-offen-ip" class="feld-kurz">Höchstens MB offene (nicht abgeschlossene) Uploads je Anschluss
            <input type="text" id="einreichung-offen-ip" name="offen_mb_ip" inputmode="numeric"
                   value="<?= e((string) $einstellungen->offenMbIp) ?>">
        </label>

        <label for="einreichung-budget-gesamt" class="feld-kurz">Höchstens MB Uploads insgesamt je Tag
            <input type="text" id="einreichung-budget-gesamt" name="budget_gesamt_mb_tag" inputmode="numeric"
                   value="<?= e((string) $einstellungen->budgetGesamtMbTag) ?>">
        </label>

        <label for="einreichung-min-frei" class="feld-kurz">Uploads pausieren unter MB freiem Speicher
            <input type="text" id="einreichung-min-frei" name="min_frei_mb" inputmode="numeric"
                   value="<?= e((string) $einstellungen->minFreiMb) ?>">
        </label>

        <p class="feld-hilfe">
            Je Anschluss und Stunde zählt jede angekündigte Datei, auch wenn sie
            nie abgeschlossen wird; offene Uploads werden nach Abschluss,
            Abbruch oder spätestens nach 24 Stunden wieder frei. Das Tagesbudget
            zählt nur tatsächlich gespeicherte Dateien. Ein Anschluss ist eine IP-Adresse,
            bei IPv6 der ganze /64-Bereich. Keine Grenze liegt unter der
            größten erlaubten Datei. Kann der Hoster den freien Speicher nicht
            nennen, gilt allein das Tagesbudget.
        </p>

        <p class="feld-hilfe">
            Für Uploads gilt zusätzlich ein eigenes Zeitfenster, das sich aus
            den beiden Einreichungs-Limits und der Seitenobergrenze ergibt -
            eine Einreichung mit mehreren Seiten braucht mehr Uploads als
            eine mit einer.
        </p>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Einstellungen speichern</button>
        </p>
    </form>
</section>
