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
