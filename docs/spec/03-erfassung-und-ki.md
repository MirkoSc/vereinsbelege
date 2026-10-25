# 03 – Erfassung, Aufbereitung, Texterkennung, KI

## 1. Öffentliche Einreichung `/einreichen`

Mobile-first, eine Seite, kein Login. Ablauf:

1. **Beleg erfassen** – drei Wege auf einem Bildschirm:
   - „Foto aufnehmen": Live-Kamera (`getUserMedia`, Rückkamera) mit
     Rahmen-Overlay; Fallback `<input type="file" accept="image/*"
     capture="environment">` (iOS/ältere Browser).
   - „Bild wählen" (JPEG/PNG; HEIC/HEIF, seit #147: siehe „HEIC" unten).
   - „PDF oder E-Rechnung wählen" (PDF, ZUGFeRD/Factur-X-PDF oder
     XRechnung als XML-Datei, seit M7-4 – Abschnitt 3).
   - Mehrere Seiten pro Beleg: „Weitere Seite hinzufügen", Reihenfolge per
     Drag & Drop, Seite löschen.
2. **Zuschneiden & Aufbereiten** (je Bild, siehe Abschnitt 2) – automatisch
   vorgeschlagen, Ecken per Finger korrigierbar, Vorschau Schwarzweiß/Farbe.
