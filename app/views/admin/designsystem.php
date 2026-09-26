<?php

/**
 * Pattern page of the design system: every component in one place, so the
 * acceptance checks of this milestone - readable at 360 px, light and dark,
 * form components, flash messages - can be done on one URL, and so a new
 * page copies markup that exists instead of inventing its own.
 *
 * Deliberately a normal page of the admin area: it uses the same layout, the
 * same stylesheet and the same CSP as everything else. A separate static
 * demo file would drift within a milestone.
 */
?>
<section>
    <h2>Designsystem</h2>
    <p>
        Bausteine für alle Seiten. Alles hier ist handgeschriebenes CSS aus
        <code>public/css/app.css</code> – kein Build-Schritt, kein Framework (E-07).
        Hell und Dunkel folgen der Einstellung des Betriebssystems.
    </p>

    <h3>Meldungen</h3>
    <p class="hinweis hinweis-ok">Gespeichert. So sieht eine Bestätigung aus.</p>
    <p class="hinweis hinweis-info">Hinweis ohne Handlungsbedarf.</p>
    <p class="hinweis hinweis-warnung">Warnung: etwas braucht Aufmerksamkeit.</p>
    <p class="hinweis hinweis-fehler">Fehler: die Aktion ist fehlgeschlagen.</p>
    <p>
        Flash-Meldungen nach einer Weiterleitung nutzen dieselbe Darstellung; das
        Layout rendert sie oberhalb des Inhalts (<code>App\View\Flash</code>).
    </p>

    <h3>Knöpfe</h3>
    <p class="knopfreihe">
        <button type="button" class="knopf knopf-primaer">Speichern</button>
        <button type="button" class="knopf">Abbrechen</button>
        <button type="button" class="knopf knopf-gefahr">Löschen</button>
        <button type="button" class="knopf knopf-still">Nebenaktion</button>
        <button type="button" class="knopf" disabled>Nicht verfügbar</button>
    </p>

    <h3>Marken</h3>
    <p class="knopfreihe">
        <span class="marke">Neu</span>
        <span class="marke marke-ok">Festgeschrieben</span>
        <span class="marke marke-warnung">Wartet auf Prüfung</span>
        <span class="marke marke-fehler">KI fehlgeschlagen</span>
    </p>

    <h3>Formular</h3>
    <?php /* GET auf die eigene Seite: das Muster soll nichts schreiben. */ ?>
    <form method="get" action="/admin/designsystem" class="formular">
        <label for="ds-text">Bezeichnung <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="ds-text" name="ds_text" required
                   aria-describedby="ds-text-hilfe" placeholder="z. B. Sportbedarf">
        </label>
        <p class="feld-hilfe" id="ds-text-hilfe">Hilfetext unter dem Feld.</p>

        <label for="ds-fehler">Feld mit Fehler
            <input type="text" id="ds-fehler" name="ds_fehler" value="12,ab"
                   aria-invalid="true" aria-describedby="ds-fehler-meldung">
        </label>
        <p class="feld-fehler" id="ds-fehler-meldung">Bitte einen Betrag wie 12,00 eingeben.</p>

        <label for="ds-betrag" class="feld-kurz">Betrag (€)
            <input type="text" id="ds-betrag" name="ds_betrag" inputmode="decimal" value="42,00">
        </label>

        <label for="ds-auswahl">Auswahl
            <select id="ds-auswahl" name="ds_auswahl">
                <option>Erste Möglichkeit</option>
                <option>Zweite Möglichkeit</option>
            </select>
        </label>

        <label for="ds-text-mehrzeilig">Mehrzeilig
            <textarea id="ds-text-mehrzeilig" name="ds_notiz" rows="3">Freitext</textarea>
        </label>

        <fieldset>
            <legend>Mehrfachauswahl</legend>
            <label class="feld-ankreuz">
                <input type="checkbox" name="ds_haken" checked> Erledigt
            </label>
            <label class="feld-ankreuz">
                <input type="radio" name="ds_wahl" checked> Erstattung
            </label>
            <label class="feld-ankreuz">
                <input type="radio" name="ds_wahl"> Barzahlung
            </label>
        </fieldset>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Absenden</button>
            <button type="reset" class="knopf">Zurücksetzen</button>
        </p>
    </form>

    <h3>Karte</h3>
    <div class="karte">
        <h4>Beleg 2026-0042</h4>
        <p class="gedaempft">Karten fassen zusammengehörige Angaben – etwa einen Beleg in einer Liste.</p>
    </div>

    <h3>Tabelle</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption>Beispiel – schmale Fenster scrollen die Tabelle waagerecht.</caption>
            <thead>
                <tr>
                    <th scope="col">Datum</th>
                    <th scope="col">Lieferant</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="zahl">Betrag</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>01.03.2026</td>
                    <td>Beispiel GmbH</td>
                    <td><span class="marke marke-ok">Geprüft</span></td>
                    <td class="zahl">42,00 €</td>
                </tr>
                <tr>
                    <td>14.03.2026</td>
                    <td>Muster e. K.</td>
                    <td><span class="marke marke-warnung">Offen</span></td>
                    <td class="zahl">1.250,00 €</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3>Leerzustand</h3>
    <div class="leer">Keine Einträge vorhanden.</div>

    <h3>htmx</h3>
    <p>
        htmx liegt vendored unter <code>public/js/vendor/</code> und wird über
        <code>&lt;meta name="htmx-config"&gt;</code> konfiguriert – ohne Inline-Skript,
        ohne <code>hx-on</code>, ohne externen Host. Der Ladeanzeiger ist
        handgeschrieben, weil <code>includeIndicatorStyles</code> aus ist:
    </p>
    <p><span class="htmx-indicator lade-anzeige">Wird geladen …</span> (nur während einer htmx-Anfrage sichtbar)</p>

    <h3>Eck-Editor (Scanner)</h3>
    <p>
        Ziehbare Ecken, Lupe, Farbmodus-Umschalter (<code>public/js/scanner/eckeditor.js</code>,
        docs/spec/03-erfassung-und-ki.md §2). Die Kantenerkennung (M5-2) läuft
        auf dem gewählten Bild; findet sie kein Viereck, setzt der Editor
        einen eingerückten Standardrahmen. Das gewählte Bild bleibt im
        Browser – nichts wird hochgeladen.
    </p>
    <label for="eck-editor-datei">Testbild
        <input type="file" id="eck-editor-datei" accept="image/*">
    </label>
    <div id="eck-editor-demo" hidden>
        <?php require __DIR__ . '/../partials/eck-editor.php'; ?>
    </div>
    <p class="knopfreihe">
        <button type="button" class="knopf knopf-primaer" id="eck-editor-entzerren" disabled>Entzerren</button>
    </p>
    <div id="eck-editor-ergebnis"></div>
</section>
