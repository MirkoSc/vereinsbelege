# 03 – Erfassung, Aufbereitung, Texterkennung, KI

## 1. Öffentliche Einreichung `/einreichen`

Mobile-first, eine Seite, kein Login. Ablauf:

1. **Beleg erfassen** – drei Wege auf einem Bildschirm:
   - „Foto aufnehmen": Live-Kamera (`getUserMedia`, Rückkamera) mit
     Rahmen-Overlay; Fallback `<input type="file" accept="image/*"
     capture="environment">` (iOS/ältere Browser).
   - „Bild wählen" (JPEG/PNG; HEIC wird im Browser, wenn möglich, konvertiert,
     sonst verständliche Fehlermeldung).
   - „PDF wählen".
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
- *Abschluss:* vollständig? → Magic Bytes des ersten Chunks (nur JPEG, PNG,
  PDF; der Client-MIME wird nie verwendet) → `BlobService::store()` bekommt
  die Chunks als Generator, der Klartext wird also nie zu einer zweiten Datei
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

**Pflicht-Tests (Upload):** `MagicBytesTest` (die drei erlaubten Typen, ZIP/
ELF/HTML/Text/GIF/HEIC/leer/zu kurz abgelehnt, PDF-Kopf mit Versatz
abgelehnt); `UploadServiceTest` (leere Datei und Datei über dem Limit vor der
Übertragung abgelehnt, Limit selbst erlaubt, zu langer Chunk abgelehnt und
ohne Rest, letzter Chunk kürzer, verkehrte Reihenfolge ergibt die richtige
Datei, Wiederholung überschreibt, Index außerhalb, fehlender und zu kurzer
Chunk stehen in `fehlend`, ID ohne Pfad-Traversal, `discard` wiederholbar,
Metadaten ohne Fachdaten, Aufräumen nur bei altem Verzeichnis);
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
1. `extract_text` (Webhoster: PDF-Textlayer / E-Rechnungs-XML) –
   `session`/`session`
2. `render_pages` (nur PDFs ohne Seitenbilder; Abschnitt 3, issue #30/M4-8) –
   `browser`/`worker`
3. `ocr` (durchsuchbares PDF/A + Text) – *entfällt*/`worker`
4. `ai_extract` (→ KI-Anbieter) – `session`/`worker`
5. `resolve_supplier` (Abschnitt 7) – immer `session`
6. `classify` (Kategorie/Kostenstelle, Regeln + KI-Vorschlag) – `session`
7. `detect_recurring` (Abschnitt 9) – `session`
8. `detect_duplicate` – `session`
9. `match_transactions` (siehe 04, falls Buchungen vorhanden) – `session`

Die Logik von `pdf_erzeugen`, `ocr` und `ai_extract` liegt in
`app/src/Service/Processing/` und ist framework-frei (siehe CLAUDE.md §6a);
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

### Auslese-Schema (Prompt-Version `extract-v1`)

```json
{
  "document_type": "rechnung|quittung|gutschrift|kassenbon|mahnung|lieferschein|sonstiges",
  "direction": "ausgabe|einnahme",
  "supplier": { "name": "", "address": "", "iban": "", "bic": "", "vat_id": "",
                "tax_number": "", "email": "", "website": "", "creditor_id": "" },
  "invoice_number": "", "customer_number": "", "contract_number": "",
  "invoice_date": "YYYY-MM-DD", "due_date": "YYYY-MM-DD|null",
  "service_period": { "from": "YYYY-MM-DD|null", "to": "YYYY-MM-DD|null" },
  "currency": "EUR",
  "total_gross": "123.45", "total_net": "103.74|null",
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

- Beträge als Dezimal-String → serverseitig in Cent geparst und geprüft
  (Summe Netto + Steuern ≈ Brutto, sonst Warnung).
- Der Prompt enthält: Kategorienliste mit `ai_hint` (passend zur
  Richtung), Kostenstellen, Sphären-Erklärung nur bei `sphaeren_aktiv`, **Freitext des Einreichers**, bekannte Lieferanten
  nur als Top-Kandidaten (per Blind-Index-Vorauswahl, max. 10 Namen) – nie
  die komplette Lieferantenliste.
- Prompts liegen versioniert unter `app/prompts/*.md`, nicht im Code.

### Prüfansicht
- Links Beleg (Seiten blätterbar, Zoom), rechts Formular mit den
  ausgelesenen Feldern; Felder mit Konfidenz < 0.7 gelb markiert,
  KI-Warnungen oben. Tastatur-freundlich (Tab-Reihenfolge, Enter =
  „Geprüft, nächster Beleg").

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
  nach Bestätigung hinzugefügt.
- Zusammenführen doppelter Lieferanten (alle Belege/Regeln umhängen,
  `merged_into` setzen, Audit). **Festgeschriebene Belege** (01 §7) sind
  unveränderlich und werden nicht umgehängt – wie das Zusammenführen damit
  umgeht (z. B. Auflösung über `merged_into`), klärt M6-5 (issue #39).
- Lieferant hat Default-Kategorie/-Sphäre; ab dem 2. bestätigten Beleg mit
  gleicher Kategorie wird sie automatisch vorgeschlagen (Regel vor KI).
- **Stand M6-2** (issue #36): Stammdaten-Pflege unter `/app/lieferanten`
  (Lieferanten und Zahler, Spalte `role`), Blind-Index-Schlüssel in
  `supplier_key` für Name/Aliasse, IBAN, USt-ID, Steuernummer, Gläubiger-ID
  und Mandatsreferenz, Normalisierung aus Stufe 4 in
  `App\Service\MasterData\SupplierKeys` – die Stufen 1–4 sind damit reine
  Nachschlagevorgänge. IBAN/USt-ID/Steuernummer/Gläubiger-ID sind eindeutig
  je Lieferant. Details: 02 „Lieferanten“.

## 8. Kategorisierung

Priorität: manuelle Regel (`assignment_rule`) > Lieferanten-Default > KI-
Vorschlag. Anzeige, woher der Vorschlag stammt („Regel: Lieferant", „KI
0.82").

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

## Pflicht-Tests

Scanner-Mathematik (Homographie-Roundtrip, Schwelle auf Referenzbild,
Viereck-Auswahl); Eck-Editor (Standardrahmen, Anzeige-/Bildkoordinaten-
Umrechnung, Eckpunkt-Verschieben mit Ablehnung entarteter Vierecke,
Farbmodus-Anwendung je Modus); Scanner-Einbau (`tests/js/scanner.test.js`:
Fähigkeitsprüfung, Startecken, Upload-Reihenfolge Original → Fassung, Abbruch
ohne Upload, Fallback nur mit Original; `tests/js/kamera.test.js`:
Verfügbarkeit nur im sicheren Kontext, Rahmen A4 innerhalb des Videos,
Fehlertexte); GD-Fallback (`SchwarzweissFallbackTest`: reines Schwarzweiß,
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
Textlayer-Heuristik; ZUGFeRD-/XRechnung-Fixtures;
KI-Client mit aufgezeichneten Antworten (gültig, ungültig + Reparatur,
Timeout, HTTP-Fehler, kein JSON); Betrags-Parsing („1.234,56", „1234.56",
negativ) und Summenprüfung; Lieferanten-Auflösung über alle 6 Stufen inkl.
Rechtsform-Normalisierung; Serienerkennung (monatlich, quartalsweise,
jährlich, unregelmäßig → keine Serie, Preissteigerung innerhalb Toleranz);
Duplikaterkennung.
