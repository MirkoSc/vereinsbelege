<?php

/**
 * Internal capture (docs/spec/03-erfassung-und-ki.md section 1, issue
 * #28/M4-6): the capture component of /einreichen for logged-in accounts
 * with `document.submit_internal` - several receipts in one pass, one card
 * per receipt, reimbursement and description optional. Every chosen file
 * starts a receipt of its own; further pages are added inside a card.
 *
 * public/js/erfassen.js drives the page (upload through /api/upload with the
 * capture id in X-Erfassung, the final JSON submit); it clones the card from
 * the <template> below. No inline script (CSP, CLAUDE.md section 4), only
 * components from /admin/designsystem.
 *
 * @var string $csrf
 * @var string $erfassung capture id of this page load
 * @var array<int, string> $kostenstellen id => name, active ones
 * @var bool $hatTresor
 * @var int $maxBelege
 * @var int $maxSeiten
 * @var int $maxDateiMb
 * @var bool $posteingang whether the account may open the inbox
 */
?>
<section class="schmal" id="erfassen" data-csrf="<?= e($csrf) ?>" data-erfassung="<?= e($erfassung) ?>"
         data-max-belege="<?= e($maxBelege) ?>" data-max-seiten="<?= e($maxSeiten) ?>"
         data-max-datei-mb="<?= e($maxDateiMb) ?>">
    <h2>Belege erfassen</h2>

    <?php if (!$hatTresor): ?>
        <p class="hinweis hinweis-warnung">
            Die Belegerfassung ist noch nicht eingerichtet (kein Tresor).
        </p>
    <?php else: ?>
        <noscript>
            <p class="hinweis hinweis-fehler">Für die Erfassung wird JavaScript benötigt.</p>
        </noscript>

        <p>
            Jede gewählte Datei wird ein eigener Beleg (bis zu <?= e($maxBelege) ?> auf einmal,
            je höchstens <?= e($maxDateiMb) ?> MB). Mehrseitige Belege: in der Karte
            „Seite hinzufügen“.
        </p>

        <p class="knopfreihe" id="erfassen-quellen">
            <label class="knopf">
                Foto aufnehmen
                <input type="file" id="erfassen-foto" class="visuell-versteckt" accept="image/*" capture="environment">
            </label>
            <label class="knopf">
                Bilder wählen
                <input type="file" id="erfassen-bild" class="visuell-versteckt" accept="image/*" multiple>
            </label>
            <label class="knopf">
                PDFs wählen
                <input type="file" id="erfassen-pdf" class="visuell-versteckt" accept="application/pdf" multiple>
            </label>
        </p>

        <p class="feld-fehler" id="erfassen-upload-fehler" hidden></p>

        <form class="formular" id="erfassen-formular" novalidate>
            <ol class="erfassen-belege" id="erfassen-belege"></ol>
            <div class="leer" id="erfassen-leer">Noch keine Belege gewählt.</div>

            <p class="feld-fehler" data-fehler-fuer="belege" hidden></p>
            <p class="hinweis hinweis-fehler" id="erfassen-allgemein" hidden></p>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer" id="erfassen-absenden">Alle erfassen</button>
                <span class="htmx-indicator lade-anzeige" id="erfassen-lade" hidden>Wird gesendet …</span>
            </p>
        </form>

        <div id="erfassen-erfolg" hidden>
            <p class="hinweis hinweis-ok" id="erfassen-erfolg-text"></p>
            <ul id="erfassen-referenzen"></ul>
            <p class="knopfreihe">
                <?php if ($posteingang): ?>
                    <a class="knopf knopf-primaer" href="/app/posteingang">Zum Posteingang</a>
                <?php endif; ?>
                <button type="button" class="knopf" id="erfassen-neu">Weitere Belege erfassen</button>
            </p>
        </div>

        <template id="erfassen-vorlage">
            <li class="karte erfassen-beleg">
                <div class="erfassen-beleg-kopf">
                    <h3 class="erfassen-beleg-titel">Beleg</h3>
                    <button type="button" class="knopf knopf-still" data-aktion="beleg-entfernen">Beleg entfernen</button>
                </div>

                <ol class="einreichen-seiten" data-rolle="seiten"></ol>
                <p class="knopfreihe">
                    <label class="knopf knopf-still">
                        Seite hinzufügen
                        <input type="file" class="visuell-versteckt" accept="image/*,application/pdf" multiple data-rolle="seite-hinzufuegen">
                    </label>
                </p>
                <p class="feld-fehler" data-fehler-fuer="seiten" hidden></p>

                <label>Worum geht es? (optional)
                    <textarea rows="2" data-feld="freitext" placeholder="z. B. Getränke Sommerfest E-Jugend"></textarea>
                </label>
                <p class="feld-fehler" data-fehler-fuer="freitext" hidden></p>

                <?php if ($kostenstellen !== []): ?>
                    <label>Mannschaft/Bereich (optional)
                        <select data-feld="kostenstelle">
                            <option value="">– keine Angabe –</option>
                            <?php foreach ($kostenstellen as $id => $name): ?>
                                <option value="<?= e($id) ?>"><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="feld-fehler" data-fehler-fuer="kostenstelle" hidden></p>
                <?php endif; ?>

                <details class="erfassen-erstattung">
                    <summary>Kostenerstattung (optional)</summary>
                    <fieldset>
                        <legend class="visuell-versteckt">Kostenerstattung</legend>
                        <label class="feld-ankreuz">
                            <input type="radio" value="" data-feld="erstattung" checked>
                            Keine Angabe
                        </label>
                        <label class="feld-ankreuz">
                            <input type="radio" value="ueberweisung" data-feld="erstattung">
                            Überweisung
                        </label>
                        <label class="feld-ankreuz">
                            <input type="radio" value="bar" data-feld="erstattung">
                            Bar erhalten
                        </label>
                        <label class="feld-ankreuz">
                            <input type="radio" value="keine" data-feld="erstattung">
                            Keine Erstattung – bereits vom Verein bezahlt
                        </label>
                    </fieldset>
                    <p class="feld-fehler" data-fehler-fuer="erstattung" hidden></p>

                    <div data-rolle="ueberweisung" hidden>
                        <label>IBAN
                            <input type="text" data-feld="iban" autocomplete="off" spellcheck="false">
                        </label>
                        <p class="feld-fehler" data-fehler-fuer="iban" hidden></p>

                        <label>Kontoinhaber
                            <input type="text" data-feld="kontoinhaber" autocomplete="off">
                        </label>
                        <p class="feld-fehler" data-fehler-fuer="kontoinhaber" hidden></p>
                    </div>
                </details>
            </li>
        </template>
    <?php endif; ?>
</section>
