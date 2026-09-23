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
mit Mehrfach-Upload für mehrere Belege auf einmal) – **Backlog M4-6**.

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
Pointer-Drag **und** ↑/↓-Knöpfen; Kamera-Live-Vorschau mit Rahmen-Overlay ist
Backlog M5 (Scanner). Referenznummer `R-<Jahr>-<laufende Nummer>`,
Bestätigungsmail nur mit der Referenz
(`Mailer::reiheEinreichungsbestaetigungEin()`), Prüfung serverseitig mit
Feld-Fehlern je Angabe.

**Stand M4-3** (issue #25, Abschnitt 5 von 01): Seiten-/Größenlimit
(`App\Service\Submission\EinreichungsEinstellungen`, einstellbar unter
`/admin/einreichung`), Rate-Limit, Proof-of-Work und Honeypot
(`App\Service\Submission\Spamschutz`) laufen jetzt vor
`SubmissionService::einreichen()` bzw. vor jedem Seiten-Upload.

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
  `/admin`. Die öffentliche Einreichung (`/einreichen`, M4-2) hat bewusst
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
  ohne DOM und ohne Netz prüft. Eine Oberfläche gibt es noch nicht – die
  Erfassungsseiten kommen mit M4-6 und M5.

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

1. `extract_text` (Webhoster: PDF-Textlayer / E-Rechnungs-XML) –
   `session`/`session`
2. `render_pages` (nur PDFs ohne Seitenbilder) – `browser`/`worker`
3. `ocr` (durchsuchbares PDF/A + Text) – *entfällt*/`worker`
4. `ai_extract` (→ KI-Anbieter) – `session`/`worker`
5. `resolve_supplier` (Abschnitt 7) – immer `session`
6. `classify` (Kategorie/Kostenstelle, Regeln + KI-Vorschlag) – `session`
7. `detect_recurring` (Abschnitt 9) – `session`
8. `detect_duplicate` – `session`
9. `match_transactions` (siehe 04, falls Buchungen vorhanden) – `session`

Die Logik von `render_pages` (Server-Variante), `ocr` und `ai_extract` liegt
in `app/src/Service/Processing/` und ist framework-frei (siehe CLAUDE.md
§6a). Ergebnisse werden immer als `document_artifact` gespeichert.

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
  `merged_into` setzen, Audit).
- Lieferant hat Default-Kategorie/-Sphäre; ab dem 2. bestätigten Beleg mit
  gleicher Kategorie wird sie automatisch vorgeschlagen (Regel vor KI).

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
Viereck-Auswahl); Upload-Chunks (Reihenfolge, fehlende Chunks, Magic-Byte-
Ablehnung, Größenlimit); IBAN-Validierung; PDF-Erzeugung (Seitenzahl =
Bildzahl, gültiges PDF); Textlayer-Heuristik; ZUGFeRD-/XRechnung-Fixtures;
KI-Client mit aufgezeichneten Antworten (gültig, ungültig + Reparatur,
Timeout, HTTP-Fehler, kein JSON); Betrags-Parsing („1.234,56", „1234.56",
negativ) und Summenprüfung; Lieferanten-Auflösung über alle 6 Stufen inkl.
Rechtsform-Normalisierung; Serienerkennung (monatlich, quartalsweise,
jährlich, unregelmäßig → keine Serie, Preissteigerung innerhalb Toleranz);
Duplikaterkennung.
