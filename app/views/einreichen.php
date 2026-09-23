<?php

/**
 * Public submission (docs/spec/03-erfassung-und-ki.md section 1, issue
 * #24/M4-2): no login, no code, mobile-first, one screen. The heavy lifting
 * (camera capture, upload, validation, the submit itself) lives in
 * public/js/einreichen.js and public/js/upload.js - only components from
 * /admin/designsystem here, no inline script (CSP, CLAUDE.md section 4).
 *
 * "Foto aufnehmen" is the system camera via <input capture> for now
 * (M4-2's "ohne Scanner"); the live camera with crop overlay arrives with
 * the scanner (M5).
 *
 * Spam defence (issue #25/M4-3, docs/spec/01-sicherheit.md section 5): the
 * proof-of-work challenge ($pow) is solved invisibly in the background by
 * public/js/einreichen.js as soon as the page loads; the honeypot field
 * below is for bots only (App\Service\Submission\Spamschutz).
 *
 * @var string $token
 * @var array{salt: string, challenge: string, max: int} $pow
 * @var array<int, string> $kostenstellen id => name, active ones
 * @var bool $hatTresor
 * @var bool $pausiert
 * @var int $maxSeiten
 * @var int $maxDateiMb
 */
?>
<section class="schmal" id="einreichen" data-token="<?= e($token) ?>" data-max-seiten="<?= e($maxSeiten) ?>"
         data-max-datei-mb="<?= e($maxDateiMb) ?>" data-pow-salt="<?= e($pow['salt']) ?>"
         data-pow-challenge="<?= e($pow['challenge']) ?>" data-pow-max="<?= e($pow['max']) ?>">
    <h2>Beleg einreichen</h2>

    <?php if ($pausiert): ?>
        <p class="hinweis hinweis-warnung">
            Die Einreichung ist vorübergehend nicht möglich. Bitte später erneut versuchen.
        </p>
    <?php elseif (!$hatTresor): ?>
        <p class="hinweis hinweis-warnung">
            Die Einreichung ist noch nicht eingerichtet. Bitte später erneut versuchen.
        </p>
    <?php else: ?>
        <noscript>
            <p class="hinweis hinweis-fehler">Für die Einreichung wird JavaScript benötigt.</p>
        </noscript>

        <p>
            Foto aufnehmen, ein Bild oder eine PDF-Datei wählen – mehrere Seiten sind
            möglich (bis zu <?= e($maxSeiten) ?>, je höchstens <?= e($maxDateiMb) ?> MB).
        </p>

        <p class="knopfreihe">
            <label class="knopf">
                Foto aufnehmen
                <input type="file" id="einreichen-foto" class="visuell-versteckt" accept="image/*" capture="environment">
            </label>
            <label class="knopf">
                Bild wählen
                <input type="file" id="einreichen-bild" class="visuell-versteckt" accept="image/*" multiple>
            </label>
            <label class="knopf">
                PDF wählen
                <input type="file" id="einreichen-pdf" class="visuell-versteckt" accept="application/pdf" multiple>
            </label>
        </p>

        <p class="feld-fehler" id="einreichen-upload-fehler" hidden></p>

        <ol class="einreichen-seiten" id="einreichen-seiten"></ol>

        <form class="formular" id="einreichen-formular" novalidate>
            <!-- Honeypot (issue #25/M4-3): unsichtbar für Menschen (CSS,
                 aria-hidden, tabindex="-1"), für ein Formular-Skript, das
                 jedes Feld befüllt, ein Verräter (App\Service\Submission\
                 Spamschutz). -->
            <div class="einreichen-koeder" aria-hidden="true">
                <label for="einreichen-webseite">Webseite
                    <input type="text" id="einreichen-webseite" name="webseite" tabindex="-1" autocomplete="off">
                </label>
            </div>

            <label for="einreichen-name">Name <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="einreichen-name" name="name" autocomplete="name" required>
            </label>
            <p class="feld-fehler" data-fehler-fuer="name" hidden></p>

            <label for="einreichen-email">E-Mail-Adresse (optional, für Rückfragen)
                <input type="email" id="einreichen-email" name="email" autocomplete="email">
            </label>
            <p class="feld-fehler" data-fehler-fuer="email" hidden></p>

            <fieldset>
                <legend>Kostenerstattung</legend>
                <label class="feld-ankreuz">
                    <input type="radio" name="erstattung" value="ueberweisung" required>
                    Bitte an mich überweisen
                </label>
                <label class="feld-ankreuz">
                    <input type="radio" name="erstattung" value="bar">
                    Bar erhalten
                </label>
                <label class="feld-ankreuz">
                    <input type="radio" name="erstattung" value="keine">
                    Keine Erstattung – bereits vom Verein bezahlt
                </label>
            </fieldset>
            <p class="feld-fehler" data-fehler-fuer="erstattung" hidden></p>

            <div id="einreichen-ueberweisung" hidden>
                <label for="einreichen-iban">IBAN
                    <input type="text" id="einreichen-iban" name="iban" autocomplete="off" spellcheck="false">
                </label>
                <p class="feld-fehler" data-fehler-fuer="iban" hidden></p>

                <label for="einreichen-kontoinhaber">Kontoinhaber
                    <input type="text" id="einreichen-kontoinhaber" name="kontoinhaber" autocomplete="name">
                </label>
                <p class="feld-fehler" data-fehler-fuer="kontoinhaber" hidden></p>
            </div>

            <label for="einreichen-freitext">Worum geht es? <span class="pflicht" aria-hidden="true">*</span>
                <textarea id="einreichen-freitext" name="freitext" rows="2" required
                          placeholder="z. B. Getränke Sommerfest E-Jugend"></textarea>
            </label>
            <p class="feld-fehler" data-fehler-fuer="freitext" hidden></p>

            <?php if ($kostenstellen !== []): ?>
                <label for="einreichen-kostenstelle">Mannschaft/Bereich (optional)
                    <select id="einreichen-kostenstelle" name="kostenstelle">
                        <option value="">– keine Angabe –</option>
                        <?php foreach ($kostenstellen as $id => $name): ?>
                            <option value="<?= e($id) ?>"><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label class="feld-ankreuz">
                <input type="checkbox" name="datenschutz" required>
                Meine Angaben werden zur Bearbeitung dieser Einreichung gespeichert
                (Datenschutz-Hinweis).
            </label>
            <p class="feld-fehler" data-fehler-fuer="datenschutz" hidden></p>

            <p class="feld-fehler" data-fehler-fuer="seiten" hidden></p>
            <p class="hinweis hinweis-fehler" id="einreichen-allgemein" hidden></p>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer" id="einreichen-absenden">Einreichen</button>
                <span class="htmx-indicator lade-anzeige" id="einreichen-lade" hidden>Wird gesendet …</span>
            </p>
        </form>

        <div id="einreichen-erfolg" hidden>
            <p class="hinweis hinweis-ok">
                Vielen Dank! Ihre Referenznummer: <strong id="einreichen-referenz"></strong>
            </p>
            <p class="feld-hilfe">Bitte notieren Sie sich diese Nummer für Rückfragen.</p>
            <p class="knopfreihe">
                <button type="button" class="knopf" id="einreichen-neu">Weiteren Beleg einreichen</button>
            </p>
        </div>
    <?php endif; ?>
</section>