3. **Angaben**:
   - Name (Pflicht), E-Mail (optional, für Bestätigung/Rückfragen)
   - **Kostenerstattung**: „Bitte an mich überweisen" (IBAN Pflicht, mit
     Prüfziffer-Validierung mod 97, Kontoinhaber) / „Bar erhalten" /
     „Keine Erstattung – bereits vom Verein bezahlt"
   - **Worum geht es?** Freitext (Pflicht, z. B. „Getränke Sommerfest
     E-Jugend")
   - Mannschaft/Bereich (optional, Auswahl aus `cost_center`)
   - Datenschutz-Hinweis + Checkbox
4. **Absenden** → Proof-of-Work läuft unsichtbar im Hintergrund (kein Code,
   kein Captcha) → Upload in Chunks
   (Abschnitt 4) → Referenznummer anzeigen („R-2026-0147"), optional Mail.

Die Seite ist als PWA installierbar (Homescreen-Icon „Beleg einreichen"),
aber ohne Offline-Warteschlange (Backlog).

Angemeldete Nutzer mit `document.submit_internal` haben dieselbe
Erfassungskomponente unter `/app/belege/neu` (ohne Erstattungsangaben-Pflicht,
mit Mehrfach-Upload für mehrere Belege auf einmal) – Stand M4-6 unten.

**Stand M4-2** (issue #24): `App\PublicPages\EinreichungController`
(`GET`/`POST /einreichen`) und `App\Api\EinreichungUploadController`
(`POST /einreichen/upload/...`, gleicher Vertrag wie Abschnitt 4). Ohne
Session ist das Credential ein zustandsloses, signiertes Formular-Token
(`App\Service\Submission\FormToken`): `GET /einreichen` stellt es aus, jede
spätere Anfrage trägt es im Header `X-CSRF-Token` zurück und wird gegen den
Server-Schlüssel neu geprüft – nichts wird nachgeschlagen. Der Hash des
Token-Nonce (`form_hash`) bindet hochgeladene Blobs an genau diesen
Seitenaufruf (`submission_upload`, App\Repository\SubmissionUploadRepository):
das Absenden verweigert eine Seiten-ID, die unter einem anderen Token
hochgeladen wurde. Nicht abgesendete Uploads räumt
`App\Service\Cron\SubmissionUploadCleanupTask` nach 24 h ab (wie
Abschnitt 4). Foto/Bild/PDF kommen über `<input capture>` bzw.
`<input type=file multiple>` (public/js/einreichen.js), Reihenfolge per
Pointer-Drag **und** ↑/↓-Knöpfen; Kamera-Live-Vorschau mit Rahmen-Overlay kam
mit M5-4 (unten). Referenznummer `R-<Jahr>-<laufende Nummer>`,
Bestätigungsmail nur mit der Referenz
(`Mailer::reiheEinreichungsbestaetigungEin()`), Prüfung serverseitig mit
Feld-Fehlern je Angabe.

**Stand M4-3** (issue #25, Abschnitt 5 von 01): Seiten-/Größenlimit
(`App\Service\Submission\EinreichungsEinstellungen`, einstellbar unter
`/admin/einreichung`), Rate-Limit, Proof-of-Work und Honeypot
(`App\Service\Submission\Spamschutz`) laufen jetzt vor
`SubmissionService::einreichen()` bzw. vor jedem Seiten-Upload.

**Stand #242** (Security-Review N-04, 01 §5, E-64): Ein Byte-Budget
begrenzt, was `/einreichen` auf den Server bringen kann – offene Uploads je
IP (IPv6: /64), angekündigte Bytes je IP und Stunde, gespeicherte Bytes
insgesamt je Kalendertag, dazu eine Schwelle für freien Speicher in
`shared/var`, unter der öffentliche Uploads pausieren
(`App\Service\Submission\UploadBudget`, einstellbar unter
`/admin/einreichung`, Details 01 §5). Angemeldete Erfassung und
Worker-Mail-Eingang laufen nicht über das Budget.

**Stand M4-5** (issue #27): Posteingang unter `/app/posteingang`
(`App\App\InboxController`, Fachlogik `App\Service\Inbox\Posteingang`).
Ansehen mit `inbox.view` im Zugriffsbereich des Kontos (Kostenstelle über
`document.cost_center_id`, Zeitraum über `created_at`, beides in SQL), Entscheiden
(Annehmen, Ablehnen mit Grund, Wiedervorlage – 02 „Statusmodell“) und
Kostenstelle ändern mit `document.edit`. Liste mit Filtern Ansicht
(Offen/Wiedervorlage/Angenommen/Abgelehnt/Alle), Mannschaft/Bereich,
Eingangszeitraum und Suche in Name/Beschreibung (nach dem Entschlüsseln in PHP,
nur mit entsperrtem Tresor). Die Mannschaftswahl der Einreichung setzt
`document.cost_center_id`. Seiten werden über
`/app/posteingang/{id}/datei/{blob}` gestreamt entschlüsselt ausgeliefert
(`StreamResponse`, `Cache-Control: no-store`, Dateiname nur aus der
Referenz, keine Temp-Datei); Bilder erscheinen direkt, PDFs öffnen im
Browser-Viewer in einem neuen Tab – die CSP (`object-src 'none'`,
`frame-ancestors 'none'`) verbietet Einbetten, Seitenbilder kommen mit
pdf.js (M4-8). Nach jeder Einreichung reiht
`App\Service\Mail\EinreichungBenachrichtigung` eine Mail an alle aktiven
Konten mit `document.edit` ein – nur Referenz und Link, keine Fachdaten.

**Stand M4-6** (issue #28): Interne Erfassung unter `/app/belege/neu`
(`App\App\ErfassungController`, Fachlogik `App\Service\Submission\InterneErfassung`),
Recht `document.submit_internal` auf `GET` und `POST` (JSON, Session-CSRF im
Header `X-CSRF-Token`). **Jede gewählte Datei wird ein eigener Beleg** (eine
Karte je Beleg, `public/js/erfassen.js`); weitere Seiten, Reihenfolge und
Löschen je Karte wie bei `/einreichen`. Je Beleg optional: „Worum geht es?“
(bewusst **optional**, anders als öffentlich), Mannschaft/Bereich und
Kostenerstattung – wird „Überweisung“ gewählt, sind IBAN (mod 97) und
Kontoinhaber Pflicht. Kein Name-/E-Mail-Feld, keine Datenschutz-Checkbox, kein
Spamschutz: angemeldet. Höchstens `InterneErfassung::MAX_BELEGE` = 50 Belege
je Durchgang und `MAX_SEITEN` = 50 Seiten je Beleg; Dateigröße wie
`/api/upload` (32 MiB). Ein Durchgang ist **eine Transaktion** – bei einem
Feldfehler (Schlüssel `"<Beleg-Index>.<Feld>"`, 422) wird nichts geschrieben.

Je Beleg entsteht wie bei der öffentlichen Einreichung eine `submission`-Zeile
(Referenz `R-<Jahr>-<Nr.>` aus derselben Nummernfolge,
`App\Service\Submission\Referenzvergabe`; Payload-Name = Anzeigename des
Kontos, ohne `email`, `erstattung` nur wenn angegeben) und ein `document` mit
`source = intern`, `created_by` = Konto, Status `eingegangen`, Job
`pdf_erzeugen` – der Posteingang zeigt interne und öffentliche Belege gleich.
Die Mail „Neue Einreichung“ geht an alle aktiven Konten mit `document.edit`
**außer dem erfassenden Konto**; Audit `beleg.erfasst` je Beleg mit dem Konto
als Akteur. Ein Tresor-Entsperren ist nicht nötig (nur Versiegeln an den
Public Key).

*Bindung der Seiten:* `GET /app/belege/neu` gibt eine zufällige Erfassungs-ID
(32 Hex) aus; die Seite schickt sie bei jedem Upload im Header `X-Erfassung`.
`/api/upload/{id}/finish` vermerkt den Blob dann in `submission_upload` unter
`sha256("intern|<user_id>|<Erfassungs-ID>")` – an Konto **und** Seitenaufruf
gebunden. Beanspruchen darf ein Beleg nur dort vermerkte Blobs, jeden genau
einmal; nach dem Commit werden genau diese Zeilen gelöscht, wieder entfernte
Seiten räumt `SubmissionUploadCleanupTask` nach 24 h mit ihrem Blob ab.
`submission.form_hash` je Beleg = `sha256("intern-beleg|" . obiger Hash . "|" .
erste Blob-ID)` – ein wiederholter Request liefert dieselben Referenzen.

**Stand M5-4** (issue #34): Scanner in beiden Erfassungen.
„Foto aufnehmen“ öffnet die **Live-Kamera** (`public/js/scanner/kamera.js`,
`app/views/partials/kamera-dialog.php`: `getUserMedia` mit Rückkamera als
`ideal`, A4-Rahmen als Ausrichthilfe, das ganze Kamerabild wird das Original),
wo der Browser sie erlaubt (sicherer Kontext). Sonst, oder nach
verweigertem Zugriff über „Kamera-App verwenden“ im Dialog, bleibt es beim
`<input capture>`. Der Videostrom läuft über `srcObject`, die CSP bleibt
unverändert. Jede Bildseite (JPEG/PNG, nie PDF) lädt **Original und
aufbereitete Fassung** hoch (zwei Uploads über dieselben Routen). Die
Nutzlast trägt neben `blobs` die parallele Liste `aufbereitet: [id|null, …]`,
intern je Beleg:
`App\Service\Submission\Seitenliste::aufbereitungLesen()`.
- Das Feld ist optional, alte Clients funktionieren weiter.
- Die Länge muss zu den Seiten passen.
- Keine ID darf doppelt vorkommen oder zugleich ein Original sein.
- Jede ID muss wie die Originale unter demselben Token bzw. Seitenaufruf
  hochgeladen sein.
- Das Größenlimit der öffentlichen Einreichung zählt beide Fassungen.
- Beim Absenden werden **nur die beanspruchten** `submission_upload`-Zeilen
  gelöscht (öffentlich wie intern). Durch „Zuschneiden“ abgelöste Fassungen
  und entfernte Seiten räumt `SubmissionUploadCleanupTask` mit ihrem Blob
  ab, statt sie zu verwaisen.

Bedienung:
- `/einreichen` öffnet den Eck-Editor nach jedem Bild. Mehrere Bilder kommen
  nacheinander dran, „Abbrechen“ verwirft die Seite.
- `/app/belege/neu` bereitet **automatisch** auf: erkannte Ecken, sonst das
  ganze Bild, dazu Schwarzweiß. Ein Durchgang kann 50 Belege haben.
- Beide Seiten bieten je Bildseite „Zuschneiden“ (Editor auf dem im Browser
  gehaltenen Original; die neue Fassung ersetzt die alte) und intern je
  Karte „Seite fotografieren“.
- Der Posteingang zeigt die aufbereitete Fassung mit „Original öffnen“
  (`Posteingang::seiten()`, `Document::blobIds()` enthält beide).

### Rechtstexte (M13-3, issue #80, E-46 vorläufig)

- **Pflegbar unter `/admin/rechtstexte`** (Recht `admin.settings`, Audit
  `einstellung.rechtstexte` nur mit den Namen der geänderten Texte):
  Impressum, Datenschutzerklärung und der Datenschutz-Hinweis über der
  Checkbox auf `/einreichen`. Gespeichert als Klartext-Settings
  `rechtstext_impressum`, `rechtstext_datenschutz`,
  `rechtstext_einreichung_hinweis` (öffentliche Texte, keine Fachdaten;
  höchstens 60 000 Byte). `App\Service\Rechtstexte\Rechtstexte`.
- **Vorlagen** unter `app/data/rechtstexte/*.md` mit Platzhaltern in
  eckigen Klammern (`[Name des Vereins]`). Ohne gespeicherten Text gilt die
  Vorlage; ein leeres Feld oder die unveränderte Vorlage wird nicht
  gespeichert (spätere Vorlagen-Updates kommen an). Solange die Vorlage
  gilt oder Platzhalter offen sind, zeigt die öffentliche Seite „noch eine
  Vorlage“, die Admin-Seite „Vorlage“/„Platzhalter offen“ – und immer den
  Hinweis „keine Rechtsberatung“.
- **Inhalt der Vorlagen:** Impressum nach § 5 DDG/§ 18 MStV;
  Datenschutzerklärung mit KI-Abschnitt (Anbieter sieht Belege im
  Klartext, AV-Vertrag nach Art. 28 DSGVO für OpenAI/Anthropic, eigener
  llama.cpp-Server als datensparsame Option – 01 §1, E-08), Aufbewahrung
  und Löschung (Fristen als Platzhalter, mit Steuerberater zu klären –
  E-10), Rechte, Cookies. Die Admin-Seite listet die genutzten KI-Profile
  (aktiv und mit Key – `KiAnbieter::nutzbar()`; Name, Host) als Erinnerung.
- **Format:** `App\View\EinfachesMarkdown` – Absätze, Zeilenumbruch,
  `##`/`###`, `- ` Listen, `**fett**`, Links nur auf `https://`, `http://`,
  `mailto:`, `tel:` und eigene Pfade. Jedes Zeichen der Eingabe wird
  escaped, es gibt kein Roh-HTML; abgelehnte Links bleiben als Text sichtbar.
- **Öffentlich ohne Session** (`Area::Oeffentlich`): `GET /impressum`,
  `GET /datenschutz`; jede Seite verlinkt beide im Fuß. Auf `/einreichen`
  steht der Hinweis über der Checkbox (mit Marke „Vorlage“, solange
  unfertig), darunter Links auf beide Seiten. Auf öffentlichen Seiten
  öffnen diese Links und die Links im Hinweis einen neuen Tab, damit auf
  `/einreichen` erfasste Seiten nicht verloren gehen.

### HEIC (Issue #147, vorläufig)

- **Server:** `MagicBytes` erkennt HEIC/HEIF am `ftyp`-Kopf (Hauptmarke
  `heic`, `heix`, `hevc`, `hevx`, `heim`, `heis`, `hevm`, `hevs`; bei `mif1`/
  `msf1` nur mit HEVC-Marke unter den kompatiblen Marken – so bleiben AVIF
  und MP4 abgelehnt). Endung und MIME-Typ des Browsers zählen nicht.
- **E-10 bleibt:** Das HEIC ist das **Original**, das im Browser erzeugte
  JPEG die **aufbereitete Fassung**. Der Server liest nie HEIC (GD kann es
  nicht), deshalb nimmt der Client ein HEIC **nur zusammen mit dem JPEG**
  an. Absicherung auf dem Server: `pdf_erzeugen` scheitert bei einem
  HEIC-Original ohne aufbereitete Fassung mit verständlichem Hinweis, die
  KI-Auslesung überspringt die Seite (die Blob-Metadaten der öffentlichen
  Einreichung sind versiegelt, eine Prüfung beim Absenden ist dort nicht
  möglich). Im **Archiv-Import** wird HEIC übersprungen („HEIC wird im
  Archiv-Import nicht unterstützt – bitte als JPEG/PDF beilegen“).
- **Browser:** Nur eigene Dekoder (`createImageBitmap`, Safari kann HEIC),
  keine Bibliothek: ein WASM-Dekoder bräuchte `'wasm-unsafe-eval'` in der
  CSP (CLAUDE.md §4). Die Fähigkeitsprüfung ist der Versuch selbst –
  schlägt er fehl (Chrome/Firefox), wird die Seite **vor dem Upload
  abgelehnt** mit der bisherigen Fehlermeldung (`HEIC_HINWEIS`, ergänzt um
  den iPhone-Tipp Einstellungen › Kamera › Formate › „Maximale
  Kompatibilität“). Das JPEG (Qualität 0,95) läuft durch dieselbe
  Scanner-Pipeline wie JPEG/PNG; scheitert der Scanner, ist das
  umgewandelte JPEG selbst die aufbereitete Fassung. „Zuschneiden"
  arbeitet auf dem JPEG.

## 2. Bildaufbereitung (im Browser)

Eigenes Modul `public/js/scanner/` mit reinen, testbaren Funktionen:

- **Kantenerkennung**: Graustufen → Weichzeichnen → Kanten → größtes
  Viereck. Umsetzung: *Entscheidung E-03* – Standard ist eine eigene
  schlanke Implementierung (Sobel + Hough/Konturapproximation auf
  verkleinertem Bild); OpenCV.js nur, wenn die eigene Erkennung in Tests
  mit echten Belegfotos zu schlecht ist (dann lazy geladen, vendored).
- **Manuelle Korrektur**: 4 ziehbare Eckpunkte (Pointer Events, Touch-Ziel
  ≥ 44 px, Lupe beim Ziehen).
- **Perspektiv-Entzerrung**: Homographie aus 4 Punkten, inverse Abbildung
  mit bilinearer Interpolation auf Canvas; Zielformat aus Seitenverhältnis
  (A4-Snap wenn nahe).
- **Schwarzweiß**: adaptive Schwelle (Sauvola/Bradley) über
  Integralbild; Option „Graustufen" und „Original (Farbe)" für Belege, bei
  denen Farbe relevant ist (Stempel, Fotos).
- Ausgabe: JPEG (Qualität ~0.85, max. 2480 px lange Kante ≈ 300 dpi A4)
  **plus** das unveränderte Original werden hochgeladen.
- Reine Funktionen (Homographie, Schwelle, Viereck-Auswahl) mit
  `node --test` und Referenzbildern unter `tests/fixtures/scanner/` testen.

Server-Fallback (Beleg kam ohne Aufbereitung, z. B. altes Gerät): GD
graustufen + globale Schwelle; kein Entzerren.

### Rechenkerne (M5-1)

Umgesetzt als reine Funktionen ohne DOM-/Canvas-Zugriff, damit sie sowohl im
Browser als auch unter `node --test` laufen (Dateien laden sich per
`module.exports`-Guard gegenseitig wie `public/js/erfassen.js` ↔
`public/js/einreichen.js`):

- `public/js/scanner/homographie.js` – `homographie(von, nach)` (DLT aus 4
  Punktpaaren, `null` bei entartetem Viereck), `abbilden(H, punkt)`,
  `invertieren(H)`.
- `public/js/scanner/entzerrung.js` – `zielgroesse(ecken, optionen)`
  (A4-Snap, Deckel bei `maxKante`), `entzerren(bild, ecken, ziel)` (inverse
  Abbildung, bilineare Interpolation).
- `public/js/scanner/schwelle.js` – `graustufen`, `integralbild`,
  `bradleySchwelle`, `schwarzweiss`. Bradley statt Sauvola: Sauvola
  bräuchte zusätzlich ein Integralbild der quadrierten Werte (doppelter
  Speicher, auf dem Handy relevant) für eine Varianz, die hier nicht nötig
  ist – Bradleys „dunkler als ein Anteil des lokalen Mittels" reicht für die
  Schattenverläufe eines fotografierten Belegs.

Ein Bild ist überall `{ width, height, data }` mit `data` als RGBA-
`Uint8ClampedArray` (kompatibel zu `CanvasRenderingContext2D.getImageData()`/
`ImageData`). Ecken sind immer `[oben-links, oben-rechts, unten-rechts,
unten-links]` (im Uhrzeigersinn). Referenzbilder unter
`tests/fixtures/scanner/` sind synthetisch erzeugt (PGM P5, siehe deren
`README.md`).

### Kantenerkennung (M5-2)

`public/js/scanner/kanten.js`, ebenfalls reine Funktionen. Ablauf in
`kantenErkennen(bild, optionen)`:

1. Graustufen (`schwelle.js`), Verkleinerung auf max. 500 px lange Kante
   (`verkleinern`) – die Erkennung selbst läuft auf dem kleinen Bild, das
   Ergebnis wird zurückskaliert.
2. Weichzeichnen (5×5-Gauß-Näherung) + Sobel-Kanten + Unterdrückung der
   Nicht-Maxima + ein aus dem Bild selbst abgeleiteter Schwellwert
   (Perzentil) → binäre Kantenkarte (`kantenKarte`). Ein 4-px-Rand wird
   ausgeblendet, weil das Weichzeichnen/Sobel dort durch die fehlenden
   Nachbarpixel eine künstliche Kante erzeugt.
3. Hough-Transformation (`houghLinien`) auf der Kantenkarte, getrennt nach
   eher waagerechten und eher senkrechten Linien.
4. `viereckAuswaehlen`: probiert Paare aus je zwei waagerechten und zwei
   senkrechten Linien, verwirft nicht konvexe oder zu kleine/zu weit aus dem
   Bild ragende Vierecke, bewertet die restlichen nach Fläche und
   Kantenunterstützung je Seite (`kanteUnterstuetzung`) – ein Punkt zählt
   nur, wenn dort eine Kante liegt **und** die Innenseite des Vierecks
   merklich heller ist als die Außenseite (ein Beleg ist Papier, heller als
   der Untergrund). Das verhindert, dass eine starke fremde Linie neben dem
   Beleg (z. B. eine Tischkante) oder eine gedruckte Linie *innerhalb* eines
   großen Blatts statt der echten Außenkante gewählt wird.
5. `eckenOrdnen`: ordnet 4 Punkte nach Winkel um ihren Schwerpunkt in
   `[oben-links, oben-rechts, unten-rechts, unten-links]` – bleibt auch bei
   starker Drehung ein gültiger, nicht überkreuzter Zyklus.

`null`, wenn kein Viereck sicher genug gefunden wird; der Eck-Editor (M5-3)
setzt dann einen eingerückten Standardrahmen statt falscher Ecken.

**Treffer-Regel** für die Messung: alle 4 gefundenen Ecken liegen höchstens
3 % der Bilddiagonale von der Soll-Ecke entfernt (Soll-Ecken von Auge bzw.
per Otsu-Schwelle + größter Kontur bestimmt).

**Stand** (Details und Zahlen: `docs/ENTSCHEIDUNGEN.md` E-03): gemessen auf
9 echten, anonymisierten Belegfotos (nicht im Repo – öffentliches Repo,
CLAUDE.md §1/E-13), 8 davon auswertbar: 3/8 (38 %) streng, 6/8 (75 %) bei
5 % Toleranz. E-03 ist damit **noch nicht entschieden** – 9 statt der
geforderten ≥ 20 Fotos sind keine belastbare Grundlage. Pflicht-Tests für
die reine Vierecks-Logik (u. a. eine helle Linie neben dem Beleg, eine
gedruckte Linie innerhalb, eine ~30°-Drehung) laufen mit synthetischen
Bildern unter `tests/js/scanner-kanten.test.js`.

Noch offen: ≥ 20 weitere anonymisierte Belegfotos als
committete Fixtures für eine reproduzierbare Trefferquoten-Messung in der
CI und der abschließende Entscheid zu E-03 (Folge-Issue).

### Eck-Editor (M5-3)

`public/js/scanner/eckeditor.js`, reine Funktionen plus eine DOM-Bindung
(die einzige Ausnahme unter den Dateien in `public/js/scanner/`, weil sie
genau die Bedienung treibt, die M5-4 in Einreichung und interne Erfassung
einbaut, statt einer separaten Seiten-Glue-Datei):

- `standardRahmen(breite, hoehe, einzug)` – eingerückter Standardrahmen,
  wenn `kantenErkennen()` (M5-2) `null` liefert; `ganzesBild(breite, hoehe)`
  für den „Ganzes Bild"-Knopf.
- `eckeVerschieben(ecken, index, punkt, breite, hoehe)` – klemmt den neuen
  Punkt auf die Bildgrenzen und übernimmt ihn nur, wenn das Viereck danach
  weiterhin konvex bleibt und eine Mindestfläche (1 % der Bildfläche) nicht
  unterschreitet; sonst bleibt das alte Viereck unverändert (Grund: siehe
  `homographie.js` – ein entartetes Viereck lässt sich nicht mehr
  entzerren).
- `tastaturSchritt(taste, gross)` – Pfeiltasten bewegen den fokussierten
  Griff (ein `<button>`, wie jedes andere Bedienelement per Tastatur
  erreichbar) um 1 Bildpixel, mit Shift um 10.
- `lupenPosition(...)`/`lupenAusschnitt(...)` – Lupe sitzt fest in einer
  Bühnen-Ecke (oben links, oder oben rechts, sobald der gezogene Punkt sie
  sonst verdecken würde) statt am Finger zu kleben, damit der Finger sie nie
  verdeckt.
- `FARBMODI = ['sw', 'grau', 'farbe']`, `farbmodusAnwenden(bild, modus)` –
  nutzt `schwarzweiss()`/`graustufenBild()` aus `schwelle.js`; Standard ist
  „Schwarzweiß" (Vorgabe für den üblichen Fall, Farbe bleibt wählbar für
  Belege mit Stempel/Foto).
- `ergebnisErzeugen(bild, ecken, modus, optionen)` – der eine Aufruf, den
  M5-4 für das hochzuladende Bild braucht: `zielgroesse()` + `entzerren()`
  aus `entzerrung.js`, dann der gewählte Farbmodus.
- `eckEditorBinden(wurzel, quelle, ecken)` – bindet Pointer Events (Touch
  und Maus identisch, `touch-action: none`), 4 Griffe ≥ 44 px
  (`--tippflaeche`), die Lupe und den Farbmodus-Umschalter an die Markup aus
  `app/views/partials/eck-editor.php`; gibt
  `{ ecken(), farbmodus(), zuruecksetzen() }` zurück.

Demo/Testseite: `/admin/designsystem` („Eck-Editor (Scanner)", Abschnitt
`admin.designsystem` – Bild bleibt im Browser, nichts wird hochgeladen),
über `public/js/designsystem.js`. Die Bühne nimmt höchstens 60 % der
Fensterhöhe ein (seit M5-4, damit „Übernehmen“ im Dialog erreichbar
bleibt).

### Einbau und Fallbacks (M5-4)

`public/js/scanner/scanner.js` verbindet Datei, Editor und Upload. Die
Skriptreihenfolge aller Scanner-Seiten steht in `App\View\ScannerSkripte`.
- `kannScannen(window)` verlangt `createImageBitmap`, `ImageData`,
  `canvas.toBlob` und `<dialog>`.
- `istScanbar()` gilt für JPEG/PNG.
- `startEcken()`: erkannte Ecken; ohne Treffer interaktiv den
  Standardrahmen, automatisch das ganze Bild (blind zuschneiden würde den
  Beleg anschneiden).
- `seiteVerarbeiten()`: erst aufbereiten, dann Original, dann Fassung
  hochladen; ein Abbruch lädt nichts hoch.
- `scannerOeffnen()`: ein `<dialog>` je Seite
  (`app/views/partials/scanner-dialog.php`). Er wird je Bild neu gebunden,
  weil die Farbmodus-Radios des Editors einen festen Namen tragen.
- `automatischAufbereiten()`.
- `createImageBitmap(…, {imageOrientation: 'from-image'})` richtet
  Handyfotos nach EXIF auf. Ausgabe JPEG 0.85, max. 2480 px (`zielgroesse`).

Kann der Browser nicht (Fähigkeit fehlt oder die Aufbereitung scheitert an
einem Bild, z. B. Speicher), geht **nur das Original** hoch
(`aufbereitet: null`), mit einem Hinweis auf der Seite. Den Rest erledigt
der **Server-Fallback** in `pdf_erzeugen` (Abschnitt 3):
`App\Service\Processing\SchwarzweissFallback` (rein, GD).
1. Pixel-Obergrenze vor dem Dekodieren.
2. PNG → `PngZuJpeg`.
3. EXIF-Drehung auf die Pixel anwenden (GD verwirft EXIF).
4. Verkleinern auf 2480 px lange Kante.
5. `IMG_FILTER_GRAYSCALE`.
6. **Globale Otsu-Schwelle** aus dem Histogramm. Nicht fest 128, damit ein
   dunkles Foto nicht schwarz wird.
7. JPEG 85.

Kein Entzerren, keine adaptive Schwelle – das bleibt dem Browser. Eine
A4-Seite braucht so in PHP unter 1 s.

### Live-Kantenerkennung im Kamerabild (issue #146)

Der Kameradialog (`public/js/scanner/kamera.js`,
`app/views/partials/kamera-dialog.php`) zeigt zusätzlich zum A4-Rahmen das
**live erkannte Viereck** über dem Video. Reine, mit `node --test`
geprüfte Funktionen (`tests/js/kamera.test.js`):

- **Drosselung:** `kantenErkennen()` (M5-2, unverändert) läuft auf einer
  auf `LIVE_BREITE` = 320 px Breite (lange Kante höchstens 500 px, die
  Arbeitsgröße von `kantenErkennen`) verkleinerten Kopie des Frames
  (`vorschauGroesse`, nie hochskaliert). Nächster Start frühestens nach
  `LIVE_INTERVALL_MS` = 400 ms, und danach mindestens so lange Pause, wie
  die Erkennung selbst brauchte (`naechsteErkennungIn`) – auf einem
  langsamen Handy bleibt der Hauptthread so höchstens zur Hälfte belegt.
  Keine Erkennung bei verstecktem Tab, ungeladenem Frame oder laufender
  Aufnahme. Ein Fehler in der Erkennung gilt als „nichts gefunden“.
- **Stabilität:** `liveFortschreiben` – „stabil“ sind
  `LIVE_STABIL_ANZAHL` = 3 aufeinanderfolgende Treffer, von denen sich
  **jedes Paar** um höchstens `LIVE_STABIL_TOLERANZ` = 2 % der
  Bilddiagonale unterscheidet (`eckenAbweichung`, größte Eckverschiebung) –
  paarweise, damit langsames Wandern nicht als Stillhalten gilt. Ein
  Fehlschlag oder ein geänderter Frame (Drehen des Handys) beginnt von vorn.
- **Overlay:** ein `<svg class="kamera-overlay">` über dem ganzen Video
  mit einem `<polygon>`; die Punkte setzt `overlayPunkte` als **Attribut**
  (kleiner Frame → Videopixel → Elementpixel unter `object-fit: contain`,
  `anzeigeAbbildung`), kein Inline-Style, kein Inline-Skript (CSP).
  Gestrichelt gelb, solange das Viereck wandert, durchgezogen grün, sobald
  es stabil ist; dazu die Statuszeile „Beleg erkannt.“ (`role=status`,
  `liveHinweis`).
- **Auslösen:** manuell immer möglich, auch ohne Viereck. Automatisches
  Auslösen ist ein Ankreuzfeld im Dialog (E-20), **standardmäßig aus**, gilt bis zum
  Neuladen der Seite (nicht gespeichert, `autocomplete="off"`); angekreuzt
  löst die erste stabile Erkennung aus. Nach einem Klick auf „Kamera-App
  verwenden“ (dessen Dateiauswahl offen sein kann) löst der Dialog nicht
  mehr selbst aus, nach einer gescheiterten Aufnahme erst wieder, wenn das
  Viereck einmal verloren war (keine Wiederholungsschleife).
- **Startecken:** beim Auslösen gehen die Ecken nur eines **stabilen**
  Vierecks (`startEckenAusLive`, auf Videopixel hochgerechnet und
  geklemmt) mit dem Foto mit: `eckenVormerken(datei, ecken)` in
  `scanner.js` (eine `WeakMap` am `File`-Objekt, nichts verlässt die
  Seite). `scannerOeffnen()`/`automatischAufbereiten()` erkennen zuerst
  wie bisher auf dem aufgenommenen Foto selbst (genau dieser Frame, feiner
  als die Vorschau); nur wenn das nichts findet, nehmen sie die vorgemerkten
  Live-Ecken statt Standardrahmen bzw. ganzem Bild (`erkannteEcken`). Im
  Normalfall stimmen beide überein. Das ganze Kamerabild bleibt das
  Original (E-10).

Der Nutzen hängt an der Trefferquote der Kantenerkennung (E-03, #142).
Prüfung auf einem echten Mittelklasse-Handy (ruckelt das Video? wie oft
„Beleg erkannt“?) steht noch aus.

## 3. PDF-Erzeugung und PDF-Eingang

- Aus den aufbereiteten Seitenbildern erzeugt der Server **ein PDF**
  (reines PHP, FPDF o. ä., JPEG unverändert eingebettet, eine Seite je
  Bild, A4 bzw. Bildformat). Metadaten: Titel = Referenznummer, keine
  personenbezogenen Daten.
- **Hochgeladene PDFs werden nicht umgebaut** (freie PHP-PDF-Parser können
  moderne komprimierte PDFs nicht zuverlässig importieren). Sie werden
  unverändert gespeichert.
- **PDF-Rasterung** (für Vorschaubilder und KI bei gescannten PDFs):
  im Browser mit **pdf.js** (vendored, Worker aus `'self'`), während ein
  angemeldeter Nutzer den Posteingang geöffnet hat. Seitenbilder werden als
  eigene Blobs gespeichert (`document.page_image_blob_ids`).
- **Textlayer**: für digitale PDFs Text serverseitig extrahieren (reiner
  PHP-PDF-Parser, z. B. smalot/pdfparser – Kompatibilität prüfen).
  Hat ein PDF brauchbaren Text (Heuristik: > 200 Zeichen, Anteil
  Buchstaben), reicht der Text für die KI; sonst Seitenbilder.
- **E-Rechnungen**: ZUGFeRD/Factur-X (XML im PDF eingebettet) und
  XRechnung (XML-Datei) werden erkannt und **strukturiert ohne KI**
  gelesen (Lieferant, USt-ID, IBAN, Rechnungsnr., Beträge, Steuern). KI nur
  für Kategorie/Zweck. Bibliothek prüfen (horstoeko/zugferd o. ä.) oder
  schmaler eigener Parser für die benötigten Felder.

**Stand M5-4** (issue #34): `pdf_erzeugen` baut das PDF je Seite aus der
aufbereiteten Fassung des Browsers (`document.processed_blob_ids`,
unverändert eingebettet, mehrere in einem Aufruf). Sie wird nur genommen,
wenn sie laut Blob-Metadaten ein JPEG ist – das lässt sich erst hier mit
entsperrtem Tresor prüfen, sonst gilt die Seite als nicht aufbereitet. Ohne
Fassung läuft das Original durch den GD-Fallback (Abschnitt 2, höchstens
eine Seite je Aufruf). Dessen JPEG ist ein Zwischenblob und wird nach dem
PDF gelöscht; Originale und Browser-Fassungen werden nie gelöscht (E-10).
Damit wird auch eine nicht aufbereitete JPEG-Seite nicht mehr unverändert
eingebettet (bis M5-4 der Fall), sondern schwarzweiß.

**Stand M4-4** (issue #26): Aus reinen Bildbelegen (JPEG/PNG) erzeugt der Job
`pdf_erzeugen` (Abschnitt 5) die Arbeitsfassung; ein einzeln eingereichtes PDF
wird selbst zur Arbeitsfassung (`document.pdf_blob_id` zeigt dann auf den
Original-Upload); eine Mischung aus Bildern und PDF(s) oder mehr als ein PDF
bleibt ohne `pdf_blob_id` – die Originale sind dann die einzige Fassung
(`App\Service\Document\PdfErzeugung`, `App\Service\Job\JobHandler`). Die
Originale werden dabei nie verändert oder gelöscht (E-10).

Der PDF-Writer (`App\Service\Processing\PdfAusBildern`) ist eine eigene,
handgeschriebene Implementierung ohne Fremdbibliothek: jedes JPEG wird
unverändert als `/DCTDecode`-XObject eingebettet, der Generator schreibt die
PDF-Objekte einzeln und streamt sie direkt in `BlobService::store()` – nie
liegt die ganze Datei oder mehr als eine Seite im Speicher. Seitenformat: A4,
wenn das Bildseitenverhältnis (nach Anwendung der EXIF-Drehung) bis auf 3 %
daran liegt, sonst A4-Breite (hochkant) bzw. A4-Höhe als Breite (querformatig)
mit der Höhe des Bildes folgend (lange Kassenbons werden nicht beschnitten).
Die EXIF-Drehung (`App\Service\Processing\JpegInfo`, aus den JPEG-Markern
gelesen, kein `ext-exif`) wird ausschließlich über die Content-Stream-Matrix
umgesetzt, nie durch Neukodieren des Bildes. Ein PNG-Beleg wird vorher über
GD nach JPEG gewandelt (`App\Service\Processing\PngZuJpeg`, Transparenz auf
Weiß geflacht) – der einzige Fall, in dem CLAUDE.md §1 den Server-Fallback für
Bildarbeit vorsieht. Info-Dictionary trägt nur `/Producer` und, wenn
vorhanden, `/Title` = Referenznummer – keine weiteren Metadaten.

Der Job selbst läuft nur, weil ein angemeldeter Nutzer ihn abarbeitet: `POST
/api/jobs/step` (Executor `session`) kommt erst mit M4-7 – bis dahin bleiben
neu eingereichte Belege ohne `pdf_blob_id`, was die Originale nicht
beeinträchtigt.

**Stand M4-8** (issue #30): die PDF-Rasterung selbst, als eigener
Browser-Job `render_pages` (`App\Service\Document\PdfRasterung`,
Abschnitt 5). `pdf_erzeugen` legt ihn in seinem ersten Schritt an, sobald
mindestens ein PDF unter den Originalen liegt (der Einzel-PDF-Fall
eingeschlossen – auch ein Upload, der direkt zur Arbeitsfassung wird, hat
noch keine Seitenbilder) – höchstens einmal je Dokument
(`JobRepository::gibtEs()`), und deckt alle PDF-Originale eines Dokuments
in einem Lauf ab, nicht eines je Datei.

- *Browser-Job statt Session-Job:* `POST /api/jobs/step` (M4-7) ruft nur
  `JobHandler`-Typen auf, die serverseitig fertig werden. Rendern passiert
  im Browser, deshalb eigene Routen unter `/api/rasterung/...`
  (`App\Api\RasterungController`). Anders als bei `/api/jobs/step`, wo die
  Route nur „angemeldet“ verlangt und `App\Service\Job\JobRunner` je Typ
  gegen `App\Service\Job\JobTyp::recht()` prüft (mehrere Jobtypen hinter
  einer Route), deklariert hier die Route selbst `document.edit` – es
  steckt ohnehin nur ein Jobtyp dahinter.
- *Wo im Browser:* nur auf `/app/posteingang` und der Detailseite
  (`public/js/rasterung.js`, `App\App\InboxController::rasterungDaten()`),
  nicht auf jeder angemeldeten Seite wie `public/js/jobs.js` – die Spec
  spricht bewusst von „während ein angemeldeter Nutzer den Posteingang
  geöffnet hat“.
- *Protokoll:* `POST /api/rasterung/naechste` beansprucht den ältesten
  `render_pages`-Job (`JobRepository::claim()`, 120 s Sperre) und ermittelt
  beim allerersten Aufruf `job.state.quellen` (die PDF-Blob-IDs der
  Originale) – danach steht dort immer, wo der Lauf steht:
  `{quellen, quelle, seite, seq, letzte}`. `seq` zählt Seiten fortlaufend
  über alle Quellen hinweg, passend zu `document_artifact.seq`.
  `GET /api/rasterung/{job}/{lock}/quelle/{n}` liefert das entschlüsselte
  Original einer Quelle (wie `App\App\InboxController::datei()`, ohne
  CSRF – der Lock in der URL ist das Credential). `POST
  /api/rasterung/{job}/{lock}/seite/{quelle}/{seite}/{seiten}` speichert
  eine gerenderte Seite (Rohdaten im Body, JPEG, höchstens 2 MiB,
  höchstens 4000 px Kantenlänge, höchstens 200 Seiten je Quelle) als
  `document_artifact` (`kind: page_image`) plus eigenem Blob; die Antwort
  nennt `quelle`/`seite`, an denen der Browser weitermacht – die Sperre
  bleibt über die ganze Aufgabe hinweg gehalten
  (`JobRepository::fortschritt()`, anders als beim Session-Job, dessen
  `schrittErledigt()` sie je Aufruf freigibt), ein erneutes
  `naechste()` würde also nichts Wiederaufnehmbares finden. Ein
  wiederholter Upload derselben Seite (Netzwerkfehler auf dem Rückweg)
  antwortet idempotent, ohne doppelt zu speichern – auch nach Abschluss
  des ganzen Jobs. `POST /api/rasterung/{job}/{lock}/abbruch` markiert den
  Job `fehler` (`grund: defekt` – kein Frame passt, oder `passwort` –
  pdf.js verlangt ein Kennwort) oder gibt ihn frei (`grund: browser`, die
  Karte ist geschlossen worden).
- *pdf.js:* vendored unter `public/js/vendor/pdfjs/` (Details und
  Lizenzprüfung: `public/js/vendor/README.md`), `useWasm: false` – die CSP
  (`docker/web/.htaccess`) hat kein `wasm-unsafe-eval`, WebAssembly wäre
  ohnehin blockiert; die beiden reinen JS-Fallback-Decoder (JBIG2,
  OpenJPEG) sind mitausgeliefert, `enableScripting: false`
  (PDF-JavaScript-Aktionen), Standardschriften und CJK-Cmaps fehlen
  bewusst (Folgeaufgabe bei Bedarf).
- *Ein Rendering-Lauf je Dokument überschreibt den vorigen:* wird ein
  Dokument je erneut gerendert, löscht der letzte Schritt die
  Seitenbilder-Blobs anderer `render_pages`-Läufe desselben Dokuments
  (`DocumentArtifactRepository::fremdeBlobIds()`) – das Löschen des Blobs
  nimmt die `document_artifact`-Zeile über `ON DELETE CASCADE` mit
  (02, Migration 015).

**Stand M7-4** (issue #46): E-Rechnungen werden im selben Session-Job
`extract_text` gelesen (`App\Service\Document\Texterkennung`), ohne KI.

- *Eigener Parser statt horstoeko/zugferd:* die Bibliothek zieht
  jms/serializer, symfony/\*, setasign/fpdi und für das Auslesen des
  PDF-Anhangs smalot/pdfparser nach – Letzteres ist in M7-3 an PHP-8.5-
  Warnungen und Laufzeit gescheitert. Gebraucht werden nur gut ein Dutzend
  Felder an festen EN-16931-Pfaden, deshalb `App\Service\Processing\
  ERechnung\*` (framework-frei wie `Processing\Pdf\*`): `ERechnungLeser`
  liest **CII** (`rsm:CrossIndustryInvoice` – ZUGFeRD 2.x, Factur-X,
  XRechnung-CII) und **UBL 2.1** (`Invoice`/`CreditNote` – XRechnung-UBL)
  per ext-dom/XPath; `ERechnungPdf` findet die eingebettete XML eines
  ZUGFeRD-/Factur-X-PDFs über `PdfDokument::eingebetteteDateien()`
  (`/AF` des Katalogs und Name-Tree `/Names /EmbeddedFiles`, inkl.
  `/Kids`), nur Dateien mit Endung `.xml`, die Standardnamen
  (`factur-x.xml`, `zugferd-invoice.xml`, `xrechnung.xml`) zuerst, innerhalb
  des Dekodier-Budgets des Textlayers. ZUGFeRD 1.0 (eigener Namensraum, vor
  EN 16931) wird erkannt und bewusst **nicht** gelesen.
- *Felder:* Belegart (Typcode 381/396/532 bzw. `CreditNote` → Gutschrift,
  sonst Rechnung), Nummer, Datum, Fälligkeit, Leistungszeitraum (sonst
  Lieferdatum), Währung, Brutto/Netto, Steuerzeilen (Satz, Betrag),
  Lieferant (Name, Anschrift, USt-ID, Steuernummer, E-Mail), IBAN/BIC,
  Gläubiger-ID, Mandatsreferenz, Kunden- und Vertragsnummer, Zahlart
  (UNTDID 4461), „bereits bezahlt" (offener Betrag 0). Beträge als
  XML-Dezimalzahl streng in Cent (Punkt, kein Komma; mehr als zwei
  Nachkommastellen → gerundet mit Hinweis). Summenprüfung wie in der
  Prüfansicht, als Hinweis. Ohne Nummer, Datum (Format 102 bzw. ISO),
  Währung oder Brutto gilt die E-Rechnung als `defekt` – **nie raten**.
- *Sicherheit:* ein Dokument mit `<!DOCTYPE` wird abgewiesen, bevor libxml
  es sieht (keine Entitäten, kein XXE), `LIBXML_NONET`, höchstens 32 MiB,
  libxml-Fehler nur intern (keine Warnung). Befund
  (`App\Service\Processing\ERechnung\ERechnungBefund`: `gelesen`/`keine`/
  `defekt`/`nicht_unterstuetzt`/`zu_gross`) – nie ein Jobfehler.
- *Ablauf:* Schritt `''` nimmt PDF- **und** XML-Originale als Quellen. Je
  Quelle ein Schritt: XML → `ERechnungLeser`; PDF → erst die eingebettete
  E-Rechnung, dann der Textlayer (so bedeutet ein gespeicherter Text, dass
  beides erledigt ist – Idempotenz bei Abbruch). Ergebnis als
  `document_artifact` **`kind = e_rechnung`** (eigenes Kind statt
  `extraction`, damit die KI-Ergebnisse aus M7-5 eigene Läufe behalten;
  `seq` = Quellposition, eigener DEK, JSON `{befund, syntax, profil,
  extraktion}`, `extraktion` im Format `extract-v1` aus Abschnitt 6 – die
  Felder, die eine E-Rechnung nicht kennt (Kategorie, Zweck, Sphäre,
  Kostenstelle, Serie), bleiben leer). `ocr_status = fertig`, wenn jedes
  Original ein PDF mit brauchbarem Textlayer oder eine gelesene
  E-Rechnungs-XML ist. `Texterkennung::eRechnung()` liefert die erste
  gelesene E-Rechnung des neuesten Laufs (Andockpunkt für `ai_extract`/M7-5:
  KI nur noch für Kategorie/Zweck).
- *Upload:* `MagicBytes::XML` (`application/xml`): optionale BOM, dann
  Markup – und die **Wurzel** muss CII oder UBL-Invoice/-CreditNote sein
  (`ERechnungLeser::wurzel()` per XMLReader auf den ersten 64 KiB,
  `MagicBytes::HEAD_BYTES`); HTML, SVG und beliebiges XML bleiben 415.
  Öffentliche Einreichung und interne Erfassung nehmen `.xml` an.
- *Anzeige:* XML ist keine Seite – im Posteingang und in der Prüfansicht nur
  als Link „E-Rechnung (XML)", ausgeliefert mit `Content-Disposition:
  attachment` und `nosniff`, nie inline. `pdf_erzeugen` überspringt reine
  XML-Belege; neben genau einem PDF (der Sichtfassung) wird dieses PDF die
  Arbeitsfassung, neben Bildern bleibt es beim gemischten Fall.
- *Prüfansicht:* ohne gespeicherten Beleg füllt
  `App\Service\Invoice\Pruefung::vorbelegen()` das Formular aus der
  `extraktion` (nimmt nur, was ins Feld passt; dieselbe Methode nutzt M7-5
  für die KI-Antwort). Lieferant: exakter Blind-Index-Treffer in der
  Reihenfolge USt-ID, Steuernummer, IBAN, Gläubiger-ID (Abschnitt 7, Stufen
  1–3), genau ein Treffer mit zur Richtung passender Rolle; sonst Name und
  (gültige) IBAN in „Neu anlegen" vorbelegt. Hinweis „ohne KI übernommen"
  samt Warnungen; eine nicht lesbare E-Rechnung nennt ihren Befund.
  Gespeichert wird erst durch die Person (CLAUDE.md §6).
- `ext-dom` und `ext-xmlreader` stehen jetzt in `composer.json` und im
  Hosting-Check (06 §5).

**Stand M7-3** (issue #45): Textlayer digitaler PDFs als Session-Job
`extract_text` (`App\Service\Document\Texterkennung`, Abschnitt 5).

- *Eigener Parser statt smalot/pdfparser:* geprüft wurde smalot/pdfparser
  2.12.5 (LGPL-3.0, reines PHP) gegen dessen eigene 58 Beispiel-PDFs unter
  PHP 8.5: PHP-8.5-Warnungen („float … is not representable as an int“ in
  `Font.php`, würde `failOnWarning` reißen) und für ein 6-MB-PDF 12,8 s bei
  264 MB Speicher – zu viel für 30 s/256 MB je Request (§1 CLAUDE.md).
  Stattdessen `App\Service\Processing\Pdf\*` (handgeschrieben wie
  `PdfAusBildern`, framework-frei): Objekte per Durchlauf der Datei statt
  über die Xref-Tabelle (robust gegen kaputte Tabellen, inkrementelle
  Updates: die spätere Definition gilt; Streams werden über `/Length`
  übersprungen), Object Streams, Filter FlateDecode (inkl. PNG-/TIFF-
  Prädiktor), ASCIIHex, ASCII85; Schriften mit ToUnicode-CMap
  (`bfchar`/`bfrange`), einfache Schriften über WinAnsi/MacRoman/Standard +
  `/Differences` mit Glyphnamen, Type0/Identity-H (2 Byte je Code – die
  Code-Länge kommt nie aus dem Codespace der ToUnicode-Map, den Adobe auch
  für einfache Schriften auf `<0000> <FFFF>` setzt); Content-Streams mit
  Text-, Positions- und Grafikzustands-Operatoren, Form-XObjects (bis Tiefe
  8), Bilder und Inline-Bilder werden nie dekodiert. Zeilenumbruch, wenn
  die Grundlinie um mehr als ½ em springt; Leerzeichen bei einer Lücke von
  mehr als 0,15 em (Kerning bleibt darunter). Gegenprobe mit denselben
  58 Dateien: keine Warnung, höchstens 36 MB, die meisten < 0,1 s.
- *Grenzen:* höchstens 200 Seiten (wie die Rasterung), 32 MiB dekodierte
  Stream-Daten je PDF (Schutz gegen Dekompressionsbomben – Flate wird
  stückweise mit Budget entpackt), 512 KiB Text je PDF, 2 Mio. Operatoren
  je Seite. Verschlüsselte PDFs (`/Encrypt`) haben keinen lesbaren Text.
  Eine kaputte, verschlüsselte oder zu große Datei ist **kein Jobfehler**:
  `App\Service\Processing\TextlayerBefund` (`gelesen`/`verschluesselt`/
  `defekt`/`zu_gross`) sagt warum, der Beleg geht den Weg über die
  Seitenbilder.
- *Heuristik* (`App\Service\Processing\Textlayer::brauchbar()`): mehr als
  200 Zeichen ohne Leerraum **und** mindestens 50 % davon Buchstaben
  (`\p{L}`; Ziffern zählen nicht – echte Rechnungen liegen bei 55–75 %, eine
  Schrift ohne brauchbare Kodierung liefert U+FFFD bzw. Symbole).
- *Ablauf:* der Job wird bei jedem neuen Beleg neben `pdf_erzeugen` und
  `detect_duplicate` eingereiht (öffentliche Einreichung und interne
  Erfassung), der Beleg entsteht mit `ocr_status = ausstehend`. Schritt
  `''` sucht die PDF-Originale (MIME-Typ aus den Blob-Metadaten); keine →
  `ocr_status = uebersprungen`, Job `uebersprungen`. Danach ein PDF je
  Aufruf (Schritt `quelle`); `job.state` hält nur Blob-IDs und je PDF
  brauchbar ja/nein. Ergebnis je PDF: `document_artifact` `kind = text`,
  `seq` = Position unter den PDF-Originalen, `producer = session`,
  `data_enc` = JSON `{befund, brauchbar, seiten[]}` unter dem eigenen DEK
  des Artefakts (AAD `document_artifact|<id>|data_enc`, zweistufig
  geschrieben). Der letzte Schritt löscht `text`-Artefakte älterer Läufe
  und setzt `ocr_status`: **`fertig`**, wenn alle Originale PDFs sind und
  jedes einen brauchbaren Textlayer hat – dann **entfällt die
  Bildauswertung**, die KI bekommt den Text
  (`Texterkennung::textFuerKi()`, Seiten getrennt durch `\f`, Andockpunkt
  für `ai_extract`/M7-5); sonst **`uebersprungen`** – die KI braucht die
  Seitenbilder (auch bei Bild + PDF gemischt). Ein Beleg mit entschiedenem
  `ocr_status` wird nicht erneut gelesen. Die Rasterung (`render_pages`)
  läuft unabhängig davon weiter – die Seitenbilder dienen auch als
  Vorschau.
- *Migration 026:* `document_artifact.data_enc` wird `MEDIUMBLOB`
  (`VARBINARY(4096)` reicht für Text nicht); offene Belege (`eingegangen`,
  `bereit_zur_auswertung`, `wiedervorlage`, `ki_fehler`) mit
  `ocr_status = keine` bekommen den Job und `ausstehend`.
- `ext-zlib` steht jetzt in `composer.json` (beim Hoster vorhanden, M0).

## 4. Upload

- Generische Upload-Komponente: Dateien in Chunks à 2 MiB
  (`/api/upload/{id}/chunk/{n}`), serverseitig in `var/tmp` gesammelt,
  nach dem letzten Chunk geprüft (Magic Bytes, Größe), **sofort
  verschlüsselt** zum Blob gemacht, Temp-Dateien gelöscht. Umgeht das
  Upload-Limit des Hosters und hält Requests kurz.
- Verwaiste Upload-Chunks räumt der Cron nach 24 h ab.

**Stand M2-4:** `Api\UploadController` + `Service\Upload\*` (issue #11).

- *Routen* (alle `POST`, Pfadsegmente englisch, weil die Chunk-Route oben so
  festgelegt ist):

  | Route | Body | Antwort |
  |---|---|---|
  | `/api/upload` | JSON `{groesse}` | 201 `{id, groesse, chunks, chunk_bytes}` |
  | `/api/upload/{id}/chunk/{n}` | Rohdaten | 200 `{chunk, chunks, fehlend, empfangen, vollstaendig}` |
  | `/api/upload/{id}/finish` | JSON `{name}` | 201 `{blob_id, groesse, typ}` |
  | `/api/upload/{id}/abort` | – | 200 `{status}` |

  `{id}` ist eine 32-stellige Zufalls-Hex-ID, `{n}` der Chunk-Index ab 0. Der
  Chunk-Body sind Rohbytes (`application/octet-stream`), kein Multipart:
  `Http\Request` liest nur JSON, der Controller bekommt den Strom deshalb als
  injizierte Closure (Default `php://input`) – dasselbe Muster wie
  `InstallController` mit `is_uploaded_file`.
- *Rechte:* seit M3-6 `document.submit_internal`, geprüft über die Route
  (`Zugriff::recht(...)->alsApi()`), Credential ist das **CSRF-Token der
  Session** (`_csrf`-Feld oder `X-CSRF-Token`) – nutzbar aus `/app` und
  `/admin`. Optionaler Header `X-Erfassung` (seit M4-6, Abschnitt 1): der
  Abschluss vermerkt den Blob dann für die interne Erfassung; eine
  ungültige Erfassungs-ID wird mit 422 abgewiesen, bevor etwas gespeichert
  wird. Die öffentliche Einreichung (`/einreichen`, M4-2) hat bewusst
  keine Session und nutzt deshalb ihre eigenen Routen unter
  `/einreichen/upload/...` (`App\Api\EinreichungUploadController`): gleicher
  Vertrag, Credential ist stattdessen das Formular-Token (oben, Abschnitt 1).
- *Zustand ohne Tabelle:* ein Verzeichnis je Upload unter
  `shared/var/tmp/upload/<id>/` mit `<n>.part` je Chunk und einer `meta.json`
  (Größe, Chunkzahl, Chunkgröße, Zeitstempel). Kein DB-Tisch – so braucht das
  Aufräumen im Cron keine Datenbankverbindung. **Originalname und der vom
  Browser gemeldete MIME-Typ stehen nicht im Temp-Bereich**: der Name ist ein
  Fachdatum, er kommt erst mit dem Abschluss-Request und geht direkt
  verschlüsselt in die Blob-Metadaten (ohne Pfad, ohne Steuerzeichen,
  max. 200 Zeichen). Die Chunk-Inhalte selbst dürfen dort im Klartext liegen –
  dafür ist `var/tmp` da (CLAUDE.md §1).
- *Chunks:* beliebige Reihenfolge, Wiederholung erlaubt. Jeder Chunk wird
  unter einem Zufallsnamen geschrieben und erst dann umbenannt, ein
  abgebrochener Request hinterlässt also keinen halben Chunk. Ein zu langer
  Chunk wird **beim Lesen** abgebrochen (413), ein zu kurzer landet und gilt
  als fehlend – die Antwort nennt `fehlend`, der Browser schickt nur diese
  Indizes neu.
- *Limits:* `UploadService::CHUNK_BYTES` = 2 MiB, `MAX_FILE_BYTES` = 32 MiB
  (Konstanten, keine Settings). Die angekündigte Größe wird geprüft, **bevor**
  ein Byte fließt.
- *Abschluss:* vollständig? → alle Chunks **einmal** öffnen
  (`UploadService::oeffnen()` → `OffenesUpload`, seit #242/N-13: Länge je
  Chunk am offenen Handle geprüft) → Magic Bytes der ersten Bytes (nur JPEG, PNG,
  PDF und seit M7-4 E-Rechnungs-XML mit CII-/UBL-Wurzel, Abschnitt 3; der
  Client-MIME wird nie verwendet) → `BlobService::store()` bekommt
  die Chunks als Generator **über dieselben Handles**, beginnend mit den
  schon geprüften Bytes – ein parallel neu gesendeter Chunk ändert weder
  Prüfung noch Gespeichertes, und der Klartext wird nie zu einer zweiten Datei
  → Temp-Verzeichnis gelöscht. Versiegelt wird an `vault.public_key`
  (Tabelle `vault`, Migration 004); solange keine Zeile existiert, antwortet
  der Abschluss 503 statt zu raten – der Installer legt sie mit M3-2 an.
  Abgelehnte Uploads (falscher Typ) werden sofort gelöscht, nicht erst vom
  Cron.
- *Fehler:* JSON `{fehler: "<deutscher Satz>"}` mit 403 (CSRF), 404
  (unbekannt), 409 (unvollständig, zusätzlich `fehlend`), 413 (zu groß), 415
  (Typ), 422 (Index/Größenangabe), 503 (kein Tresor). Die Meldungen nennen
  weder Dateinamen noch Größen noch IDs.
- *Aufräumen:* `Cron\UploadCleanupTask` (`uploads_aufraeumen`,
  `KEEP_HOURS = 24`) im `aufraeumen`-Bucket. Entscheidend ist der **jüngste**
  Zeitstempel im Verzeichnis, ein laufender Upload wird also nicht unter dem
  Browser weggeräumt.
- *Wartungsmodus:* der Shim lässt `/api/...` nicht durch (nur `/admin`,
  `/css/`, `/js/`) – ein Upload während eines Updates scheitert und wird
  aufgeräumt.
- *Browser:* `public/js/upload.js`, klassisches Skript ohne Fremdbibliothek
  (CSP `script-src 'self'`, `connect-src 'self'`). Reine Funktionen
  (`chunkGrenzen`, `fortschrittProzent`, `fehlertext`) plus
  `dateiHochladen(datei, {csrf, fetch, onFortschritt})`: eröffnen, Chunks
  **nacheinander**, abschließen; bei einem Fehler wird der Upload sofort
  abgebrochen. `fetch` ist injizierbar, damit `node --test` den ganzen Ablauf
  ohne DOM und ohne Netz prüft. Oberflächen: `/einreichen` (M4-2, eigene
  Routen) und `/app/belege/neu` (M4-6, `public/js/erfassen.js`); seit M5-4
  lädt jede Bildseite dort Original und aufbereitete Fassung als zwei
  Uploads hoch (Abschnitt 1).

**Pflicht-Tests (Upload):** `MagicBytesTest` (die erlaubten Typen inkl.
XRechnung CII/UBL mit BOM und ohne Prolog, HEIC (`heic`/`heix`, `mif1` mit
HEVC-Marke), ZIP/ELF/HTML/Text/GIF/AVIF/MP4/abgeschnittener ftyp-Kopf/leer/
zu kurz abgelehnt, PDF-Kopf mit Versatz abgelehnt, HTML/SVG/beliebiges XML/
XML mit DOCTYPE/ZUGFeRD 1.0 abgelehnt); `UploadServiceTest` (leere Datei und Datei über dem Limit vor der
Übertragung abgelehnt, Limit selbst erlaubt, zu langer Chunk abgelehnt und
ohne Rest, letzter Chunk kürzer, verkehrte Reihenfolge ergibt die richtige
Datei, Wiederholung überschreibt, Index außerhalb, fehlender und zu kurzer
Chunk stehen in `fehlend`, ID ohne Pfad-Traversal, `discard` wiederholbar,
Metadaten ohne Fachdaten, Aufräumen nur bei altem Verzeichnis; seit #242:
ein nach dem Öffnen neu gesendeter Chunk ändert weder Kopf noch Inhalt);
`UploadCleanupTaskTest` (24-h-Grenze, Wiederholbarkeit);
`ChunkUploadTest` (voller Weg gegen beide Speicher-Backends, entschlüsselter
Blob identisch, Originalname verschlüsselt und ohne Pfad, `var/tmp` leer,
unvollständig/falscher Typ/kein Tresor/fehlendes CSRF/unbekannte ID);
`VaultRepositoryTest`; `tests/js/upload.test.js` (Chunk-Grenzen, Fortschritt,
Reihenfolge der Requests, Abbruch bei Fehler).

## 5. Verarbeitungspipeline (Jobs)

Jeder Beleg durchläuft Jobs in `job` (siehe 06-betrieb.md,
„Session-Worker", und 07-worker.md). Spalte „Executor": ohne / mit
aktivem Worker-Modul.

0. `pdf_erzeugen` (Abschnitt 3, issue #26/M4-4: PDF-Arbeitsfassung aus
   Bildseiten) – `session`/`session`
1. `extract_text` (Webhoster: PDF-Textlayer / E-Rechnungs-XML; seit
   issue #45/M7-3 der Textlayer, seit issue #46/M7-4 die E-Rechnung,
   `App\Service\Document\Texterkennung`, Abschnitt 3; eingereiht beim
   Eingang neben `pdf_erzeugen`) –
   `session`/`session`
2. `render_pages` (nur PDFs ohne Seitenbilder; Abschnitt 3, issue #30/M4-8) –
   `browser`/`worker`
3. `ocr` (durchsuchbares PDF/A + Text) – *entfällt*/`worker`
4. `ai_extract` (→ KI-Anbieter) – `session`/`worker`
5. `resolve_supplier` (Abschnitt 7; seit issue #48/M7-6
   `App\Service\Document\LieferantenAufloesung`, eingereiht von `ai_extract`,
   sobald es einen Beleg mit gültigem Ergebnis auf `ausgewertet` setzt, und per
   Migration 035 für schon ausgewertete Belege) – immer `session`
6. `classify` (Kategorie/Kostenstelle, Regeln + KI-Vorschlag) – `session`
7. `detect_recurring` (Abschnitt 9; seit issue #57/M8-1 `App\Service\Invoice\Serien`,
   eingereiht von „Geprüft“ in der Prüfansicht und per Migration 036 je Lieferant
   und Richtung für den neuesten schon geprüften Beleg) – `session`
8. `detect_duplicate` (issue #40/M6-6: berechnet `document.content_bi` aus den
   Originaldateien, ein Schritt je Datei, `App\Service\Document\Duplikatindex`;
   eingereiht gleich beim Eingang neben `pdf_erzeugen`, nicht erst nach den
   Schritten davor – den Verdacht selbst leitet SQL bei jedem Lesen ab, Details
   02 „Statusmodell“, Stand M6-6) – `session`
9. `match_transactions` (siehe 04, falls Buchungen vorhanden) – `session`

Die Logik von `pdf_erzeugen`, `extract_text` (`Processing\Pdf\*`), `ocr`
und `ai_extract` liegt in `app/src/Service/Processing/` und ist framework-frei (siehe CLAUDE.md §6a);
jeder dieser Jobtypen implementiert `App\Service\Job\JobHandler` (`typ()`,
`recht()`, `schritt()`) – das ist der Andockpunkt, den der Runner aus M4-7
(`POST /api/jobs/step`) aufruft. `render_pages` läuft anders: sein Schritt
passiert im Browser, nicht in einem Request dieses Runners, deshalb
implementiert `App\Service\Document\PdfRasterung` nur `App\Service\Job\
JobTyp` (`typ()`, `recht()`, ohne `schritt()`) und liegt in
`app/src/Service/Document/`, framework-frei bis auf die eigenen Routen
(`App\Api\RasterungController`, Abschnitt 3). Ergebnisse ab `extract_text`
werden als `document_artifact` gespeichert (`render_pages` seit M4-8:
`kind: page_image`); `pdf_erzeugen` schreibt direkt `document.pdf_blob_id`,
weil es kein Auslese-Ergebnis, sondern die Datei selbst ist.

Jeder Schritt idempotent, einzeln wiederholbar, Fehler landen am Beleg
sichtbar („KI-Anbieter nicht erreichbar – erneut versuchen").

**Stand M7-5** (issue #47, Migration 031): `ai_extract` ist
`App\Service\Document\KiAuslesung` (Recht `document.edit`, Executor
`session`); die Fachlogik liegt framework-frei in
`App\Service\Processing\KiAuslese\` (`Auslesung`, `AusleseEingabe`,
`AusleseVorschlag`), geprüft durch den Architekturtest
`tests/Support/ProcessingArchitectureTest.php` (kein `App\Http`,
`App\Repository`, `App\Database`, … , kein PDO, keine Superglobals, keine
Session-/Header-Funktionen in `app/src/Service/Processing/`).
- *Eingereiht* beim Annehmen im Posteingang (`Posteingang::entscheiden()`,
  Ziel `bereit_zur_auswertung`), beim Archiv-Import für Belege, die gleich
  `bereit_zur_auswertung` sind (nicht für Wiedervorlagen), über „KI erneut
  versuchen“ in der Prüfansicht und per Migration 031 für alle Belege, die
  schon in `bereit_zur_auswertung` stehen – immer über
  `KiAuslesung::einreihen()`, das keinen zweiten offenen Lauf anlegt.
- *Schritt `''`:* Beleg nicht (mehr) `bereit_zur_auswertung` (jemand hat ihn
  übernommen, festgeschrieben …) oder kein nutzbares Standardprofil (aktiv,
  Key lässt sich entschlüsseln) → Job `uebersprungen`, der Beleg wird von Hand
  erfasst. Sind `extract_text` oder `render_pages` für das Dokument noch
  offen, wartet der Job 30 s (`JobSchrittErgebnis::warten()`, 06 §4). Hat der
  Beleg nichts, was die KI lesen kann (kein Text, keine E-Rechnung, keine
  Seiten bzw. ein Profil ohne `vision`), gibt es keinen Aufruf: Artefakt mit
  dem Grund, Status `ki_fehler`.
- *Schritte `fragen`, `fallback`, `reparieren`:* je **ein** KI-Aufruf
  (`KiAbfrage::fragen()`, `fallbackFragen()`, `reparieren()`), als
  zweiphasiger Schritt (06 §4: Verbindung vor dem Aufruf freigegeben); die
  Reparatur mit dem Profil des vorigen Versuchs, der Fallback nur, wenn der
  erste Aufruf einen Ausfall traf und ein anderes nutzbares Fallback-Profil
  da ist. Höchstens drei Aufrufe. Hat jemand den Beleg zwischen zwei Schritten
  übernommen, wird nicht mehr gefragt.
- *Eingabe:* Textlayer (`Texterkennung::textFuerKi()`, nur bei
  `ocr_status = fertig` – dann keine Bilder), die Felder einer gelesenen
  E-Rechnung, sonst Seitenbilder: je Original die aufbereitete Fassung des
  Scanners, sonst das Bild selbst (PNG → JPEG per `PngZuJpeg`), für PDFs die
  Seitenbilder des neuesten Rasterungslaufs; höchstens `max_images` und
  20 MiB je Aufruf. Dazu Freitext der Einreichung, aktive Kategorien (mit
  Richtung und `ai_hint`) und Kostenstellen.
- *Ergebnis:* je Versuch ein `document_artifact` `kind = extraction`, `seq` =
  Versuch 0–2, eigener DEK, `data_enc` = JSON `{versuch, meldung, extraktion,
  hinweise}` – `versuch` mit Rohantwort, Modell, Prompt-Version, Tokens,
  Dauer, HTTP-Status, Fallback ja/nein (`KiVersuch::toArray()`), `meldung`
  ohne Beleginhalt (`KiErgebnis::meldung()` über die Kette), bei gültiger
  Antwort `extraktion` (`extract-v1` nach den Server-Prüfungen, Abschnitt 6)
  und `hinweise`. Dort liest der nächste Schritt den vorigen Versuch;
  `job.state` hält nur den Zähler `n`. Der letzte Schritt löscht ältere Läufe
  (`loescheAndereLaeufe()`) und setzt `ausgewertet` (gültig) bzw. `ki_fehler`
  – nur aus `bereit_zur_auswertung` (`UPDATE … WHERE status = ?`), ein
  inzwischen übernommener oder festgeschriebener Beleg bleibt, wie er ist
  (01 §7). „KI fehlgeschlagen“ ist kein Jobfehler.
- *Idempotenz:* Liegt der Versuch eines Schritts schon vor (Request nach dem
  Aufruf abgebrochen), geht der wiederholte Schritt von dort weiter, ohne neu
  zu fragen.

## 6. KI-Anbindung

### Client
- `LlmClient`-Interface, eine Implementierung `OpenAiCompatibleClient`
  (curl, `POST {base_url}/chat/completions`, Bearer-Key, Timeout aus
  Profil) und `FakeLlmClient` für Tests.
- Bilder als `image_url` mit `data:image/jpeg;base64,…`, max. `max_images`
  Seiten (Rest: nur Text).
- Wenn Profil `json_schema=true`: `response_format` mit JSON-Schema
  (llama.cpp und OpenAI unterstützen das). Sonst: Schema im Prompt +
  robuste Extraktion des ersten JSON-Objekts.
- Validierung gegen das Schema in PHP; bei Verstoß **ein** Reparatur-Call
  („Hier ist dein JSON und der Validierungsfehler, gib korrigiertes JSON");
  danach `ki_fehler`.
- Pro Aufruf wird protokolliert: Anbieter, Modell, Prompt-Version,
  Tokens (falls geliefert), Dauer, Ergebnisstatus; Rohantwort verschlüsselt.
- Admin-Seite „KI-Anbieter": Profile anlegen, **„Verbindung testen"**
  (Mini-Prompt + optional Testbild), Standardprofil wählen, optional
  Fallback-Profil.
- Voreinstellungen als Vorlagen, in dieser Reihenfolge umgesetzt (E-08):
  1. **OpenAI (Standard)**: Vision + `response_format: json_schema`.
  2. **Anthropic** über den OpenAI-kompatiblen Endpunkt – Bild- und
     `response_format`-Unterstützung beim Verbindungstest ermitteln und im
     Profil speichern; ohne `json_schema` Prompt-Schema-Pfad.
  3. **llama.cpp-Server** (Vision-Modell mit mmproj) über eigenen
     Reverse-Proxy mit TLS + API-Key.
- Das Standardprofil ist nach der Installation „OpenAI" (ohne Key =
  KI-Funktionen deaktiviert, Belege werden dann nur manuell erfasst).

**Umsetzung (M7-1, issue #41, Migration 024):** `/admin/ki-anbieter`
(`admin.settings`) verwaltet die Profile (`App\Admin\KiAnbieterController`,
Regeln in `App\Service\Ki\KiAnbieterService`, SQL in
`App\Repository\AiProviderRepository`). Vorlagen sind
`App\Service\Ki\KiVorlage` (`OpenAi`, Modell `gpt-6-luna`; `Anthropic`,
siehe M7-1b unten; `LlamaCpp`, siehe M7-1c unten). Regeln: Basis-URL nur `https://`,
ohne Zugangsdaten, Parameter und `/chat/completions`; API-Key nur sichtbares
ASCII (er landet in einem HTTP-Header); das Standardprofil ist nicht
löschbar, nur ein aktives Profil kann Standard werden. Der Key wird nie
zurück ins Formular geschrieben (leer = unverändert, Ankreuzfeld entfernt
ihn) und nur über `AiProviderRepository::apiKey()` gelesen; ein mit anderem
Server-Schlüssel verschlüsselter Key gilt als fehlend. Das
**Fallback-Profil** kam mit issue #180 (M7-2b, Migration 030, siehe unten).

Der **Verbindungstest** (`App\Service\Ki\Verbindungstest`) prüft Adresse,
Key und Modell und **ermittelt die Fähigkeiten** (M7-1b, issue #42; vorher
ein einziger Aufruf, der nur die Angaben des Profils bestätigte). Zwei
Proben, höchstens drei Aufrufe, je Aufruf `min(timeout_s, 20 s)`, alle
zusammen höchstens `Verbindungstest::GESAMT_S` = 24 s (unter
`max_execution_time` 30 s):
1. *Text mit Schema:* eine Frage, die in einem Satz zu beantworten ist
   („Welche Farbe hat der Himmel …“), gesendet mit `response_format` und
   einem Mini-Schema `{ok, farbe}`. Antwortet der Server mit **nacktem
   JSON** `{ok: bool, farbe: string}`, erzwingt er das Schema → `json_schema`
   = ja; antwortet er mit dem Satz, ignoriert er den Parameter (so der
   OpenAI-kompatible Endpunkt von Anthropic: kein Fehler, kein Effekt) → nein.
   Lehnt er den Parameter ab (HTTP 400/422), geht die Frage **einmal** ohne
   `response_format` erneut hinaus, das Ergebnis ist „nein“. Diese Probe ist
   zugleich der eigentliche Verbindungstest.
2. *Bild:* ein erzeugtes rotes Testbild (`data:image/jpeg;base64,…`); das
   Modell muss „rot“/„red“ antworten → `vision` = ja. Falsche Antwort oder
   HTTP 400/422 → nein.

Beide Proben laufen **immer**, unabhängig von den Häkchen im Profil – das
Ergebnis ist, was das Modell tut, nicht was im Formular steht. Jeder andere
Fehler (401/403/404/429/5xx, Weiterleitung, keine Chat-Antwort, Timeout,
Zeitbudget) beendet den Test **ohne Ergebnis**: Meldung (HTTP-Status
übersetzt, Text des Anbieters gekürzt und um den Key bereinigt), das Profil
bleibt unverändert. Ohne `max_tokens`/`temperature`, weil neuere OpenAI-Modelle
beide unter diesem Namen ablehnen. Nichts wird geloggt. Transport ist
`App\Service\Ki\ChatHttp` (`CurlChatHttp`: nur HTTPS, folgt **keinen**
Weiterleitungen, damit der Bearer-Key nicht an ein Umleitungsziel geht) –
darauf baut M7-2 den `LlmClient` auf.

**Umsetzung (M7-1b, issue #42):** keine Migration, keine neue Route.
- **Vorlage `Anthropic`:** Basis-URL `https://api.anthropic.com/v1` (der
  Client hängt `/chat/completions` an), Modell `claude-sonnet-5-5`, Zeitlimit
  60 s, `vision` ja, `json_schema` **nein** (der Endpunkt ignoriert
  `response_format`, das Schema geht in den Prompt, bis der Test etwas anderes
  zeigt), `max_images` 4, `max_tokens` 4000. Reihenfolge der Vorlagen: OpenAI,
  Anthropic (E-08). Eine zweite Client-Implementierung gibt es nicht.
- **Übernahme:** `POST /admin/ki-anbieter/{id}/testen` (unverändert,
  `admin.settings`, CSRF) schreibt das Ergebnis über
  `KiAnbieterService::faehigkeitenUebernehmen()` in `ai_provider.caps`:
  `vision` und `json_schema` aus den Proben; `max_images` und `max_tokens` sind
  nicht erkennbar und bleiben (erkennt der Test `vision`, aber das Profil hat
  `max_images` 0, wird es 1 – die Regel „Vision braucht mindestens ein Bild“).
  Die Meldung nennt, was sich geändert hat („Ins Profil übernommen: Bilder,
  JSON-Schema.“). Hat sich etwas geändert, entsteht ein Audit-Eintrag
  `ki_anbieter.geaendert` (Details: Profil ohne Key, `felder` = `faehigkeiten`,
  `quelle` = `verbindungstest`); ein Test ohne Änderung bleibt ohne Spur.
- Das Formular weist darauf hin, dass der Test Bilder und JSON-Schema selbst
  einträgt; wer ein Häkchen bewusst abschalten will (z. B. Bilder aus
  Kostengründen), muss damit rechnen, dass der nächste Test es wieder setzt.

**Umsetzung (M7-1c, issue #43):** keine Migration, keine neue Route, keine
Client-Änderung. Reihenfolge der Vorlagen: OpenAI, Anthropic, llama.cpp (E-08).
- **Vorlage `LlamaCpp`** (`llamacpp`, Name „llama.cpp-Server“): Basis-URL
  `https://llm.example.org/v1` als **Platzhalter** (der Verein hostet den
  Server selbst; die Anwendung spricht nur `https://`, also steht ein
  Reverse-Proxy mit TLS davor), Modell `default` (`llama-server` prüft den
  Namen nicht), Zeitlimit 180 s (Raspberry Pi/kleiner VPS), `vision` ja
  (Modell mit `--mmproj`), `json_schema` ja (`llama-server` setzt
  `response_format` in eine Grammatik um), `max_images` 2 (kleiner Kontext),
  `max_tokens` 4000. „Verbindung testen“ ersetzt `vision`/`json_schema`
  wie bei den anderen Anbietern durch das tatsächliche Verhalten; fehlt die
  `mmproj`-Datei, meldet der Test „keine Bilder“.
- **Auth:** der API-Key des Profils geht wie bei jedem Anbieter als Bearer
  an den Proxy bzw. `llama-server --api-key`. Weiterleitungen folgt der
  Client nicht – die Basis-URL muss direkt antworten.
- **Offen (geprüft in M7-5):** Ob die eingesetzte `llama-server`-Version
  `max_completion_tokens` (Name, den der Client sendet) auswertet, ist nicht
  zugesichert; ohne Auswertung begrenzt nur der Kontext die Antwortlänge.
- **Doku:** `docs/llama-cpp-reverse-proxy.md` (Serverstart, Caddy/nginx mit
  TLS und Key, freizugebender Pfad, Timeouts, Profil anlegen, Prüfung mit
  `tools/hosting-check.php?llm_url=…`).

**Umsetzung (M7-2, issue #44):** alles in `App\Service\Ki`, framework-frei
(kein Http/Session/Repository), damit Job (M7-5) und Worker (M7b) denselben
Code nutzen; keine Migration, keine Route, nichts wird geloggt.
- `LlmClient::chat(KiAnbieter, Key, LlmAnfrage): LlmAntwort` – ein Aufruf,
  keine Deutung des Textes. `OpenAiCompatibleClient` baut den Request über
  `ChatHttp`: System-Nachricht, eine User-Nachricht (Text + Seitenbilder als
  `data:image/jpeg;base64,…`, nur bei `vision`, höchstens `max_images`; nur
  JPEG), danach der Verlauf der Reparatur; `max_completion_tokens` aus dem
  Profil (aktueller Name bei OpenAI, auch Anthropics kompatibler Endpunkt;
  llama.cpp prüft M7-1c), **kein** `temperature`; Timeout = Profil. Mit Schema
  und `json_schema`: `response_format` `{type: json_schema, json_schema:
  {name, strict: true, schema}}`; ohne: Schema per
  `app/prompts/schema-im-prompt-v1.md` in die System-Nachricht.
- Fehler ohne verwertbare Antwort sind `LlmFehler` mit `KiAufrufStatus`
  (`zeitueberschreitung`, `verbindungsfehler`, `http_fehler`,
  `unerwartete_antwort` – 2xx ohne Chat-Completion mit Text, auch
  `refusal`); die Meldung ist deutsch und ohne Anbietertext (der steht
  gekürzt und ohne Key getrennt in `anbieterText`). HTTP-Hinweise und
  Key-Bereinigung teilt sich der Client mit dem Verbindungstest
  (`KiAnbieterText`).
- `KiAbfrage` macht daraus validierte Daten: `fragen()` (ein Aufruf; das
  erste JSON-Objekt der Antwort – ganz, in einem Code-Block oder in Prosa,
  Klammern in Strings zählen nicht, nie ein inneres Objekt einer kaputten
  oder abgeschnittenen Hülle), `reparieren()` (genau **ein** weiterer Aufruf,
  nur bei `kein_json`/`schema_verletzt`: derselbe Verlauf inkl. Seiten und
  `response_format`, dazu die alte Antwort als `assistant` und
  `app/prompts/reparatur-v1.md` mit der Fehlerliste), `ausfuehren()` = beides
  nacheinander → `KiErgebnis` (ungültig = „KI fehlgeschlagen“, M7-5 setzt
  `ki_fehler`). Zwei Methoden, weil jeder Aufruf in der Job-Kette ein eigener
  kurzer Request ist (CLAUDE.md §1, 06 „DB-Verbindung“); den ersten Versuch
  hält der Job dazwischen verschlüsselt.
- `KiSchema` validiert in PHP – eigener Code statt Bibliothek, genau die
  Teilmenge von JSON Schema, die OpenAI im Strict-Modus durchsetzt: `type`
  (auch mit `null`), `properties`, `required`, `additionalProperties`
  (boolesch), `items`, `enum`, `pattern`, `minimum`/`maximum`,
  `minItems`/`maxItems`, `description`; jedes andere Schlüsselwort wird beim
  Laden abgewiesen. Fehler nennen Ort und Regel (`/supplier/iban: …`), nie
  den Wert; höchstens 20.
- Protokoll je Aufruf als `KiAufruf` (Anbieter, Modell, Prompt-Version,
  Reparatur ja/nein, Status, Tokens, Dauer, HTTP-Status, Rohantwort) – nur
  zurückgegeben; speichern tun M7-5 (Rohantwort verschlüsselt am Beleg) und
  M7-8 (KI-Protokoll).
- Prompts und Schemas liegen unter `app/prompts/` (`KiPrompts`,
  `Paths::promptsDir()`); `{{platzhalter}}` müssen alle und genau gefüllt
  werden.

**Umsetzung (M7-2b, issue #180, Migration 030):** das optionale
**Fallback-Profil**. Fällt das Standardprofil aus, fragt der Client **einmal**
das Fallback-Profil.
- *Daten und Regeln:* `ai_provider.is_fallback` (höchstens eine Zeile, per
  CHECK nie zugleich `is_default`; kein Unique-Index aus demselben Grund wie
  bei `is_default`, `AiProviderRepository::setFallback()` schaltet in *einem*
  Statement um). `KiAnbieterService::alsFallback()` verlangt: nicht der
  Standard, aktiv, mit Key. Die Wahl entfällt von selbst – und die Seite
  sagt es –, wenn das Profil gelöscht wird (gleiche Zeile), Standard wird,
  deaktiviert wird oder seinen Key verliert. `AiProviderRepository::fallback()`
  liefert nur ein *nutzbares* Fallback-Profil (eine von Hand geänderte Zeile
  darf den Client nicht täuschen).
- *Admin:* `/admin/ki-anbieter` (`admin.settings`) zeigt die Marke
  „Fallback“ und je nutzbarem Nicht-Standard-Profil „Als Fallback“, am
  Fallback-Profil „Fallback entfernen“ (`POST …/{id}/fallback`,
  `POST …/fallback-entfernen`, CSRF). Audit: `ki_anbieter.fallback_gewaehlt`
  (Name, ggf. `ersetzt`), `ki_anbieter.fallback_entfernt`; Löschen, Standard
  setzen und Ändern vermerken im Audit, dass dabei die Fallback-Wahl entfiel
  (`war_fallback`, `fallback_entfernt`) – nie ein Key.
- *Client:* Ausfall = `KiAufrufStatus::ausfall()`: `zeitueberschreitung`,
  `verbindungsfehler`, `http_fehler` mit HTTP **429 oder 5xx**. Nicht: 4xx
  (Key oder Anfrage falsch – das Fallback bekäme dieselbe), 2xx ohne
  Chat-Antwort, Antworten, die ankommen aber nicht passen (Reparatur-Weg).
  `KiVersuch::fallbackMoeglich()` gilt nur für den **ersten** Aufruf einer
  Kette – ein gescheiterter Reparatur-Call oder ein Fallback-Versuch selbst
  weicht nie weiter aus. `KiAbfrage::fallbackFragen()` ist ein **eigener
  Schritt** der Job-Kette (nie zwei lange Aufrufe in einem Request; derselbe
  Verlauf wie `fragen()`, mit dem Key des Fallback-Profils; `LogicException`
  bei unpassendem Vorgänger, gleichem oder unbrauchbarem Profil). Passt die
  Antwort des Fallbacks nicht, folgt dessen *eigener* Reparatur-Call
  (`reparieren()` mit dem Fallback-Profil) – höchstens **drei** Aufrufe je
  Auslesen (`KiErgebnis`). `ausfuehren()` nimmt optional Fallback-Profil und
  -Key. Der Job (M7-5) holt beides aus `AiProviderRepository::fallback()`/
  `apiKey()`; die Fachlogik bleibt framework-frei.
- *Protokoll:* `KiAufruf` nennt mit `anbieterId`/`anbieterName`/`modell` das
  tatsächlich gefragte Profil, `fallback = true` kennzeichnet den
  Ausweich-Aufruf; `KiErgebnis::meldung()` hängt „Das Fallback-Profil wurde
  verwendet.“ an.

**Umsetzung (M7-8, issue #50, Migration 032):** das **KI-Protokoll**.
- *Daten:* `ai_call_log` (Betriebsdaten im Klartext, kein Tresor): Zeitpunkt,
  Anbieter (ID + kopierter Name/Modell), Prompt-Version, Reparatur/Fallback,
  Status (`KiAufrufStatus`, das „Ergebnis“), Tokens, Dauer, HTTP-Status –
  **nie** Anfrage, Antwort oder ein Wert vom Beleg (die Rohantwort bleibt
  verschlüsselt im `extraction`-Artefakt). `KiAuslesung::nachAufruf()` schreibt
  die Zeile über `AiCallLogRepository`, *nachdem* der Versuch gespeichert ist
  (ein wiederholter Schritt protokolliert nichts doppelt). „Verbindung testen“
  protokolliert weiterhin nichts.
- *Ansicht:* `GET /admin/ki-protokoll` (`admin.settings`, Seite im
  `KiAnbieterController`, Nav-Eintrag „KI-Protokoll“): je Anbieter eine Karte
  mit Summen der letzten 30 Tage (Aufrufe, gültig/Fehler, Tokens,
  Ø-Dauer) und den neuesten 25 Aufrufen. Aufrufe gelöschter Profile erscheinen
  unter ihrem kopierten Namen mit dem Zusatz „(Profil gelöscht)“.
- *Offen/vorläufig:* keine automatische Bereinigung alter Zeilen (ein Verein
  erzeugt wenige tausend Zeilen im Jahr).

### Auslese-Schema (Prompt-Version `extract-v1`)

```json
{
  "document_type": "rechnung|quittung|gutschrift|kassenbon|mahnung|lieferschein|sonstiges",
  "direction": "ausgabe|einnahme",
  "supplier": { "name": "", "address": "", "iban": "", "bic": "", "vat_id": "",
                "tax_number": "", "email": "", "website": "", "creditor_id": "" },
  "invoice_number": "", "customer_number": "", "contract_number": "",
  "invoice_date": "YYYY-MM-DD|null", "due_date": "YYYY-MM-DD|null",
  "service_period": { "from": "YYYY-MM-DD|null", "to": "YYYY-MM-DD|null" },
  "currency": "EUR",
  "total_gross": "123.45|null", "total_net": "103.74|null",
  "taxes": [ { "rate": "19", "amount": "19.71" } ],
  "payment": { "already_paid": true, "method": "bar|karte|lastschrift|ueberweisung|paypal|unbekannt",
               "mandate_reference": "" },
  "purpose_short": "Ein Satz: worum geht es (für Vereinszwecke)",
  "category_id": 7,
  "sphere": "nur wenn sphaeren_aktiv: ideell|vermoegensverwaltung|zweckbetrieb|wirtschaftlich",
  "cost_center_id": null,
  "recurring": { "likely": true, "period": "monat|quartal|halbjahr|jahr|null", "evidence": "Abschlag Mai" },
  "confidence": { "supplier": 0.0, "total_gross": 0.0, "invoice_date": 0.0, "category": 0.0 },
  "warnings": [ "z. B. Betrag schwer lesbar" ]
}
```

- Formal: `app/prompts/extract-v1.schema.json` (M7-2) – strict-tauglich:
  jedes Objekt nennt alle Schlüssel in `required` und erlaubt keine weiteren,
  Optionales ist `null`; Datum `JJJJ-MM-TT`, Beträge
  `^-?[0-9]+(\.[0-9]{1,2})?$` (Punkt, keine Tausenderpunkte), Steuersatz in
  Prozent, Konfidenzen 0–1, Enums wie oben. Unbekannte Texte des Lieferanten
  sind `""`.
- **Nicht lesbar → `null` (bzw. `""`) + Eintrag unter `warnings`, nie
  raten** – auch Belegdatum und Bruttobetrag (seit M7-2 nullable); die
  Prüfansicht lässt das Feld dann leer.
- Beträge als Dezimal-String → serverseitig in Cent geparst und geprüft
  (Summe Netto + Steuern ≈ Brutto, sonst Warnung).
- Der Prompt enthält: Kategorienliste mit `ai_hint` (passend zur
  Richtung), Kostenstellen, Sphären-Erklärung nur bei `sphaeren_aktiv`, **Freitext des Einreichers**, bekannte Lieferanten
  nur als Top-Kandidaten (per Blind-Index-Vorauswahl, max. 10 Namen) – nie
  die komplette Lieferantenliste.
- Prompts liegen versioniert unter `app/prompts/*.md`, nicht im Code.

**Umsetzung (M7-5, issue #47):** `App\Service\Processing\KiAuslese\Auslesung`
(framework-frei, Ablauf des Jobs: Abschnitt 5).
- *Prompt:* System-Nachricht `app/prompts/extract-v1.md` (Regeln: nichts
  raten, Formate, Richtung, Lieferant = Aussteller, `sphere` immer null;
  `{{kategorien}}` als „Nummer: Name (Richtung) – ai_hint“ aller aktiven
  Kategorien, `{{kostenstellen}}`, `{{lieferanten}}`), der Beleg in
  `app/prompts/extract-v1-beleg.md` (`{{freitext}}`, `{{inhalt}}`: Felder der
  E-Rechnung als JSON, Textlayer seitenweise, höchstens 60 000 Zeichen, bzw.
  ein Satz zu den beiliegenden Seitenbildern). Freitext (höchstens
  2000 Zeichen) und Beleg stehen zwischen Marken (`<<<FREITEXT …
  FREITEXT>>>`, `<<<BELEG … BELEG>>>`), die die Daten selbst nicht schließen
  können (`<<<`/`>>>` darin werden entschärft); der Prompt sagt, dass das
  Daten und keine Anweisungen sind – der Freitext kommt aus der öffentlichen
  Einreichung.
- *Lieferanten-Vorauswahl* (seit M7-6, issue #48,
  `SupplierAufloesung::vorauswahl()`): Aus Textlayer und Freitext liest
  `App\Service\MasterData\LieferantenKandidaten` alles, was wie IBAN, USt-ID,
  Steuernummer oder Gläubiger-ID aussieht, und jede Folge von 1–4 Wörtern einer
  Zeile als Name (die ersten 20 000 Zeichen), normalisiert wie die
  `supplier_key`-Schlüssel; dazu Kennungen und Name des Verkäufers einer
  E-Rechnung. Deren Blind Indexes werden gesammelt nachgeschlagen
  (`SupplierRepository::idsMitSchluesseln()`); in `{{lieferanten}}` stehen die
  **Namen** von höchstens 10 Treffern der Rolle `lieferant`/`beide` (nie Zahler –
  das können Privatpersonen sein; Datensparsamkeit) – über eine Kennung gefundene zuerst, dann
  nach Fundstelle –, je höchstens 100 Zeichen, einzeilig und ohne Marken, mit
  dem Hinweis, dass es Daten sind und der Name bei einem Treffer genau so in
  `supplier.name` gehört (ein Name kann aus Stufe 6 einer öffentlichen
  Einreichung stammen). Ohne Treffer bzw. ohne Text (Foto) bleibt es bei
  „Keine Vorauswahl.“. Gesammelt in der Session (`AusleseEingabe::$lieferanten`)
  – der Blind-Index-Schlüssel kommt aus dem Tresor.
- *Sphären:* `sphaeren_aktiv` gibt es noch nicht (E-15) – der Prompt erklärt
  keine Sphären, `sphere` wird serverseitig immer null.
- *Server-Prüfungen* (`Auslesung::auswerten()`, nach der Schema-Prüfung):
  Beträge mit `Processing\Betrag` in Cent geparst (unbrauchbar → null +
  Hinweis), Summenprüfung Netto + Steuern ≈ Brutto (Hinweis, Wert bleibt),
  Daten, die es nicht gibt (2026-02-30) → null + Hinweis, Kategorie nicht
  angeboten bzw. nicht zur Richtung passend → null + Hinweis, unbekannte
  Kostenstelle → null, nicht gesendete Seiten bzw. gekürzter Text → Hinweis.
  Hinweise nennen Felder, nie Werte.

### Prüfansicht
- Links Beleg (Seiten blätterbar, Zoom), rechts Formular mit den
  ausgelesenen Feldern; Felder mit Konfidenz < 0.7 gelb markiert,
  KI-Warnungen oben. Tastatur-freundlich (Tab-Reihenfolge, Enter =
  „Geprüft, nächster Beleg").

**Stand M7-5** (issue #47): die KI belegt das Formular vor.
- *Reihenfolge* (nur ohne gespeicherten Beleg, `PruefungController::formular`):
  KI-Vorschlag (`KiAuslesung::auszug()`, neuester Lauf), darüber eine gelesene
  E-Rechnung (sie gewinnt bei allem, was sie kennt; die KI ergänzt Kategorie,
  Zweck, Kostenstelle), darüber die Archiv-Vorgabe (Quelle in den Hinweisen
  „KI“ bzw. „E-Rechnung“). Alles über `Pruefung::vorbelegen()`, das seit M7-5
  auch `category_id` (aktiv, zur Richtung passend) und `cost_center_id` (nur
  wenn das Dokument keine hat – sie bestimmt den Scope) übernimmt und beim
  Lieferanten nie Auswahl und „Neu anlegen“ zugleich belegt.
- *Konfidenz:* `supplier` → Lieferant bzw. „Neu anlegen: Name“, `total_gross`
  → Brutto, `invoice_date` → Belegdatum, `category` → Kategorie; unter 0,7
  (`AusleseVorschlag::KONFIDENZ_GRENZE`) bekommt das Feld die Klasse
  `feld-unsicher` (gelb) und im Label die Marke „KI unsicher“ – nur, wenn die
  KI es tatsächlich gefüllt hat und nichts darübergelegt wurde.
- *Oben:* Hinweis „Vorschlag der KI (Profil, Modell)“ samt den `warnings` der
  KI und den Server-Hinweisen (gelb, sobald es Warnungen oder unsichere Felder
  gibt); bei `ki_fehler` die Meldung und „KI erneut versuchen“; bei einem Beleg
  in `bereit_zur_auswertung` ohne Ergebnis „Mit KI auslesen“ bzw. „Die KI liest
  diesen Beleg gerade aus“.
- *Lieferant* (seit M7-6, issue #48): über KI und E-Rechnung legt sich das
  Ergebnis von `resolve_supplier` (`Pruefung::lieferantVorbelegen()`, Abschnitt 7
  „Stand M7-6“), darüber weiterhin die Archiv-Vorgabe.
- *„KI erneut versuchen“:* `POST /app/belege/pruefen/{id}/ki-erneut`
  (`document.edit` mit Scope, CSRF, Tresor), `Pruefung::kiErneut()`:
  `ki_fehler` → `bereit_zur_auswertung` und Job einreihen, aus
  `bereit_zur_auswertung` nur einreihen; abgelehnt ohne nutzbares KI-Profil,
  bei laufendem Lauf und in jedem anderen Status. Audit `beleg.ki_erneut`
  (Details: `von`).
- Gespeichert wird erst durch die Person (CLAUDE.md §6).

**Stand M6-3** (issue #37, vollständig manuell – M7-5 belegt dasselbe
Formular nur vor): `App\App\PruefungController`, Regeln und Verschlüsselung
in `App\Service\Invoice\Pruefung`, Tabelle `invoice` (02 „Fachdaten“,
Migration 019), Recht `document.edit` (Admin, Finanzen) samt dessen Scope.
- **Routen:** `/app/belege/pruefen` ist die Warteschlange (alle Belege, aus
  deren Status `in_pruefung` erreichbar ist, älteste zuerst – nur
  Klartextspalten, Navigationseintrag „Belege prüfen“);
  `/app/belege/pruefen/{id}` das Formular, `POST` dorthin speichert
  (`aktion=speichern`) oder schließt ab (`aktion=geprueft`);
  `…/datei/{blob}` streamt eine Seite wie der Posteingang.
- **Felder:** Richtung (Ausgabe/Einnahme), Belegart, Belegdatum (Pflicht),
  Belegnummer, Brutto (Pflicht), Währung (Standard EUR), Netto und bis zu
  drei Steuerzeilen (Satz, Betrag), Lieferant bzw. Zahler, Kategorie,
  Kostenstelle, Fälligkeit, Leistungszeitraum, Zweck, Notiz. Neu belegt:
  Ausgabe, Rechnung, EUR und die Kostenstelle des Dokuments.
- **Beträge** werden mit `App\Service\Processing\Betrag` in Cent geparst
  (rahmenwerkfrei, auch für M7-5): deutsch „1.234,56“ und englisch
  „1234.56“, Vorzeichen vorn oder hinten (Kassenbon „12,50-“), höchstens
  zwei Nachkommastellen; „1,234“ ist mehrdeutig und wird abgelehnt statt
  geraten. Die Summenprüfung (Netto + Steuern = Brutto, 1 Cent Toleranz je
  Steuerzeile) **warnt nur**, sie blockiert nicht.
- **Passend zur Richtung:** Kategorie `ausgabe`/`einnahme` bzw. `beide`,
  aktiv (außer der schon gespeicherten); Partner mit Rolle
  `lieferant`/`zahler` bzw. `beide`, nicht zusammengeführt. Ohne JavaScript
  bietet die Seite alles gruppiert an, `public/js/pruefansicht.js` blendet
  die unpassenden Gruppen aus – geprüft wird serverseitig.
- **Lieferant bzw. Zahler neu anlegen** direkt im Formular (Name, optional
  IBAN) über `SupplierService::anlegen()` mit der Rolle aus der Richtung –
  nur mit `supplier.manage`, und nur statt, nicht neben einer Auswahl.
  Anlegen läuft vor der Transaktion des Belegs (eigene Transaktion des
  Lieferanten-Service); eine doppelte IBAN verweist auf den vorhandenen.
- **Kostenstelle** des Belegs wird in `document.cost_center_id`
  übernommen – daran hängt der Scope „eigene Kostenstelle“ des Posteingangs.
- **Beleganzeige:** Bilder (aufbereitete Fassung, Original einen Klick
  entfernt) und die Seitenbilder der PDF-Rasterung (M4-8, jeweils der
  neueste Lauf) blätterbar mit Zoom-Stufen 100/150/200/300 %; PDFs selbst
  als Link (CSP). Ab 64rem nebeneinander (Beleg mitlaufend), darunter
  gestapelt mit eigenem Bildlauf – auf 360 px geprüft.
- **Tastatur:** Felder in Lesereihenfolge, erster Absende-Knopf ist
  „Geprüft, nächster“ (Enter), danach „Nur speichern“ und „Überspringen“.
  „Nächster“ ist der folgende Beleg der Warteschlange, am Ende wieder von
  vorn; ist keiner mehr offen, geht es zurück zur Liste.
- **Audit** `beleg.bearbeitet` (Details: nur die **Namen** geänderter
  Felder und ggf. der Ausgangsstatus; Speichern ohne Änderung schreibt keine
  Zeile) und `beleg.geprueft`; ein neu angelegter Partner zusätzlich
  `lieferant.angelegt`.

**Stand M6-4** (issue #38, Details 01 §7): zwei weitere Aktionen der
Prüfansicht. `POST …/festschreiben` (Beleg `geprueft` → `festgeschrieben`)
und `POST …/festschreibung-aufheben` (Pflicht-Grund, → `in_pruefung`),
beide `document.edit` mit Scope, in `App\Service\Invoice\Festschreibung`.
Auf `/app/belege/pruefen` steht unter der Warteschlange der Abschnitt
„Geprüft – bereit zum Festschreiben“; ein festgeschriebener Beleg ist in der
Prüfansicht nur lesbar und zeigt „Festschreibung aufheben“. Audit
`beleg.festgeschrieben` und `beleg.festschreibung_aufgehoben` (Details: nur
der Grund). Tests: `PruefungFlowTest` (Abschnitt Festschreibung),
`DocumentStatusTest`.

**Pflicht-Tests M6-3:** `BetragTest` (Parsing deutsch/englisch/negativ,
Ablehnung Mehrdeutiges, Formatierung, Summenprüfung, Steuersatz);
`DocumentStatusTest` (neue Übergänge, `pruefbare()`); `PruefungFlowTest`
(Warteschlange älteste zuerst, Formular mit Seiten und Seitenbildern,
Enter = „Geprüft, nächster“, verschlüsselt gespeichert – kein Klartext in
`invoice`/`audit_log`, Blind Index nachgerechnet, Audit nur Feldnamen,
nächster Beleg inkl. Umlauf, geprüft nur lesbar, nicht freigegebener
Beleg, KI-Fehler manuell erfassbar, Summen-Warnung, Validierung je Feld,
Richtung ↔ Kategorie/Partner, Partner neu anlegen inkl. Rechteprüfung und
doppelter IBAN, Rollen, Zeitraum-Scope, veralteter Stand wird nicht
überschrieben, Verwendungszähler und FKs, Spaltenliste);
`RoutePermissionMatrixTest`; `tests/js/pruefansicht.test.js`.

## 7. Lieferanten

- Auflösung in `resolve_supplier` in fester Reihenfolge:
  1. USt-ID / Steuernummer (Blind Index) exakt
  2. IBAN exakt
  3. Gläubiger-ID
  4. normalisierter Name (Kleinschreibung, Rechtsform entfernt „GmbH &
     Co. KG", Umlaute vereinheitlicht) exakt
  5. Ähnlichkeit (Trigramm/Levenshtein) gegen die entschlüsselten Namen der
     Kandidaten ≥ Schwelle → **Vorschlag**, nicht automatisch
  6. sonst **automatisch neu anlegen** mit `created_via=ki`,
     `needs_review=1` (Liste „Neue Lieferanten prüfen")
- Neu gelernte Merkmale (weitere IBAN, Alias-Name) werden dem Lieferanten
  nach Bestätigung hinzugefügt – umgesetzt mit M7-6b, „Stand M7-6b“.
- Zusammenführen doppelter Lieferanten (alle Belege/Regeln umhängen,
  `merged_into` setzen, Audit). **Festgeschriebene Belege** (01 §7) sind
  unveränderlich und werden nicht umgehängt, sondern über `merged_into`
  aufgelöst – Details unter „Stand M6-5“.
- Lieferant hat Default-Kategorie/-Sphäre; ab dem 2. bestätigten Beleg mit
  gleicher Kategorie wird sie automatisch vorgeschlagen (Regel vor KI) –
  umgesetzt mit M7-7, §8 „Stand M7-7“.
- **Stand M6-2** (issue #36): Stammdaten-Pflege unter `/app/lieferanten`
  (Lieferanten und Zahler, Spalte `role`), Blind-Index-Schlüssel in
  `supplier_key` für Name/Aliasse, IBAN, USt-ID, Steuernummer, Gläubiger-ID
  und Mandatsreferenz, Normalisierung aus Stufe 4 in
  `App\Service\MasterData\SupplierKeys` – die Stufen 1–4 sind damit reine
  Nachschlagevorgänge. IBAN/USt-ID/Steuernummer/Gläubiger-ID sind eindeutig
  je Lieferant. Details: 02 „Lieferanten“.
- **Stand M6-5** (issue #39): Zusammenführen unter
  `/app/lieferanten/{id}/zusammenfuehren?ziel=…` (Vorschau, `GET`) und
  `POST` dorthin (Ausführen), Recht `supplier.manage`, CSRF, Tresor
  entsperrt. Der Lieferant `{id}` (Quelle) geht im Ziel auf; die Vorschau
  zeigt das Ergebnis, welche Angaben sich ändern und was mit den Belegen
  passiert („Andersherum zusammenführen“ tauscht die Richtung). Regeln
  (`App\Service\MasterData\SupplierMerge`, reine Funktion):
  - Name und Aliasse der Quelle werden Aliasse des Ziels; IBANs und
    Mandatsreferenzen werden vereinigt. Mehr als 20 je Feld → Ablehnung.
  - USt-ID, Steuernummer, Gläubiger-ID: das Ziel übernimmt den Wert der
    Quelle, wenn es keinen hat; zwei **verschiedene** Werte → Ablehnung
    (kein Duplikat oder ein Wert falsch – erst korrigieren).
  - Übrige Einzelwerte (Anschrift, BIC, E-Mail, Website, Kundennummer):
    das Ziel gewinnt, ein abweichender Wert der Quelle wird – wie deren
    Notiz – an die Notiz des Ziels angehängt („Übernommen von …“). Notiz
    länger als 2000 Zeichen → Ablehnung. So geht nichts verloren.
  - Verschiedene Rollen → `beide`; Standard-Kategorie des Ziels, sonst die
    der Quelle. `created_via`/`needs_review` bleiben die des Ziels.
  - Ablauf (`App\Service\MasterData\SupplierZusammenfuehrung`) in **einer**
    Transaktion: beide Zeilen `FOR UPDATE` (kleinere ID zuerst), im Lock
    neu entschlüsselt und geplant, Ziel neu verschlüsselt samt
    `supplier_key` (Eindeutigkeit gegen alle Dritten geprüft), offene
    Belege umgehängt (`InvoiceRepository::haengeLieferantUm()` mit
    `locked_at IS NULL`), Quelle `merged_into` = Ziel, Lieferanten, die
    früher in die Quelle zusammengeführt wurden, zeigen direkt aufs Ziel
    (`merged_into` ist nie mehr als ein Schritt), Schlüssel der Quelle
    gelöscht, Audit-Eintrag.
  - **Festgeschriebene Belege** behalten `supplier_id` der Quelle; die
    Zeile der Quelle bleibt deshalb bestehen (FK `RESTRICT`). Angezeigt
    wird über `SupplierRepository::aufgeloest()` das Ziel (Prüfansicht);
    nach „Festschreibung aufheben“ speichert die Prüfansicht das Ziel.
    Wer später nach Lieferant filtert oder auswertet (M11), löst ebenso
    über `merged_into` auf.
  - Ein zusammengeführter Lieferant hat keine eigene Seite mehr (Weiterleitung
    aufs Ziel), lässt sich weder ändern noch löschen und ist keine Auswahl
    in der Prüfansicht. Nicht mit sich selbst, nicht in einen bereits
    zusammengeführten.

- **Stand M7-6** (issue #48, Migration 035; vorläufige Details E-23): Job
  `resolve_supplier` (`App\Service\Document\LieferantenAufloesung`, Recht
  `document.edit`, Executor `session`), Stufen in
  `App\Service\MasterData\SupplierAufloesung::aufloesen()`:
  - *Eingabe:* der neueste KI-Vorschlag (`supplier`, `direction`,
    `payment.mandate_reference`), darüber jeder Wert des Verkäufers einer
    gelesenen E-Rechnung. Übersprungen, wenn der Beleg nicht (mehr)
    `ausgewertet` ist oder die Archiv-Vorgabe schon einen Lieferanten nennt.
  - *Rolle:* es zählen nur Partner, deren Rolle zur Richtung passt
    (`InvoiceDirection::passtZuLieferant()`). Trifft eine Kennung (Stufen
    1–3) nur einen Partner der anderen Rolle, dürfen die folgenden Kennungen
    noch den passenden finden; findet keine ihn – oder trifft der Name (Stufe
    4) nur die andere Rolle –, endet die Auflösung mit „Rolle passt nicht“ – keine Wahl, keine Neuanlage (die Kennung gehört
    schon ihm). Mehrere gleichnamige Partner der Rolle (Stufe 4) →
    „mehrdeutig“, ebenso ohne Wahl und Neuanlage.
  - *Stufe 5:* `App\Service\MasterData\LieferantenAehnlichkeit` – das
    Maximum aus Levenshtein (je Buchstabe, nicht Byte) und Trigramm-Dice der
    normalisierten Namen, gegen Name und Aliasse aller Partner der Rolle;
    ab 0,8 ein Vorschlag (höchstens drei, der beste zuerst); unter 4
    Buchstaben nur Gleichheit.
  - *Stufe 6:* `SupplierService::anlegenAusKi()` mit Name, Anschrift, IBAN,
    BIC, USt-ID, Steuernummer (beide seit #245 nur mit gepflegten
    Vereinskennungen und nie die des Vereins, „Stand #245“), E-Mail, Website,
    Gläubiger-ID (Mandatsreferenz
    nur mit Gläubiger-ID) – Werte, die das Formular ablehnen würde, fallen weg,
    ein zu langer Name wird gekürzt; Rolle aus der Richtung, keine
    Standard-Kategorie, `created_via = ki`, `needs_review = 1`. Audit
    `lieferant.angelegt` in derselben Transaktion, ohne Benutzer, Details `{herkunft: ki, beleg, felder}`.
  - *Ergebnis* (`App\Service\MasterData\Aufloesung`, Schema `lieferant-v1`):
    `{art, stufe, merkmal, supplier_id, kandidaten[{id, aehnlichkeit}], felder}` –
    nur IDs –, als `document_artifact` `kind = lieferant` (seq 0) unter eigenem
    DEK, in **einer** Transaktion mit einer Neuanlage; ältere Läufe werden
    gelöscht. Ein gespeichertes Ergebnis beendet einen wiederholten Schritt;
    ein neuer Lauf (z. B. nach „KI erneut versuchen“) findet den angelegten
    Lieferanten in Stufe 4 wieder.
  - *Gleichzeitig:* mit Neuanlage liest die Auflösung die Schlüssel mit
    `FOR UPDATE` (`idsWithKey(sperren: true)`); verliert ein paralleler Lauf
    (Deadlock/Sperr-Timeout bzw. Kennung inzwischen vergeben), rollt er
    zurück und wartet 5 s (`warten`), nach zwei Versuchen löst er ohne
    Neuanlage auf; jeder andere Fehler ist ein Jobfehler.
  - *Prüfansicht* (`Pruefung::lieferantVorbelegen()`): Treffer und Neuanlage
    werden gewählt, ein Vorschlag gewählt **und** gelb markiert („KI
    unsicher“); unter der Auswahl steht, wie der Partner gefunden wurde
    („Lieferant automatisch erkannt (Stufe 2 – IBAN).“, „Kein genauer Treffer
    (Stufe 5) – Vorschlag nach ähnlichem Namen: „…“ (93 %)“, „Die KI hat „…“
    neu angelegt (Stufe 6 …) – bitte unter „Neue Lieferanten prüfen“
    kontrollieren.“ mit Link, Rolle passt nicht, mehrdeutig – diese beiden
    ohne Wahl und ohne „Neu anlegen“-Vorbelegung). Ein inzwischen
    gelöschter oder unpassender Partner wird nicht gewählt; ein
    zusammengeführter steht für sein Ziel. Der Hinweis entfällt, wenn die
    Archiv-Vorgabe einen anderen Partner wählt.
  - *„Neue Lieferanten prüfen“:* `/app/lieferanten?pruefen=1` (dieselbe Liste,
    nur `needs_review = 1`, mit „Als geprüft markieren“ je Zeile); die normale
    Liste nennt die Anzahl und verlinkt dorthin. `POST
    /app/lieferanten/{id}/geprueft` (`supplier.manage`, CSRF, Tresor) setzt
    `needs_review = 0`, Audit `lieferant.geprueft` (nur beim ersten Mal).
    Speichern des Formulars gilt ebenfalls als geprüft (geänderte Felder dann
    inkl. `needs_review`); Zusammenführen lässt das Flag des Ziels.
  - *Noch offen:* „neu gelernte Merkmale … nach Bestätigung hinzugefügt“ (E-23)
    – umgesetzt mit M7-6b, siehe unten.

- **Stand M7-6b** (issue #201, keine Migration; vorläufige Details E-29):
  Bestätigt eine Person den Lieferanten, den die Prüfansicht gewählt hat,
  lernt er die neuen Merkmale des Belegs.
  - *Quelle:* nur die Werte des Belegs – der neueste KI-Vorschlag
    (`supplier`, `payment.mandate_reference`), darüber jeder Wert des
    Verkäufers einer gelesenen E-Rechnung (dieselbe Überlagerung wie
    `resolve_supplier`, `LieferantenAufloesung::ueberlagern()`), beim
    Speichern **neu aus den Artefakten gelesen**, nie aus den Formularfeldern
    (Freitext, „Neu anlegen“).
  - *Regeln* (`App\Service\MasterData\LieferantenMerkmale::neue()`, reine
    Funktion; Ergebnis `Merkmale`): **IBAN** gültig und noch nicht beim
    Lieferanten; **Name** als Alias, wenn seine normalisierte Form (Stufe 4)
    weder Name noch Alias ist; **Gläubiger-ID** gültig und nur, wenn der
    Lieferant keine hat (eine andere → Hinweis, nicht ersetzt);
    **Mandatsreferenz** nur zusammen mit dieser Gläubiger-ID. Höchstens 20 je
    Liste, sonst Hinweis. USt-ID: seit #245 wie die Gläubiger-ID,
    Steuernummer weiterhin nicht (siehe „Stand #245“).
  - *Vereinskonten* (`SupplierService::istVereinsIban()`, Blind Index
    `bank_account.iban`): eine IBAN eines eigenen Kontos des Vereins ist nie
    die eines Lieferanten – bei Lastschrift-Rechnungen und Einnahmen steht oft
    nur sie auf dem Beleg. Sie wird nicht gelernt („ein Konto des Vereins –
    nicht übernommen“), bei der Stufe-6-Neuanlage weggelassen und in Stufe 2
    der Auflösung (wie in der Vorbelegung über Kennungen) ignoriert.
  - *Eindeutigkeit* (`SupplierService::lernbar()`): eine IBAN oder
    Gläubiger-ID, die schon bei einem **anderen** Lieferanten liegt, wird
    nicht gelernt, ebenso kein Name, der Name oder Alias eines anderen ist
    (Stufe 4 würde für ihn mehrdeutig); die Seite nennt ihn („bitte
    zusammenführen“).
  - *Anzeige vor dem Speichern:* unter der Lieferanten-Auswahl ein
    Ankreuzfeld „Neue Merkmale beim Lieferanten „…“ ergänzen“,
    **vorausgewählt**, mit der Liste der Werte und den Hinweisen; dazu das
    versteckten Felder `lieferant_lernen_fuer` (ID) und
    `lieferant_lernen_stand` (Fingerabdruck des gezeigten Plans, HMAC mit
    Tresor-Schlüssel, `Merkmale::fingerabdruck()`). Angeboten nur, wenn der
    Beleg noch nicht gespeichert ist, der Lieferant aus KI/E-Rechnung/
    Auflösung gewählt wurde (nicht aus der Archiv-Vorgabe) und die Person
    `supplier.manage` hat. `pruefansicht.js` blendet es aus, sobald ein
    anderer Partner gewählt wird.
  - *Speichern* (`Pruefung::speichern()`): gelernt wird nur, wenn angekreuzt,
    beim **ersten** Speichern, mit `supplier.manage`, ohne „Neu anlegen“ und
    wenn der gespeicherte Lieferant **genau** `lieferant_lernen_fuer` ist –
    ein durch einen anderen ersetzter Vorschlag lernt nichts. Nach dem
    Commit des Belegs in **eigener** Transaktion
    (`SupplierService::merkmaleErgaenzen()`): Zeile `FOR UPDATE`, neu
    entschlüsselt und neu geplant (Schlüssel anderer Lieferanten mit
    `FOR UPDATE` gelesen, damit zwei Speichervorgänge nicht dieselbe IBAN
    zwei Lieferanten beibringen) – gelernt wird nur, wenn der Plan noch
    **genau** dem gezeigten entspricht (Fingerabdruck) –, unter dem
    Zeilen-DEK neu verschlüsselt, `supplier_key` neu geschrieben,
    Eindeutigkeit erneut geprüft; `needs_review` bleibt. Audit
    `lieferant.geaendert` (Entität Lieferant) mit `{felder, beleg}` – nur
    Feldnamen wie beim Formular (`iban`, `aliases`, `creditor_id`,
    `mandate_refs`) und Beleg-ID. Ein geänderter Plan oder ein verlorenes
    Sperren (Deadlock/Timeout) verwirft nur das Lernen (Hinweis), nicht den
    Beleg.
    Die Meldung nennt nur Feldbezeichnungen („Beim Lieferanten ergänzt: IBAN.“).
  - *Unberührt:* festgeschriebene Belege (Speichern ist dort gesperrt) und
    zusammengeführte Lieferanten (weder wählbar noch lernbar).

- **Stand #245** (Migration 046; vorläufige Details E-67): ergänzt M7-6b.
  - *USt-ID* wird gelernt wie die Gläubiger-ID: gültig (Formular-Muster
    `SupplierService::USTID_MUSTER`) und **nur, wenn der Lieferant keine
    hat**; eine abweichende wird gemeldet, nicht ersetzt (eher ein
    Lesefehler). Verglichen wie ihr Schlüssel (`SupplierKeys::kennung()`),
    Feld im Audit `vat_id`, Eindeutigkeit gegen andere Lieferanten wie bei
    IBAN/Gläubiger-ID (`lernbar()`, Blind Index nur in der Session).
    **Keine Steuernummer:** dieselbe Nummer steht lokal („FF/BBB/UUUUP“) oder
    13-stellig (ELSTER, mit Länderkennziffer) auf Belegen – weder die eigene
    des Vereins noch die eines anderen Lieferanten ließe sich sicher erkennen.
  - *Vereinskennungen:* die eigene USt-ID und Steuernummer des Vereins
    (`club_identity`, `/admin/vereinskennungen`, Recht `admin.settings`,
    Tresor entsperrt; Audit `einstellung.vereinskennungen` nur mit „gesetzt
    ja/nein“). Reverse-Charge- und B2B-Rechnungen drucken „Ihre USt-IdNr.“,
    die KI liest sie als die des Verkäufers. Wie die Vereins-IBAN (E-29)
    werden sie **nie gelernt** (Hinweis „die des Vereins“, kein Konflikt),
    bei der KI-Neuanlage (Stufe 6) weggelassen und in Stufe 1 der Auflösung,
    in der Vorbelegung über Kennungen (`Pruefung::lieferantZu()`) und in der
    Vorauswahl für den KI-Prompt (`SupplierAufloesung::vorauswahl()`)
    übergangen – alle über `SupplierService::istVereinsKennung()`.
    **Solange die Seite nie gespeichert wurde**, lernt kein Lieferant eine
    USt-ID (Hinweis mit Verweis auf die Seite) und Stufe 6 legt ohne USt-ID
    und Steuernummer an; zwei leere Felder speichern heißt „der Verein hat
    keine“. Verglichen wird nach dem Entschlüsseln in der Sitzung, kein
    Blind Index – die Rotation versiegelt die Zeile mit. Lässt sich die Zeile
    nicht entschlüsseln, gilt das wie „nicht gespeichert“ (die sichere
    Seite), und die Admin-Seite zeigt einen Fehler statt eines leeren
    Formulars. Die Steuernummer wird so verglichen, wie sie eingetragen ist
    (Ziffern/Buchstaben) – die Seite bittet um die Form der Rechnungen.
  - *Konflikt markiert:* ein Wert, der schon einem **anderen** Lieferanten
    gehört (IBAN, Gläubiger-ID, USt-ID, Name/Alias), wird nicht
    gelernt; `Merkmale::$konflikte` hält Feld → ID des anderen. Beim Speichern
    (angekreuzt – gibt es nur Konflikte, sendet die Seite statt des
    Ankreuzfelds ein verstecktes `lieferant_lernen=konflikt`; ein seitdem
    geänderter Plan meldet dann kein „nicht ergänzt“, denn angeboten war
    nichts –, Recht `supplier.manage`) schreibt `Pruefung::speichern()` in
    der Lern-Transaktion den Audit-Eintrag `lieferant.merkmal_konflikt`
    (Entität: der bestätigte Lieferant) mit `{felder, andere, beleg}` – nur
    Feldnamen und IDs, kein Wert – auch wenn sonst nichts zu lernen ist,
    aber je Lieferant, Feld und anderem Lieferanten **nur einmal** (frühere
    Einträge werden in der Session entschlüsselt verglichen; die monatliche
    Rechnung schreibt nicht jedes Mal einen neuen). Die
    Meldung danach nennt nur Feldbezeichnungen („Beim Lieferanten nicht
    ergänzt, weil bei einem anderen Lieferanten hinterlegt: IBAN – bitte
    … ggf. zusammenführen.“). Eine Vereinskennung ist kein Konflikt (nur
    Hinweis).
  - *Schlüsselrotation* (#90): gelernte Werte stehen in den Lieferantendaten;
    `LieferantenIndexpflege` leitet `supplier_key` daraus neu ab – gelernte
    IBAN und USt-ID sowie die Vereinskennungen sind im
    `SchluesselrotationTest` abgedeckt.

## 8. Kategorisierung

Priorität: manuelle Regel (`assignment_rule`) > Lieferanten-Default > KI-
Vorschlag. Anzeige, woher der Vorschlag stammt („Regel: Lieferant", „KI
0.82").
`assignment_rule` gibt es seit M9-6 für Buchungen (04 §5 „Stand M9-6“,
Muster im Tresor, Abgleich in PHP); M7-7 ergänzt die Beleg-Seite.

**Stand M7-7** (issue #49, ohne Migration, E-21):
- *Regeln:* Es gibt **eine** Regelliste (`/app/buchungen/regeln`). Eine Regel
  mit Kategorie schlägt diese Kategorie auch für Belege vor
  (`App\Service\Bank\Regelabgleich::trifftBeleg()`): *Stichwort* enthalten
  im Zweck des Belegs, *Gegenseite* als IBAN gleich einer IBAN des
  Lieferanten bzw. Zahlers, sonst enthalten in seinem Namen oder einem
  Alias – beim noch unbekannten Partner (KI/E-Rechnung „Neu anlegen“) in
  dessen Name bzw. IBAN; Richtung, Groß-/Kleinschreibung, Leerraum, „beides
  muss passen“ und „ohne Bedingung nichts“ wie bei Buchungen. Von mehreren
  passenden aktiven Regeln **mit Kategorie** gilt die älteste.
- *Lieferanten-Default:* zuerst `supplier.default_category_id`, dann
  **gelernt**: Haben die zwei neuesten geprüften bzw. festgeschriebenen
  Belege des Partners (`checked_at`, auch über `merged_into` aufgelöst –
  ein zusammengeführter Partner im Formular zählt als sein Ziel –,
  nur im Zugriffsbereich der Person) dieselbe Kategorie, wird sie
  vorgeschlagen (`InvoiceRepository::letzteGeprueftenKategorien()`, nichts
  wird in `supplier` geschrieben).
- *KI:* `category_id` des neuesten Laufs (§6).
- Jede Stufe zählt nur mit einer **aktiven, zur Richtung passenden**
  Kategorie, sonst fragt die nächste. Reihenfolge und Herkunft:
  `App\Service\Invoice\Kategorisierung` (rein, ohne DB), angewendet in
  `Pruefung::kategorieVorschlagen()` auf das fertig vorbelegte Formular
  (Partner, Zweck, Richtung aus KI, E-Rechnung und Archiv-Vorgabe). Nennt
  die Archiv-Vorgabe (M12-3) selbst eine Kategorie, bleibt diese – sie hat
  ein Mensch einsortiert.
- *Anzeige:* unter dem Feld „Vorgeschlagen von: Regel „Name““ bzw.
  „Standard-Kategorie des Lieferanten/Zahlers“, „wie die letzten 2 geprüften
  Belege des Lieferanten/Zahlers“, „KI 0,82“ (`aria-describedby`). Den
  Namen der Regel sieht nur, wer Regeln lesen darf (`bank.view`), sonst
  steht nur „Regel“. Gelb („KI unsicher“) nur, wenn die KI-Kategorie
  stehen bleibt.
- *Änderbar:* Wie die ganze Vorbelegung (§6) nur ein Vorschlag, solange
  der Beleg nicht gespeichert ist; gespeichert wird, was die Person wählt.
  Danach ändert die Kategorie nur noch das Formular – bis „Geprüft“ und
  nach „Festschreibung aufheben“ (01 §7); spätere Regeln oder Lieferanten-
  Defaults fassen gespeicherte Belege nie an. Die Herkunft wird nicht
  gespeichert.

## 9. Wiederkehrende Rechnungen

- Erkennung bei jedem neuen Beleg eines Lieferanten: ≥ 3 Belege (bzw. 2 +
  KI-Hinweis `recurring.likely`) mit ähnlichem Betrag (±15 %, Setting) und
  regelmäßigem Abstand (Median-Intervall passt zu Monat/Quartal/Halbjahr/
  Jahr ± Toleranz) → Serien-**Vorschlag**.
- Bestätigte Serie: Belege werden verknüpft, `next_expected` berechnet.
- Übersicht „Wiederkehrende Kosten": Serie, Intervall, letzter Betrag,
  Jahreshochrechnung, Trend (Preissteigerung), nächster erwarteter Termin,
  **„überfällig"-Markierung**, wenn der erwartete Beleg fehlt.
- Auch aus Bankbuchungen (regelmäßige Lastschriften mit gleicher
  Mandatsreferenz) wird eine Serie vorgeschlagen – nützlich für
  „Buchung ohne Beleg".

**Stand M8-1** (issue #57, Migration 036, E-26): Erkennung, Vorschlag,
Bestätigen/Ändern/Verwerfen. **M8-2** (issue #58, E-33): Übersicht mit
Jahreshochrechnung/Trend/„überfällig" (unten „Übersicht"). Serien aus
Bankbuchungen später.
- *Auslöser:* Jeder Beleg, der in der Prüfansicht „Geprüft“ wird, reiht
  den Session-Job `detect_recurring` ein (`Serien::einreihen()`). Gezählt
  werden nur geprüfte bzw. festgeschriebene Belege
  (`InvoiceRepository::geprueftFuerLieferant()`, auch über `merged_into`
  aufgelöst; die 120 neuesten), getrennt nach Lieferant **und** Richtung.
  Ein Schritt, eine Transaktion unter Zeilensperre des Lieferanten und
  seiner Serien (ein gleichzeitiges Bestätigen überschreibt sich nicht).
  Wurde der Beleg auf einen anderen Lieferanten oder eine andere Richtung
  geändert, verlässt er zuerst seine bisherige Serie.
- *Erkennung* (`App\Service\Invoice\Serienerkennung`, rein, ohne DB):
  ≥ 3 Belege (2, wenn die KI am auslösenden – neuesten – Beleg
  `recurring.likely` las; nennt sie einen Rhythmus, muss er passen),
  gleiche Währung, Betrag > 0, jeder Betrag innerhalb ±15 % (Setting
  `serien_toleranz_prozent`, 0–100) des nächstneueren – eine schrittweise
  Preissteigerung bleibt in der Serie, ein Sprung beendet sie. Die Kette
  beginnt beim neuesten Beleg; passt der nicht (Einzelkauf), der nächste.
  Nicht passende Beträge werden übersprungen. Rhythmus: Median der Abstände
  ≈ Monat/Quartal/Halbjahr/Jahr (30,4/91,3/182,6/365,25 Tage ± 5/12/20/30);
  von zwei Belegen, die näher als ein halber Rhythmus liegen, bleibt nur der,
  dessen Abstand zum neueren Nachbarn besser zum Rhythmus passt (Einzelkauf
  mit ähnlichem Betrag, Duplikat); eine Lücke über 2,5 Rhythmen
  schneidet ab (nur der Teil danach zählt). Höchstens die 60 neuesten Belege.
- *Vorschlag:* `next_expected` = neuester Beleg + Rhythmus (Tag aufs
  Monatsende gekappt), `tolerance_days` 7/14/21/30, erwarteter Betrag = der
  neueste. Ein neuer Lauf aktualisiert den offenen Vorschlag, mit dem er die
  meisten Belege teilt (Bezeichnung/Vertrag bleiben); teilt er keine, wird
  er ein weiterer Vorschlag (Miete und Versicherung desselben Partners).
  Belege, die noch an einem anderen offenen Vorschlag hängen (späte Belege
  schließen eine Lücke), gehen in den gewählten über; ein leer gewordener
  Vorschlag wird gelöscht. Kein Audit.
- *Bestätigte Serie:* Ein neuer geprüfter Beleg, der zu keiner Serie gehört,
  wird verknüpft, wenn Währung gleich, Betrag in der Toleranz des erwarteten
  und kein Beleg der Serie näher als ein halber Rhythmus liegt (bei mehreren
  der nächste Betrag); ist er der neueste, übernimmt die Serie Betrag und
  rechnet `next_expected` weiter. Audit `serie.beleg_verknuepft` ohne
  Benutzer (Details: Dokument-ID).
- *Seiten* `/app/serien` (Liste: Vorschläge, bestätigte Serien, nächste
  Fälligkeit zuerst; Navigation „Wiederkehrend“) und `/app/serien/{id}`
  (Formular: Bezeichnung, Rhythmus inkl. „unregelmäßig“ – dann ohne nächste
  Fälligkeit –, erwarteter Betrag, nächste Fälligkeit, Toleranz 0–120 Tage,
  Vertrags-/Kundennummer, Belege als Ankreuzfelder, darunter weitere
  geprüfte Belege des Partners ohne Serie). `POST` dorthin bestätigt einen
  Vorschlag bzw. speichert, `POST …/verwerfen` verwirft (auch eine
  bestätigte Serie; danach nur lesbar). Recht `document.edit`, CSRF, Tresor;
  ein Konto mit Scope auf `document.edit` bekommt 403 (eine Serie umfasst
  alle Belege des Lieferanten). Audit `serie.bestaetigt`/`serie.geaendert`
  (nur Feldnamen; Speichern ohne Änderung schreibt nichts) und
  `serie.verworfen` (Details: Status vorher).
- *Nie* wird `invoice` geschrieben; Beträge/Namen nur verschlüsselt
  (`recurring_series.data_enc`), `job.state` leer.
- *Übersicht* (M8-2, `/app/serien`, Titel „Wiederkehrende Kosten“, Recht
  und Scope wie oben, keine Migration): zuerst die bestätigten Serien, dann
  die Vorschläge, je Serie Rhythmus, erwarteter Betrag, letzter geprüfter
  Beleg (Betrag, Datum, Link in die Prüfansicht), Jahreshochrechnung, Trend,
  nächste Fälligkeit; die Bezeichnung führt zur Serie. Berechnet rein in PHP
  (`App\Service\Invoice\SerienKennzahlen`, ohne DB); entschlüsselt werden
  nur die Belege, die die Kennzahlen brauchen (nach Klartext-Datum
  ausgewählt: der neueste, der Vergleichsbeleg), ein nicht lesbarer Beleg
  wird übersprungen statt die Seite abzubrechen:
  - *Jahreshochrechnung* = erwarteter Betrag × Belege pro Jahr (12/4/2/1);
    „unregelmäßig“ hat keine. Darüber die Summe je Richtung und Währung
    über die bestätigten Serien („Ausgaben pro Jahr“).
  - *Trend* = letzter Betrag gegen den neuesten Beleg der Serie, der
    mindestens 335 Tage älter ist (≈ Vorjahr), sonst gegen den ältesten; nur
    Belege in der Währung der Serie mit Betrag > 0 (ist der neueste eine
    Gutschrift oder in anderer Währung: kein Trend), in Zehntelprozent
    gerundet, ab zwei Belegen.
  - *Fälligkeit* nur für bestätigte Serien mit nächster Fälligkeit:
    „fällig“ ab dem Tag bis einschließlich Fälligkeit + `tolerance_days`,
    danach **„überfällig“** (Marke, Hinweis mit Anzahl über der Tabelle).
    Maßgeblich sind nur geprüfte Belege – ein Beleg, der noch im Posteingang
    liegt, beendet „überfällig“ erst mit dem Prüfen (dann verknüpft ihn die
    Serie und rechnet `next_expected` weiter). Vorschläge und verworfene
    Serien sind nie überfällig.
  - Keine Mail und kein Cron: „überfällig“ entsteht beim Anzeigen (der Cron
    kann ohne Tresor nichts entschlüsseln, CLAUDE.md §4).

## 10. Volltextsuche (Session-Index)

**Stand issue #94** (ohne Migration): `/app/suche` findet Belege über ihren
erkannten Text, ohne dass ein durchsuchbarer Index dauerhaft auf dem Server
liegt (CLAUDE.md §5, E-24).

- *Kein dauerhafter Index:* weder Klartext noch Wort-HMACs (Blind Indexes je
  Wort) in DB oder Dateien – beides verriete einem Datenbank- oder
  Dateileser Worthäufigkeiten und damit Inhalte. Stattdessen baut jede
  angemeldete Session mit entsperrtem Tresor ihren eigenen, verschlüsselten
  Index, der mit der Session endet.
- *Textquellen* (`App\Service\Suche\Suchtextquelle`, je Quelle eine Klasse,
  in `bootstrap.php` als Liste an `Volltextsuche` übergeben):
  `TextlayerQuelle` (alle Seiten des neuesten `text`-Laufs von
  `extract_text`, auch eine für die KI zu dünne Textebene –
  `Texterkennung::seitentexte()`), `ERechnungQuelle` (Felder einer gelesenen
  E-Rechnung) und `KiQuelle` (gültige `extraction` des neuesten KI-Laufs). Aus
  einer Extraktion (`Extraktionstexte`, Format `extract-v1`) zählen
  Lieferantenangaben, Rechnungs-/Kunden-/Vertragsnummer, Zweck,
  Mandatsreferenz, Hinweis auf Wiederkehr und der Bruttobetrag in deutscher
  Schreibweise mit zwei Nachkommastellen („1234,50“, auch aus „1234.5“). Der OCR-Text des Workers (07, #51–#55) kommt ohne
  Umbau dazu: als Artefakt `text` deckt ihn `TextlayerQuelle` schon ab,
  sonst als eigene Quelle mit eigener Artefakt-Art.
- *Normalisierung* (`Suchtext`): Kleinschreibung, Leerraum zu einem
  Leerzeichen, Steuer- und Formatzeichen (weiches Trennzeichen, Null-Breite)
  entfernt, Umlaute bleiben; je Dokument höchstens 32 KiB
  (`MAX_BYTES_JE_DOKUMENT`), Quellen mit „ | “ getrennt.
- *Ablage:* Teile unter `shared/var/tmp/suche/<Index-ID>/<n>.bin`
  (`SuchindexSpeicher`, Dateirechte 0600, atomar geschrieben). Je Teil JSON
  `{f: 1, e: [[document_id, text], …]}` → `gzdeflate` → `sodium_pad` auf
  4-KiB-Blöcke (die Größe eines Teils verrät wenig über seinen Text) →
  `FieldCipher` (XChaCha20-Poly1305, AAD `suchindex|<n>|teil`) mit einem
  **zufälligen Index-Schlüssel je Index**. In der PHP-Session
  (`SitzungsSuchindex`, `$_SESSION['suchindex']`) stehen nur Index-ID, Cursor,
  Teilezahl, Benutzer-ID, Fingerabdruck des Scopes und der Index-Schlüssel
  **versiegelt an den Tresor-Public-Key** (wie `dek_sealed`) – öffnen kann ihn
  nur der entsperrte Tresor, also nur mit dem Cookie `__Host-vk` (01 §2).
  Session-Dateien allein, Index-Dateien allein oder beides zusammen öffnen
  nichts; die Session bleibt klein.
- *Aufbau als Schrittkette* (CLAUDE.md §1): `POST /app/suche/index`
  (`Volltextsuche::schritt()`, JSON `{fertig, offen}`) nimmt bis zu 40
  Dokumente (`SCHRITT_DOKUMENTE`) auf und schreibt sie als einen Teil;
  `public/js/suche.js` wiederholt das, bis der Index aktuell ist, und schickt
  dann das Suchformular erneut ab (Schrittgröße und offene Belege als
  `data-*`). Welche Dokumente dran sind, sagt der **Cursor = höchste
  aufgenommene `document_artifact.id`** der Arten, die die Quellen lesen
  (`text`, `e_rechnung`, `extraction`): Ein Schritt nimmt die Dokumente mit
  Artefakten über dem Cursor in der Reihenfolge ihres jeweils neuesten
  Artefakts (`DocumentArtifactRepository::naechsteSuchdokumente()`), der neue
  Cursor ist das neueste Artefakt des letzten – so wird jedes Dokument je
  Änderung genau einmal aufgenommen, und `offen` sinkt je Schritt um die
  Schrittgröße. Ein neuer Joblauf schreibt neue Zeilen, sein Dokument kommt
  in den nächsten Schritt; beim Suchen werden die Teile vom neuesten her
  gelesen, so gilt je Dokument der neueste Eintrag (auch ein leerer). Der
  erste Aufbau ist dasselbe ab Cursor 0.
  Sind höchstens 40 Dokumente offen (kleiner Verein, ein paar neue Belege seit
  der letzten Suche), erledigt die Seite das gleich selbst
  (`Volltextsuche::nachziehen()`), ohne Kette. Gebaut wird beim ersten Aufruf
  von `/app/suche` in der Session, nicht schon beim Anmelden.
- *Suche:* `POST /app/suche` (CSRF) – die Suchbegriffe sind Vereinsdaten und
  gehören nicht in URLs, Server-Logs oder den Verlauf; Antwort `no-store`.
  Begriffe an Leerraum getrennt, „in Anführungszeichen“ als Phrase, alle
  müssen vorkommen (UND), mindestens 2 Zeichen, höchstens 8 Begriffe und
  200 Zeichen (`Suchanfrage`); Treffer neueste zuerst (Dokument-ID), höchstens
  50 (`MAX_TREFFER`, darüber „weitere Treffer – bitte eingrenzen“; erst
  werden alle Teile nach passenden Dokument-IDs durchsucht, der Ausschnitt
  nur für die angezeigten Treffer gebildet), je Treffer
  Referenz (Link auf `/app/posteingang/{id}`), Eingang, Status,
  Mannschaft/Bereich und ein Ausschnitt um die erste Fundstelle mit
  `<mark>` (Segmente, in der View escaped). Gesucht wird nur in einem Index,
  der aktuell ist – ein halber Index zeigt keine (unvollständigen) Treffer.
- *Rechte und Scope:* `inbox.view` mit seinem Scope (01 §4) auf allen vier
  Routen – wie der Posteingang, auf den die Treffer verlinken. Aufgenommen
  werden nur Dokumente im Scope (`DocumentArtifactRepository::
  suchquellenNach()`, `Zugriffsbereich::sqlBedingung()` auf
  `document.created_at`/`cost_center_id`), und jeder Treffer wird vor der
  Anzeige erneut in SQL gegen den Scope geprüft
  (`DocumentRepository::inboxItems()`): Ein Dokument, das den Scope
  inzwischen verlassen hat, steht noch im Index, erscheint aber nicht. Bei
  einem Kostenstellen-Scope merkt sich die Session zusätzlich eine Signatur
  der aufgenommenen Dokumente (Anzahl und Summe ihrer IDs bis zum Cursor,
  `suchdokumenteBis()`); bekommt ein altes Dokument nachträglich eine
  Kostenstelle des Scopes (oder verliert sie), passt die Signatur nicht mehr
  und der Index wird neu gebaut. Ein Zeitraum-Scope hängt an
  `created_at`, das sich nie ändert.
- *Verwerfen und Neuaufbau:* anderer Benutzer, geänderter Scope, ein Tresor,
  der den Schlüssel nicht öffnet, ein unbekanntes Session-Format, ein
  fehlender oder manipulierter Teil → Index weg (Dateien und Session-Eintrag),
  der nächste Aufruf baut neu. „Suchindex neu aufbauen“ (`POST
  /app/suche/neu`) tut dasselbe von Hand. Abmelden löscht die Dateien
  (`AuthController`, vor dem Zerstören der Session); verwaiste Indexe
  abgelaufener Sessions räumt der Cron nach 24 h ab
  (`Cron\SuchindexCleanupTask`, `suchindex_aufraeumen`, nur Dateizeiten).
- *Bekannte Grenzen:* Ein Artefakt, dessen `data_enc` erst nach dem Lesen
  eines Schritts gesetzt wird (Einfügen und Füllen liegen im selben Request
  von Millisekunden), fehlt im Index bis zum nächsten Lauf oder Neuaufbau.
  Jede Suche entschlüsselt alle Teile in einem Request; bei einigen
  Tausend Belegen je Jahr (typisch wenige KiB Text je Beleg) sind das einige
  MiB und damit kurz – sehr große Archive mit langen PDF-Texten machen die
  Suche entsprechend langsamer.
  Kompression vor Verschlüsselung verrät über die (auf 4 KiB gerundete)
  Größe eines Teils grob, wie gut sich sein Text packen lässt; ausnutzbar
  wäre das nur mit Lesezugriff auf `var/tmp` und vielen gezielten
  Neuaufbauten.
- *Ohne Tresor* gibt es nichts zu durchsuchen: Hinweis statt Formular, der
  Schritt antwortet 403.

## Pflicht-Tests

Rechtstexte (M13-3: `EinfachesMarkdownTest`: Überschriften/Listen/Absätze,
nur eigene Tags und `href`, kein Skript/Event-Attribut/`javascript:`/
`data:`/`//host`, Platzhalter-Erkennung auch langer Platzhalter, Links im neuen Tab;
`RechtstexteFlowTest`: Seiten ohne
Session mit Vorlage-Hinweis, Fuß-Links, Speichern escaped, unveränderte
Vorlage nicht gespeichert, leeres Feld = Vorlage, zu lang → nichts
gespeichert, Länge nach CRLF-Normalisierung, CSRF, 403 ohne
`admin.settings`, Audit nur bei Änderung, nur nutzbare KI-Profile;
`PublicSubmissionTest`: Hinweis escaped, Vorlage-Marke, Links im neuen Tab;
`RoutePermissionMatrixTest`); Scanner-Mathematik (Homographie-Roundtrip, Schwelle auf Referenzbild,
Viereck-Auswahl); Eck-Editor (Standardrahmen, Anzeige-/Bildkoordinaten-
Umrechnung, Eckpunkt-Verschieben mit Ablehnung entarteter Vierecke,
Farbmodus-Anwendung je Modus); Scanner-Einbau (`tests/js/scanner.test.js`:
Fähigkeitsprüfung, Startecken, Upload-Reihenfolge Original → Fassung, Abbruch
ohne Upload, Fallback nur mit Original; `tests/js/kamera.test.js`:
Verfügbarkeit nur im sicheren Kontext, Rahmen A4 innerhalb des Videos,
Fehlertexte; Live-Erkennung #146: Drosselung, Vorschaugröße, Stabilität
über 3 Frames inkl. Fehlschlag/Wandern, Startecken nur aus stabilem
Viereck, Overlay-Abbildung unter `object-fit: contain`; `scanner.test.js`:
vorgemerkte Startecken je Datei); GD-Fallback (`SchwarzweissFallbackTest`: reines Schwarzweiß,
dunkles Foto nicht schwarz, lange Kante gedeckelt, EXIF-Drehung,
PNG-Transparenz, Pixel-Obergrenze); Original + aufbereitete Fassung
(`PdfErzeugungTest`: Fassung unverändert eingebettet, Originale
byte-identisch, Nicht-JPEG-Fassung → Fallback; `PublicSubmissionTest`/
`InterneErfassungTest`: parallele Liste gespeichert, falsche Länge/fremde
Herkunft/Kollision abgelehnt, abgelöste Fassung bleibt für den Cron;
`InboxFlowTest`: beide Fassungen ausgeliefert); Upload-Chunks (Reihenfolge, fehlende Chunks,
Magic-Byte-Ablehnung, Größenlimit); IBAN-Validierung; PDF-Erzeugung (Seitenzahl =
Bildzahl, gültiges PDF); PDF-Rasterung (vollständiger Lauf über eine und
mehrere Quellen, Idempotenz eines wiederholten Uploads, Sperre und
Gift-Job-Schutz über viele Requests hinweg – Details: 06-betrieb.md §4);
Textlayer-Heuristik (`TextlayerTest`: > 200 Zeichen ohne Leerraum, ≥ 50 %
Buchstaben, nur bei Befund `gelesen`; `PdfTextlayerTest`: WinAnsi/
MacRoman/`/Differences`, Type0 mit ToUnicode, 1-Byte-Codes trotz
2-Byte-Codespace, Zeilen/Leerzeichen aus Positionen, Filterketten, Object
Streams, indirekte `/Length`, Form-XObjects, Bilder nie dekodiert,
inkrementelles Update, Seitenbaum, verschlüsselt, kaputt ohne Warnung,
Dekompressionsbombe, Scan ohne Text, zwei Fixtures unter
`tests/fixtures/pdf/`; `TexterkennungJobTest`: beide Speicher-Backends, ein
PDF je Schritt, kein Klartext außerhalb des Artefakts, Scan/Bilder/
gemischt → `uebersprungen`, kaputt/verschlüsselt kein Jobfehler,
wiederholter Schritt speichert nichts doppelt, neuer Lauf ersetzt den
alten, langer Text, Migration 026); E-Rechnungen (`tests/fixtures/erechnung/`,
synthetisch: XRechnung CII und UBL, UBL-Gutschrift, ZUGFeRD 1.0,
ZUGFeRD-/Factur-X-PDF; `ERechnungLeserTest`: alle Felder beider Syntaxen,
Gutschrift, `extract-v1`-Form, Summen-Hinweis, Cent-Parsing und Rundung,
unvollständige Steuerzeile, fehlendes Pflichtfeld → `defekt`, ZUGFeRD 1.0,
kein Rechnungs-XML, kaputt ohne Warnung, DOCTYPE/XXE/Entitäten abgewiesen,
zu groß, Wurzelerkennung; `ERechnungPdfTest`: Fixture, Name-Tree mit
`/Kids` und UTF-16-Namen, nur `/AF`, Standardname zuerst, andere Anhänge,
ohne Anhang, kaputt/verschlüsselt, Dekompressionsbombe;
`TexterkennungJobTest`: beide Speicher-Backends, ZUGFeRD neben Text, kein
Klartext außerhalb des Artefakts, defekt kein Jobfehler, Wiederholung und
Abbruch zwischen den Artefakten, neuer Lauf ersetzt, kein KI-Zugriff;
`PruefungFlowTest`: Vorbelegung ohne Speichern, Lieferant über IBAN/USt-ID
nur mit passender Rolle, nicht lesbare E-Rechnung, Hinweise, nur passende
Werte; `PdfErzeugungTest`, `ChunkUploadTest`, `InboxFlowTest`: XML ist keine
Seite, Upload ok bzw. 415, Download als `attachment`);
KI-Auslesen (M7-5: `KiAusleseTest`: Prompt mit Kategorien/`ai_hint`,
Kostenstellen, ohne Lieferantenliste, Textlayer ohne Bilder, Bilder nur bei
`vision` und höchstens `max_images`, E-Rechnung als Felder, Freitext als Daten
ohne Ausbruch aus den Marken, „nicht lesbar“ mit Grund, Server-Prüfungen
(Summe, Betrag, Datum, Kategorie inkl. Richtung, Kostenstelle, `sphere`,
fehlende Seiten), Konfidenz-Grenze 0,7, Rundreise `KiVersuch`;
`ProcessingArchitectureTest`: keine Web-Schicht in `Service/Processing`, samt
Selbsttest des Detektors; `KiAuslesungJobTest`: gültig → `ausgewertet`,
Artefakt verschlüsselt mit Rohantwort/Modell/Prompt-Version, kein Klartext
in Artefakt-Spalten/`job`/`audit_log`, je Aufruf ein Schritt, Reparatur mit
Verlauf, nach der Reparatur ungültig → `ki_fehler` ohne dritten Aufruf,
Ausfall → Fallback mit eigenem Key, ohne Fallback → `ki_fehler`, gespeicherter
Versuch wird nicht neu erfragt, übernommener/festgeschriebener Beleg ohne
Aufruf (auch zwischen zwei Schritten), ohne nutzbares Profil übersprungen,
Warten auf `render_pages`, digitales PDF als Text, PNG → JPEG und
`max_images`, Profil ohne `vision`, Freitext, neuer Lauf ersetzt den alten,
Migration 031; `JobStepFlowTest`: zweiphasiger Schritt mit Freigabe vor dem
Aufruf, verlängerte Sperre, Session frei, verlorene Sperre verwirft das
Ergebnis, Exception nur als Klassenname, `warten`; `PruefungFlowTest`:
Vorbelegung ohne Speichern, gelbe Markierung mit Marke nur an KI-Feldern,
Warnungen oben, E-Rechnung gewinnt, Archiv obenauf, `ki_fehler` mit Meldung
und „KI erneut versuchen“ (Status, Job, Audit, kein zweiter Lauf), „Mit KI
auslesen“, ohne Profil nichts angeboten, Tresor/CSRF/Scope;
`InboxFlowTest`/`ArchivImportFlowTest`: Job beim Annehmen bzw. Import;
`RoutePermissionMatrixTest`);
Kategorisierung (M7-7: `KategorisierungTest`: Regel am Beleg – Stichwort im
Zweck, Gegenseite über Name/Alias/IBAN, beides, Richtung, ohne Bedingung
nichts; Reihenfolge Regel > Standard > gelernt > KI, älteste aktive Regel mit
Kategorie, unbrauchbare Kategorie gibt weiter, gelernt nur bei zwei gleichen
neuesten, Belege nur bei Bedarf gelesen, Herkunftstexte samt Regelname nur
mit Leserecht; `PruefungFlowTest`: Regel vor Lieferanten-Default vor KI samt
Anzeige und ohne Gelb, Regel auf neuen Partner der KI, aus zwei geprüften
Belegen gelernt und nur im Zugriffsbereich, Lieferant aus dem Archiv,
Vorschlag änderbar und nach dem Speichern weg);
KI-Client mit aufgezeichneten Antworten (`tests/fixtures/llm/extract-v1/`,
`FakeLlmClient`/`FakeChatHttp`; `KiAbfrageTest`: gültig, ungültig +
Reparatur mit Verlauf und Fehlerliste, nach der Reparatur ungültig →
fehlgeschlagen ohne dritten Aufruf, kein JSON, JSON in Prosa/Code-Block,
Klammern in Strings, kaputte und abgeschnittene Antwort, Timeout und
HTTP-Fehler ohne Reparatur, keine Beleginhalte in Meldungen/Fehlern,
Protokoll, getrennte Schritte; `KiAbfrageFallbackTest`: Ausfall (Timeout,
Verbindung, 429, 5xx) → ein Aufruf des Fallbacks mit eigenem Key und Protokoll
des genutzten Profils, kein Fallback bei 4xx/Reparatur-Weg/gescheitertem
Reparatur-Call, nie ein zweiter Fallback oder vierter Aufruf, eigene
Reparatur am Fallback, unbrauchbares/gleiches Profil, getrennter Schritt; `OpenAiCompatibleClientTest`: Request je
Fähigkeit inkl. `max_images`, `response_format` vs. Schema im Prompt,
`max_completion_tokens`, kein `temperature`, Antwort-Parsing, HTTP-Fehler
ohne Key, Timeout/Verbindung, keine Chat-Antwort/Verweigerung, nur JPEG;
`KiSchemaTest`: alle Schlüsselwörter, keine Werte in Fehlern, unbekannte
Schlüsselwörter abgewiesen, `extract-v1` strict-konform, E-Rechnung hat
dieselbe Form; `KiPromptsTest`); KI-Anbieter (`VerbindungstestTest` mit
`tests/fixtures/llm/verbindungstest/`: erzwungenes Schema + Bild
erkannt (zwei Aufrufe, Anfrage-Inhalt), ignoriertes Schema (Satz statt JSON,
Anthropic) → `json_schema` nein, abgelehnter Parameter → eine Wiederholung
ohne `response_format`, Bild nicht gesehen/abgelehnt → `vision` nein,
`max_images` mindestens 1 bei Vision, Zeitlimit je Aufruf und Gesamtbudget,
401 ohne Key im Ergebnis, HTTP-Fehler und Timeout in beiden Proben ohne
Ergebnis, keine Chat-Antwort; `KiAnbieterRegelnTest`: Basis-URL, Vorlagen
OpenAI/Anthropic/llama.cpp; `KiAnbieterFlowTest`: Seed = Vorlage OpenAI als Standard
ohne Key, Vorlagen Anthropic und llama.cpp im Formular (speichern + testen), Fallback wählen/ersetzen/entfernen
(höchstens eins, Regeln aktiv + Key + nicht Standard,
Löschen/Standard/Deaktivieren/Key entfernen löscht die Wahl, CHECK, CSRF,
Audit), Key nur als Server-Chiffrat und nie
auf einer Seite, leer = unverändert, Entfernen, fremder Server-Schlüssel,
Validierung, genau ein Standard und nicht löschbar, Verbindungstest mit
gespeichertem Key, Fähigkeiten ins Profil übernommen + Audit (nur bei
Änderung), fehlgeschlagene Probe ändert nichts, kein Key im Log, CSRF); Betrags-Parsing („1.234,56", „1234.56",
negativ) und Summenprüfung; Lieferanten-Auflösung über alle 6 Stufen inkl.
Rechtsform-Normalisierung (M7-6: `LieferantenAufloesungJobTest`: Stufe 1
USt-ID und Steuernummer in anderer Schreibweise, Stufe 2 IBAN mit Leerzeichen,
Stufe 3 Gläubiger-ID, Stufe 4 Name ohne Rechtsform/Umlaute und Alias,
Reihenfolge der Stufen, mehrdeutiger Name, Rolle passt nicht ohne Neuanlage,
Einnahme → Zahler, zusammengeführter Lieferant, Stufe 5 nur Vorschlag ohne
Anlage, Stufe 6 mit `created_via = ki`/`needs_review`, Schlüsseln, Audit ohne
Benutzer und ohne Klartext, unbrauchbare Werte weggelassen, ohne Lieferant
nichts, zweiter Lauf legt nicht doppelt an, wiederholter Schritt, übernommener
Beleg, gescheiterte KI, Archiv-Vorgabe, E-Rechnung über KI, Vorauswahl im
Prompt bzw. ohne Text keine, Migration 035; `LieferantenAehnlichkeitTest`;
`LieferantenKandidatenTest`; `KiAusleseTest`: Vorauswahl höchstens 10 Namen,
als Daten, Marken entschärft; `PruefungFlowTest`: Treffer gewählt mit Stufe,
Vorschlag gelb, Neuanlage mit Link, Rolle passt nicht, Verkäufer der
E-Rechnung, Archiv gewinnt; `SupplierFlowTest`: Liste „Neue Lieferanten
prüfen“, „Als geprüft markieren“ einmal mit Audit, Speichern gilt als geprüft,
Zusammenführen lässt das Flag, Tresor/CSRF; `RoutePermissionMatrixTest`;
M7-6b: `LieferantenMerkmaleTest`: neue IBAN/Alias, Bekanntes und Ungültiges
nicht, Gläubiger-ID nur wenn leer samt Mandat, volle Listen; `PruefungFlowTest`:
Ankreuzfeld vorausgewählt, Lernen samt `supplier_key` und Audit nur mit
Feldnamen, nächster Beleg in Stufe 2, ersetzter Vorschlag/abgewählt/ohne
`supplier.manage`/späteres Speichern/geänderter Plan lernt nichts, IBAN bzw.
Name eines anderen Lieferanten genannt und nicht gelernt, Vereins-IBAN nicht
gelernt (dazu `LieferantenAufloesungJobTest`: Stufe 6 ohne Vereins-IBAN,
Stufe 2 ignoriert sie), abgelehntes Speichern zeigt das Angebot
wieder, zusammengeführter Lieferant unberührt; `pruefansicht.test.js`);
Serienerkennung (M8-1: `SerienerkennungTest`: monatlich, quartalsweise,
halbjährlich, jährlich, unregelmäßig → keine Serie, Preissteigerung innerhalb
Toleranz, Sprung beendet die Serie, Einzelkauf übersprungen, 2 + KI-Hinweis
samt Rhythmus, Lücke schneidet ab, Währung/Gutschrift, Monatsende;
`SerienFlowTest`: „Geprüft“ reiht ein, drei Belege → Vorschlag ohne Klartext,
zweiter Lauf aktualisiert, festgeschriebene zählen und bleiben unverändert,
Lieferanten getrennt, Toleranz-Setting, Migration 036, Liste/Formular/
Bestätigen mit Audit nur Feldnamen, bestätigte Serie übernimmt den nächsten
Beleg, Verwerfen ohne erneuten Vorschlag, Validierung je Feld, Tresor/CSRF,
Zusammenführen; `RoutePermissionMatrixTest`; M8-2: `SerienKennzahlenTest`:
Jahreshochrechnung je Rhythmus, keine bei „unregelmäßig“, Trend gegen ≈
Vorjahr bzw. ältesten Beleg, keiner bei einem Beleg/anderer Währung,
„überfällig“ erst nach der Toleranz, nur bestätigte Serien mit Fälligkeit,
nur benötigte Belege entschlüsselt, unlesbarer übersprungen, Summen;
`SerienFlowTest`: Übersicht mit „überfällig“, Links zu Serie und Beleg,
letzter Betrag, Hochrechnung, Trend, Vorschlag nie überfällig);
Duplikaterkennung (`InhaltsindexTest`: gleiche Bytes gleich unabhängig von der
Stückelung, Seitenreihenfolge zählt, kein reiner SHA-256, anderer Tresor anders,
gesperrter Tresor wirft; `DuplikatindexJobTest`: beide Speicher-Backends, ein
Schritt je Original, `job.state` ohne Klartext/Hash, einmal gesetzt bleibt,
festgeschriebener Beleg bekommt den Index, Migration reiht ältere Belege ein;
`DuplikatFlowTest`: Marke in Posteingang und Prüf-Warteschlange, Hinweis auf
beiden Detailseiten, Lieferant + Nummer inkl. Leerraum und zusammengeführtem
Lieferanten, abgelehntes Gegenstück zählt nicht, Verwerfen aus jedem offenen
Status inkl. `geprueft`, ohne Verdacht abgelehnt, Behalten löst beide und eine
dritte Kopie markiert wieder, festgeschriebener Beleg unverändert, CSRF/Recht/
Scope/Tresor).
Volltextsuche (issue #94, Abschnitt 10): `SuchtextTest` (Normalisierung,
Kürzung an Zeichengrenzen), `SuchanfrageTest` (UND, Phrasen, kurze/doppelte
Begriffe, Ausschnitt mit Markierung, Mehrbyte-Zeichen), `ExtraktionstexteTest`,
`SuchindexSpeicherTest` (atomar, 0600, Kennung kein Pfad, Cron nach 24 h);
`VolltextsucheTest` (alle drei Quellen, Aufbau in Schritten, neueste zuerst
und Obergrenze, kein Klartext in Dateien und Session, Schlüssel nur mit dem
Tresor, neuer Text und neuester Eintrag gilt, nur der Scope wird
aufgenommen und angezeigt, Scope-Wechsel/anderer Benutzer/anderer Tresor/
manipulierter oder fehlender Teil verwerfen, Verwerfen löscht Dateien);
`SucheFlowTest` (Suche per POST mit `no-store`, kleiner Bestand ohne Kette,
großer mit Kette und JSON-Schritt, escapter Ausschnitt, ohne Tresor, CSRF,
Neuaufbau, Kostenstellen-Scope); `RoutePermissionMatrixTest`;
`tests/js/suche.test.js`.
