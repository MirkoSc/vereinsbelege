<?php
/**
 * Setting a second factor up, or changing it (issue #17/M3-4,
 * docs/spec/01-sicherheit.md section 3) - one view, several steps, the same
 * pattern app/views/install.php uses for its own multi-step forms. Only
 * components from /admin/designsystem, no page-specific CSS, no JavaScript
 * except the print button (public/js/sicherheit.js, same pattern as
 * public/js/install.js).
 *
 * @var string $csrf
 * @var string $schritt 'waehlen' | 'totp' | 'email' | 'codes'
 * @var string|null $fehler
 * @var string|null $hinweis
 * @var string|null $totpSecretBase32
 * @var string|null $totpQrSvg server-generated SVG markup (App\Support\QrCode) -
 *      echoed unescaped further down, like $content in app/views/layout.php
 * @var list<string>|null $backupCodes
 */
?>
<section class="schmal">
    <h2>Zwei-Faktor-Anmeldung einrichten</h2>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php elseif ($hinweis !== null): ?>
        <p class="hinweis hinweis-info" role="status"><?= e($hinweis) ?></p>
    <?php endif; ?>

    <?php if ($schritt === 'waehlen'): ?>
        <p>Wählen Sie einen zweiten Faktor für die Anmeldung.</p>

        <div class="karte">
            <h3>Authenticator-App (TOTP)</h3>
            <p>Empfohlen. Funktioniert auch ohne Netzverbindung, z. B. mit Google Authenticator oder Aegis.</p>
            <form method="post" action="/app/sicherheit/einrichten/totp/starten">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf knopf-primaer">Mit Authenticator-App einrichten</button>
            </form>
        </div>

        <div class="karte">
            <h3>Code per E-Mail</h3>
            <p>Ein sechsstelliger Code wird bei jeder Anmeldung an Ihre E-Mail-Adresse gesendet.</p>
            <form method="post" action="/app/sicherheit/einrichten/email/starten">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="knopf">Mit E-Mail-Code einrichten</button>
            </form>
        </div>

    <?php elseif ($schritt === 'totp'): ?>
        <p>Scannen Sie diesen Code mit Ihrer Authenticator-App und bestätigen Sie mit dem angezeigten Code.</p>

        <?php if ($totpQrSvg !== null): ?>
            <div class="karte">
                <?= $totpQrSvg ?>
            </div>
        <?php endif; ?>

        <p class="feld-hilfe">
            Geht das Scannen nicht? Geheimnis von Hand eingeben:
            <code><?= e($totpSecretBase32 ?? '') ?></code>
        </p>

        <form method="post" action="/app/sicherheit/einrichten/totp/bestaetigen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label for="einrichten-totp-code">Code aus der Authenticator-App
                <input type="text" id="einrichten-totp-code" name="code" inputmode="numeric"
                       autocomplete="one-time-code" autocapitalize="none" spellcheck="false" required autofocus>
            </label>
            <p><button type="submit" class="knopf knopf-primaer">Bestätigen</button></p>
        </form>

    <?php elseif ($schritt === 'email'): ?>
        <p>Ein Code wurde an Ihre hinterlegte E-Mail-Adresse gesendet.</p>

        <form method="post" action="/app/sicherheit/einrichten/email/bestaetigen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label for="einrichten-email-code">Code aus der E-Mail
                <input type="text" id="einrichten-email-code" name="code" inputmode="numeric"
                       autocomplete="one-time-code" autocapitalize="none" spellcheck="false" required autofocus>
            </label>
            <p><button type="submit" class="knopf knopf-primaer">Bestätigen</button></p>
        </form>

        <form method="post" action="/app/sicherheit/einrichten/email/starten">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="feld-hilfe">Keinen Code erhalten?
                <button type="submit" class="knopf knopf-still">Code erneut senden</button>
            </p>
        </form>

    <?php elseif ($schritt === 'codes' && $backupCodes !== null): ?>
        <p class="hinweis hinweis-ok">Der zweite Faktor ist eingerichtet.</p>
        <p class="hinweis hinweis-warnung nicht-drucken">
            Diese zehn Backup-Codes werden nur jetzt angezeigt. Jeder funktioniert nur einmal und ersetzt den
            zweiten Faktor, falls Authenticator-App oder E-Mail-Postfach nicht erreichbar sind.
        </p>
        <div class="schluessel-gruppen" aria-label="Zehn Backup-Codes">
            <?php foreach ($backupCodes as $code): ?>
                <span class="schluessel-gruppe"><?= e($code) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="nur-druck">Vereinsbelege – Backup-Codes für die Zwei-Faktor-Anmeldung.</p>
        <p class="nicht-drucken">
            <button type="button" id="sicherheit-codes-drucken" class="knopf">Zum Aufbewahren drucken</button>
        </p>
        <p class="nicht-drucken"><a class="knopf knopf-primaer" href="/app/sicherheit">Fertig</a></p>
    <?php endif; ?>
</section>
