# 07 – Verarbeitungs-Worker (optionales Zusatzmodul)

## 1. Grundsatz

Die Anwendung ist **ohne Worker vollständig funktionsfähig** (Browser-/
Session-Verarbeitung wie in 03 und 06 beschrieben). Der Worker ist ein
**Zusatzmodul**, das im Admin aktiviert wird und dann bestimmte Jobs
übernimmt – besser (OCR, PDF/A) und unabhängig von angemeldeten Nutzern.

- Läuft als Docker-Container auf beliebiger Hardware mit ausgehendem HTTPS
  – Referenzplattform: **Raspberry Pi 4 (arm64, Debian)**; ebenso x86-VPS.
- **Nur ausgehende Verbindungen** (Polling). Kein offener Port, keine
  öffentliche Adresse, kein DynDNS nötig.
- Der Webhoster bleibt die **einzige Datenquelle**. Der Worker speichert
  nichts dauerhaft außer seiner eigenen Konfiguration und seinem Schlüssel.
- Fällt der Worker aus, übernimmt nach einer Wartezeit automatisch wieder der
  Browser-/Session-Weg. Kein Beleg geht verloren oder bleibt hängen.
- Gleiches Repo, gleiche Sprache (PHP 8.5 CLI), **gemeinsamer Code** für
  Krypto, KI-Client, Schema-Validierung, Betrags-Parsing
  (`app/src/Service/Processing/*` darf keine Abhängigkeit zu HTTP-Request,
  Session oder Datenbank haben – so läuft er in beiden Umgebungen).

## 2. Was der Worker übernimmt

| Job | ohne Worker | mit Worker |
|---|---|---|
| `render_pages` (PDF → Seitenbilder) | Browser (pdf.js) | Poppler/Ghostscript |
| `ocr` (Textebene, PDF/A) | **entfällt** | OCRmyPDF (Tesseract `deu`+`eng`), Drehung/Schräglage korrigiert, PDF/A-2b |
| `extract_text` (digitale PDFs, E-Rechnung) | Server (Webhoster) | bleibt auf dem Webhoster (schnell, braucht keinen Worker) |
| `ai_extract` | Session-Schritt | Worker, **ohne Zeitlimit, rund um die Uhr**, mit OCR-Text + Bildern |
| `resolve_supplier`, `classify`, `detect_*`, `match_transactions` | Session | **bleibt Session** (braucht Tresor) |

Zusätzlich (kein Job, eigene Fähigkeit `mail`): **Beleg-Eingang per E-Mail**
– der Worker ruft ein IMAP-Postfach des Vereins ab und reicht Anhänge wie die
öffentliche Einreichung in den Posteingang ein (§9, issue #87, E-54).
Die Nachverarbeitung des Bestandsarchivs mit OCR ist umgesetzt
(§6 „Stand #88“).

Kein eigener Job `image_cleanup` (#248, E-70): Belege ohne
Browser-Aufbereitung bereinigt der GD-Fallback des Webhosters (03 §2); Drehung
und Schräglage korrigiert der `ocr`-Job ohnehin in seinem PDF/A.

## 3. Verschlüsselung – Minimalrechte

Der Worker erhält **nie** den Tresor-Private-Key.

- Worker hat ein eigenes Schlüsselpaar `W_pub`/`W_priv` (erzeugt auf dem
  Worker, `W_priv` verlässt ihn nie).
- **Worker-Grant je Dokument**: Wird ein Blob für ein Dokument angelegt,
  während das Modul aktiv ist, versiegelt der Webhoster den Blob-DEK
  zusätzlich an `W_pub` (`worker_grant`). Das geht ohne Geheimnis – genau
  wie die Versiegelung an den Tresor bei der öffentlichen Einreichung.
- **Worker-Kontext**: Für die KI nützliche Einreicherangaben (nur Freitext
  und Kostenstellen-Hinweis – **nicht** Name, E-Mail, IBAN) werden bei der
  Einreichung zusätzlich als eigener kleiner Umschlag an `W_pub` versiegelt.
- Ergebnisse (OCR-Text, PDF/A, Seitenbilder, KI-Auswertung) verschlüsselt
  der Worker mit neuen DEKs, versiegelt an **`VK_pub`** (Tresor) und lädt
  sie als `document_artifact` hoch. Der Worker kann eigene Ergebnisse danach
  selbst nicht mehr lesen.
- Nach Abschluss (oder Abbruch nach max. Versuchen) werden die
  `worker_grant`- und `worker_context`-Zeilen **gelöscht**.
- **Tresor-Schlüsselrotation** (01 §2, issue #90): Worker-Ergebnisse sind an
  den jeweils aktuellen `VK_pub` versiegelt (`vault.current()`, ab dem Start
  einer Rotation die neue Generation) und werden wie jede `dek_sealed`-Spalte
  von der Rotation erfasst. `worker_grant`/`worker_context` sind an `W_pub`
  versiegelt und von der Rotation nicht betroffen. Während einer Rotation
  (`vault`: mehr als eine Generation ohne `retired_at`,
  `VaultRepository::rotationInProgress()`) werden keine neuen Worker-Grants
  aus der Session heraus erteilt – die Sitzungen sind dann ohnehin gesperrt
  (umgesetzt in `WorkerFreigaben::ausSession()`, #52). Ein Ergebnis-Upload,
  dessen `dek_sealed` nicht (mehr) zur aktuellen Generation gehört, wird beim
  Festschreiben mit 409 `dek_veraltet` verworfen; der Worker holt den
  aktuellen `VK_pub` aus dem Job-Kontext und meldet neu an.
- Dokumente, die vor der Aktivierung eingingen: Ein angemeldeter Nutzer kann
  sie „an den Worker übergeben" – die Session entschlüsselt den DEK und
  versiegelt ihn an `W_pub`.
- **Folge für das Bedrohungsmodell** (Ergänzung zu 01 §1): Wird der
  Worker-Rechner gestohlen/kompromittiert, sind nur die **gerade wartenden**
  Dokumente und die an den Worker übergebenen KI-Zugangsdaten betroffen –
  nicht Archiv, Kontoauszüge, Lieferanten, Einreicher-Bankdaten.
- Temporäre Klartextdateien auf dem Worker liegen ausschließlich in einem
  `tmpfs` (RAM), werden nach jedem Job gelöscht; kein Klartext auf der
  SD-Karte.
- **Stand M7b-2 (issue #52, Migration 041, E-39):** `worker_grant` je Blob
  (worker_id, document_id, blob_id, `sealed_dek` = `crypto_box_seal(DEK,
  W_pub)`, expires_at = +3 Tage) und `worker_context` je Dokument
  (`{freitext, kostenstelle_hinweis}` an `W_pub`, Freitext auf 1000 Zeichen
  gekürzt). Erteilt von
  `App\Service\Worker\WorkerFreigaben`: `ausSession(InboxItem, Vault)`
  („an den Worker übergeben“ – alle Blobs aus `Document::blobIds()`, nur
  für den aktiven, bestätigten Worker; **verweigert während einer
  Tresor-Rotation** (#90) und mit einem Tresor, der nicht die aktuelle
  Generation ist) sowie `beiAnlage()`/`kontextBeiAnlage()` für frisch
  erzeugte DEKs ohne Geheimnis (seit #53 ungenutzt: Grants entstehen beim
  Routing aus der Session, §6 „Stand M7b-3“, E-43).
  Ein Grant öffnet einen Blob nur, solange derselbe Worker einen laufenden,
  nicht abgelaufenen Job desselben Dokuments hält. Gelöscht werden Grants
  und Kontext, sobald nach `complete`, endgültigem `fail` oder erschöpften
  Versuchen kein Worker-Job des Dokuments mehr wartet oder läuft; beim
  Entkoppeln alle des Workers; der Cron (`worker_aufraeumen`) nach
  `expires_at` und verwaiste nach 15 min. Umschläge und Formprüfungen in
  `App\Service\Worker\WorkerKrypto` (ohne HTTP/DB, auch für
  `bin/worker.php`, #54). Ergebnisse: siehe §5 „Stand M7b-2“.
  KI-Zugangsdaten: beim Heartbeat eines aktiven Workers versiegelt
  `App\Service\Worker\WorkerKonfiguration` die aktiven Anbieter-Profile
  (`{format: "worker-config-v1", ki_anbieter: [{id, name, base_url, model,
  faehigkeiten, timeout_s, standard, fallback, api_key}]}`) an `W_pub`,
  wenn sich ein HMAC darüber (`worker.config_hash`) geändert hat, und
  erhöht `config_version`.

### KI-Zugangsdaten
Konfiguriert wird **nur im Admin des Webhosters** (eine Stelle). Bei
Aktivierung/Änderung versiegelt der Webhoster die Anbieter-Profile inkl.
API-Key an `W_pub`; der Worker holt sie beim Heartbeat ab und hält sie nur
im Speicher. Zusätzlich kann auf dem Worker ein lokales Profil (z. B.
llama.cpp im Heimnetz) per Umgebungsvariable definiert werden, das der
Admin dann als Anbieter „Worker-lokal" auswählen kann.

## 4. Kopplung und Authentifizierung

1. Admin → „Worker-Modul" → „Worker koppeln" → Kopplungscode (8 Zeichen,
   15 min gültig) wird angezeigt.
2. Auf dem Pi: `docker compose run --rm worker pair https://belege.verein.de
   <code>` → Worker erzeugt Schlüsselpaar, sendet `W_pub`, erhält
   Worker-ID + Zugangsschlüssel (32 Byte, auf dem Webhoster mit dem
   Server-Schlüssel verschlüsselt – eine HMAC lässt sich nicht gegen einen
   Hash prüfen, E-28).
3. Admin sieht den Fingerabdruck von `W_pub` (auch in der Worker-Konsole
   ausgegeben), vergleicht und bestätigt. Erst dann ist der Worker aktiv.
- Jede Anfrage: `Authorization: Worker <id>` + HMAC-SHA256-Signatur über
  Methode | Pfad | Zeitstempel | Nonce | SHA256(Body) mit dem
  Zugangsschlüssel. Zeitfenster ±5 min, Nonce-Replay-Schutz (Tabelle,
  Cron räumt auf). Rate-Limit je Worker.
- Mehrere Worker sind technisch möglich, v1 unterstützt **einen** aktiven.
- Entkoppeln im Admin: Grants löschen, Zugang sperren, sofortige Rückkehr
  zum Browser-Weg.
- **Stand M7b-1 (issue #51, Migration 038, E-28):** Tabellen `worker`,
  `worker_pairing`, `worker_nonce`. Admin-Seite `/admin/worker`
  (`admin.settings`): Kopplungscode erzeugen (Crockford-Base32 `ABCD-EFGH`,
  nur als HMAC gespeichert, nur angezeigt in der Antwort, ein neuer ersetzt
  den alten; nur ohne gekoppelten Worker), Fingerabdruck bestätigen
  (SHA-256 von `W_pub`, 8 × 4 Hex-Zeichen), entkoppeln (Status `gesperrt`,
  `secret_enc` gelöscht, gehaltene Jobs sofort freigegeben). Status
  `gekoppelt` = nur Heartbeat, `aktiv` = darf Jobs beanspruchen.
  Wire-Format in `App\Service\Worker\WorkerProtokoll` (ohne
  HTTP/DB-Abhängigkeit, damit `bin/worker.php` denselben Code nutzt):
  Header `Authorization: Worker <id>` und/oder `X-Worker-Authorization`
  (gleicher Wert; hat Vorrang – für Hoster ohne `Authorization` an FastCGI
  oder eine Seite hinter HTTP-Basic),
  `X-Worker-Timestamp` (Unix-Sekunden), `X-Worker-Nonce` (16–64 Zeichen
  `[A-Za-z0-9_-]`), `X-Worker-Signature` = hex HMAC-SHA256 über
  `METHODE|PFAD|ZEIT|NONCE|hex(SHA-256(Body))`; Pfad ohne Query –
  Worker-Routen lehnen Query-Strings ab. Prüfung in
  `App\Service\Worker\WorkerAuth`, von `app/src/routes.php` aus der
  Deklaration `Zugriff::worker()` abgeleitet (wie der Login-Guard).
  Grenzen: 30 Fehlversuche je IP/15 min (429), 300 Anfragen je Worker/min;
  Kopplung 10 Fehlversuche je IP und 50 insgesamt je 15 min. Nonces räumt
  der Cron (`worker_aufraeumen`) nach 11 min, abgelaufene Codes nach einem
  Tag. Grants löschen (#52) hängt an `WorkerKopplung::entkoppeln()`, die
  Rückkehr zum Browser-Weg (#53) löst die Admin-Seite direkt danach aus
  (`WorkerFallback::ausfuehren()`).

## 5. Protokoll (Webhoster-API `/api/worker/*`)

| Endpunkt | Zweck |
|---|---|
| `POST /pair` | Kopplung (nur mit gültigem Code) |
| `POST /heartbeat` | Version, Protokollversion, Fähigkeiten (`ocr`, `render`, `ai`), freie Ressourcen; Antwort: versiegelte Konfiguration falls geändert, Anzahl wartender Jobs |
| `POST /jobs/claim` | bis zu N Jobs übernehmen (setzt `locked_until`, z. B. 30 min) |
| `GET /blobs/{id}` | Chiffrat streamen + an den Worker versiegelter DEK (nur mit gültigem Grant für einen beanspruchten Job desselben Dokuments) |
| `GET /jobs/{id}/context` | Worker-Kontext + nicht-sensible Stammdaten für den Prompt (Kategorien mit `ai_hint`, Kostenstellen, Settings) |
| `POST /jobs/{id}/artifacts` (+ `…/{a}/chunks/{n}`, `…/{a}/commit`) | Ergebnis-Upload (Chunk-Upload wie 03 §4), bereits an `VK_pub` verschlüsselt |
| `POST /jobs/{id}/complete` / `/fail` | Abschluss bzw. Fehler mit Meldung (ohne Klartext-Inhalte) |
| `POST /jobs/{id}/release` | Rückgabe beim Beenden, ohne einen Versuch zu verbrauchen (#248) |

- Polling-Intervall: 60 s ohne Arbeit, sofort weiter, solange Jobs warten.
- **Protokollversion**: Webhoster lehnt inkompatible Worker mit klarer
  Meldung im Admin ab („Worker-Update nötig").
- Idempotenz: Artefakte tragen eine Job-Versuchs-ID; doppelte Uploads
  werden erkannt.
- **Stand M7b-1 (issue #51):** umgesetzt sind `pair`, `heartbeat`,
  `jobs/claim`, `jobs/{id}/extend` (zusätzlich: Sperre um 30 min
  verlängern, für lange OCR/KI-Läufe), `jobs/{id}/complete`,
  `jobs/{id}/fail`
  (`App\Api\WorkerApiController`, JSON, Fehler als `{fehler, meldung}`);
  `blobs`, `context`, `artifacts` siehe „Stand M7b-2“. Protokollversion 1
  (`WorkerProtokoll::VERSION`); inkompatibel → 409 `protokoll` „Worker-Update
  nötig“ – bei `pair` ohne den Code zu verbrauchen, beim Heartbeat wird die
  Version trotzdem gespeichert (Admin-Anzeige) und Claims werden abgelehnt.
  - `pair` `{code, public_key (Base64, 32 Byte X25519), name, version,
    protokoll_version}` → `{worker_id, schluessel (Base64), fingerabdruck,
    status, protokoll_version}`.
  - `heartbeat` `{protokoll_version, version, faehigkeiten[], config_version}`
    → `{status, server_zeit, poll_sekunden, wartend, config}` (`wartend` =
    jetzt beanspruchbare Jobs, nicht die gerade bearbeiteten); `config` =
    `{version, versiegelt}` nur, wenn `worker.config_version` neuer ist
    (KI-Zugangsdaten, seit #52, §3). Freie Ressourcen werden noch nicht
    ausgewertet.
  - `jobs/claim` `{max ≤ 5}` → `{jobs: [{id, typ, ref_type, ref_id, step,
    versuch, lock, gesperrt_bis}]}`, nur `executor = worker` und nur Typen
    der gemeldeten Fähigkeiten (`ocr` → `ocr`, `render` →
    `render_pages`, `ai` → `ai_extract`); Sperre 30 min, je Job ein eigenes
    `lock` (`w<id>-<zufall>`); ein Job über 3 Versuche wird beim Claim
    beendet statt ausgegeben. `versuch` (= `attempts`) ist die
    Job-Versuchs-ID für die Artefakt-Idempotenz (#52).
  - `extend`/`complete` `{lock}`, `fail` `{lock, fehler, endgueltig}`: nur
    mit der eigenen Sperre (sonst 409 bzw. 404 für fremde/unbekannte Jobs);
    ein wiederholtes `complete` desselben Holders (Antwort verloren) wird
    mit 200 beantwortet – `locked_by` bleibt dafür am fertigen Job stehen;
    `fehler` nur als Bezeichner (`[A-Za-z][A-Za-z0-9_.:\\-]*`, also auch
    Klassennamen mit `\`; sonst `WorkerFehler`),
    nie Text; ohne `endgueltig` geht der Job bis zum 3. Versuch zurück auf
    `offen`.

- **Stand M7b-2 (issue #52, E-39):**
  - `GET /blobs/{id}` → Chiffrat als Stream, Header `X-Worker-Dek`
    (Base64, Grant an `W_pub`), `X-Blob-Header` (Base64, secretstream-Header),
    `X-Blob-Groesse` (Klartext-Bytes). Nur mit Grant dieses Workers **und**
    einem laufenden, nicht abgelaufenen Claim auf einen Job desselben
    Dokuments, sonst einheitlich 403 `kein_grant`.
  - `GET /jobs/{id}/context` (nur für einen gehaltenen Job, sonst 404) →
    `{job, dokument: {id, blobs[], kontext (Base64|null)}, tresor:
    {version, public_key}, artefakte[] (erlaubte Kinds), stammdaten:
    {kategorien[{id, name, richtung, ki_hinweis}], kostenstellen[{id,
    name}]}}`. `tresor` = aktuelle Generation (`vault.current()`); daran
    versiegelt der Worker seine Ergebnisse. Seit #54 (E-50) zusätzlich
    `dokument.originale` (Blob-IDs der Originale in Seitenreihenfolge),
    `dokument.verarbeitet` (parallel dazu der verarbeitete Scan, nur wenn
    gewährt, sonst `null`) und `dokument.pdf` – nur IDs, damit der Worker
    `text`/`pdfa` je Original nummerieren kann.
  - Ergebnis-Upload in drei Schritten, weil das AAD von `data_enc` die
    Zeilen-ID enthält:
    1. `POST /jobs/{id}/artifacts` `{lock, versuch, kind, seq, dek_sealed,
       groesse, header?, sha256?}` (Binärwerte Base64; `header` und
       `sha256` (hex des Chiffrats) nur für Blob-Kinds) → 201 `{status:
       "offen", artefakt_id, aad, chunks, chunk_bytes, fehlend[]}` bzw.
       200 `{status: "vorhanden"}`. Legt die `document_artifact`-Zeile mit
       Kind-Präfix `~` an (für Leser unsichtbar) und `worker_upload`.
       Geprüft: Sperre, `versuch` = aktuelle `attempts`, Kind je Job-Typ
       (`render_pages` → `page_image`; `ocr` → `pdfa`, `text`;
       `ai_extract` → `extraction`),
       `dek_sealed` = 81 Byte mit Versionsbyte der aktuellen
       Tresor-Generation, Header-Format, Größe (Blob ≤ 64 MiB, `data_enc`
       ≤ 4 MiB), höchstens 10 offene Uploads je Worker.
    2. `POST /jobs/{id}/artifacts/{a}/chunks/{n}` – Rohkörper = Chiffrat
       (je 2 MiB), Ablage in `shared/var/tmp/upload/worker/` (nur
       Chiffrat). Ohne Sperre im Request; gebunden an Worker, Job und
       Versuch der Anmeldung, der Job muss noch gehalten sein.
    3. `POST /jobs/{id}/artifacts/{a}/commit` `{lock}` → Länge (und bei
       Blobs SHA-256) geprüft, Blob per `BlobService::storeCipher()` bzw.
       `data_enc` gesetzt und Kind-Präfix entfernt – in **einer**
       Anweisung; Wiederholung → `vorhanden`.
  - Blob-Artefakte haben kein `meta_enc` (AAD mit Blob-ID); das Kind sagt,
    was es ist. Ein nicht festgeschriebener Upload verfällt mit dem Job
    (`complete`/`fail`), beim Entkoppeln und per Cron nach einem Tag.
  - Wer die Ergebnisse in den Ablauf übernimmt: siehe „Stand M7b-3“.

- **Stand M7b-3 (issue #53, E-43):**
  - `complete` reiht den Session-Job `worker_uebernahme` ein
    (`App\Service\Worker\WorkerUebernahme`, State `{job, typ}`). Er prüft
    jedes Artefakt des Laufs mit dem Tresor; was nicht entschlüsselt oder
    nicht die Form der Session-Artefakte hat, bekommt das Kind-Präfix `!`
    (unsichtbar, im Admin gezählt). Formen: `text` = `{brauchbar, seiten[]}`
    (wie `extract_text`; genau ein Text je Original, `seq` 0 … n−1, jeder
    mit mindestens einer nicht-leeren Seite – sonst wird der ganze Lauf
    markiert), `extraction` = `{versuch, meldung, extraktion, hinweise}`
    (wie `ai_extract`; ein als gültig gemeldeter Versuch muss mit seinen
    `daten` serverseitig das Schema `extract-v1` erfüllen; `meldung`,
    `extraktion` und `hinweise` des Workers werden verworfen und mit
    `Auslesung::auswerten()` neu gebildet), `page_image`/`pdfa` =
    vollständiger Blob mit öffnendem DEK. Folgen: `ocr` vollständig und
    durchweg brauchbar → `ocr_status = fertig` (vollständig, aber
    unbrauchbar → bleibt nur für die Suche); `ai_extract` → Status `ausgewertet`/`ki_fehler`
    wie die Session-Kette, ohne brauchbaren Versuch Session-`ai_extract`;
    `render_pages` mit einer defekten Seite → ganzer Lauf verworfen,
    Browser rendert.
  - Leser (Texterkennung, E-Rechnung, KI-Auszug, Volltext-Index) werten
    ein Worker-Artefakt, das nicht entschlüsselt oder kein JSON ist, als
    fehlend (`App\Service\Document\ArtefaktJson`) – schon vor der
    Übernahme. Der Volltext-Index merkt sich reservierte Zeilen, die sein
    Cursor passiert, und nimmt sie nach dem Festschreiben auf.
  - Endgültiges `fail` und erschöpfte Versuche reihen den Browser-/
    Session-Gegenpart ein (`WorkerFallback::nachFehlschlag()`).
  - Mit ausgeschaltetem Modul liefert `jobs/claim` keine Jobs und der
    Heartbeat `wartend = 0`; die KI-Profile samt API-Keys gehen nur an einen
    Worker, der bei aktivem Modul `ai` meldet – sonst eine leere Liste.
  - Ein Worker-`ai_extract` wird erst beansprucht, wenn für dasselbe
    Dokument kein `pdf_erzeugen`, `extract_text`, `render_pages` oder `ocr`
    mehr wartet.

- **Vorgaben für `bin/worker.php` (#54)** aus den Reviews von #52/#53:
  - Jeder Versuch einer Anfrage – auch die Wiederholung nach Timeout oder
    Netzfehler – bekommt einen **neuen Nonce** (und einen neuen
    Zeitstempel); ein wiederholter Nonce wird als Replay abgelehnt.
  - Die Webhoster-Adresse beim `pair` und in der Konfiguration muss
    `https://` sein; `http://`-URLs lehnt der Worker ab (Zugangsschlüssel,
    Grants und Ergebnisse gingen sonst unverschlüsselt über die Leitung –
    die HMAC schützt nur die Integrität).
  - Eine neue Konfiguration mit leerer `ki_anbieter`-Liste ersetzt die alte
    vollständig – zuvor erhaltene API-Keys verwirft der Worker.
  - Ergebnisformate wie oben; `text` je Original eine Zeile.

- **Stand #248 (E-70):**
  - `POST /jobs/{id}/release` `{lock}` (signiert, Nonce, wie alle
    Worker-Routen): nur mit der eigenen Sperre; der Job geht zurück auf
    `offen`, `attempts` sinkt um eins (die abgebrochene Bearbeitung zählt
    nicht als Versuch), die Grants bleiben. Das gilt für höchstens **3**
    Rückgaben je Job (`MAX_FREIGABEN`, Zähler `freigaben` in `job.state`);
    danach behält der Job den Versuch, damit eine Neustart-Schleife (z. B.
    ein Update-Timer kürzer als ein langer Lauf) trotzdem endet und
    zurückfällt. `locked_by` bleibt stehen –
    eine Wiederholung derselben Rückgabe (Antwort verloren) wird mit 200
    beantwortet, solange niemand den Job neu beansprucht hat; eine fremde
    oder veraltete Sperre → 409, unbekannter Job → 404.
  - **Artefakte abgebrochener Versuche** werden verworfen – offene Uploads
    und schon festgeschriebene Ergebnisse samt Blobs
    (`WorkerArtefakte::abgebrochenenVersuchVerwerfen()`): bei `release`,
    bei `fail` ohne `endgueltig` und bei jedem Claim (auch wenn das Aufräumen einer Rückgabe scheiterte; ein abgestürzter
    Worker lässt die Sperre ablaufen). Damit findet `vorhanden` beim
    Anmelden nur Ergebnisse des laufenden Versuchs, nie einen halben Lauf
    eines früheren – und die nach `release` wiederverwendete Versuchsnummer
    ist unschädlich. Als defekt markierte (`!`) bleiben für die Zählung im
    Admin.
  - **KI-Aufrufe des Workers im KI-Protokoll** (`ai_call_log`): Die
    Übernahme (`KiAuslesung::workerLaufUebernehmen()`) schreibt je
    wohlgeformtem Versuch eine Zeile wie die Session – Profil, Modell,
    Prompt-Version, Status, Tokens, Dauer, nie die Rohantwort. Name und
    Modell stammen aus dem eigenen Anbieter-Profil (unbekanntes Profil:
    „Worker“, Modell nur als Kennung), Zahlen werden auf ihre Spalten
    begrenzt – der Worker kann so keinen Text ins Klartext-Protokoll
    schreiben. Zeitpunkt
    ist der der Übernahme (der Worker meldet keine Aufrufzeit). Eine
    wiederholte Übernahme protokolliert nicht doppelt (Marke im
    verschlüsselten Artefakt). Versuche abgebrochener oder endgültig
    gescheiterter Läufe erscheinen nicht.

## 6. Routing auf dem Webhoster

- Setting `worker_modul_aktiv` (Default aus).
- Ist das Modul aktiv **und** der Worker online (Heartbeat < 5 min), werden
  worker-fähige Jobs mit `executor = worker` angelegt.
- Worker offline > `worker_fallback_minuten` (Default 60): offene
  Worker-Jobs fallen auf `executor = session|browser` zurück; `ocr` wird
  übersprungen und kann später nachgeholt werden („OCR nachholen" im
  Posteingang und als Sammelaktion).
- Admin-Seite „Worker": Status (online/offline, letzte Meldung, Version,
  Fähigkeiten), Warteschlange, letzte Fehler, Durchsatz, koppeln/
  entkoppeln. Alarm-Mail, wenn der Worker > 24 h offline ist und Jobs
  warten.
- **Stand M7b-3 (issue #53, E-43):**
  - `App\Service\Worker\WorkerRouting`: Modul an, Worker `aktiv`,
    Protokoll unterstützt, Heartbeat < 5 min, Fähigkeit für den Typ →
    Worker. `App\Service\Worker\WorkerUebergabe::einreihen()` erteilt
    dann die Grants aus der Session (`WorkerFreigaben::ausSession()`) und
    reiht mit `executor = worker` ein, sonst Browser/Session bzw. – bei
    `ocr` – nichts. Eingehängt in `pdf_erzeugen` (`render_pages`),
    `extract_text` (`ocr`, wenn die Seitenbilder gebraucht werden: keine
    PDF/XML-Quelle oder unbrauchbare Textebene) und das Annehmen im
    Posteingang (`ai_extract`). Je Typ und Dokument höchstens ein
    wartender Job.
  - Rückfall: `WorkerFallbackTask` bei **jedem** Cron-Lauf
    (`App\Service\Worker\WorkerFallback`) sowie sofort beim Ausschalten
    und Entkoppeln. Freie Worker-Jobs (offen bzw. Sperre abgelaufen) gehen
    zurück, wenn das Modul aus ist, kein einsatzbereiter Worker da ist oder
    er seit `worker_fallback_minuten` schweigt; Jobs eines Typs, dessen
    Fähigkeit er nicht mehr meldet, sofort. `render_pages` → `browser`,
    `ai_extract` → `session` (an Ort und Stelle, Schritt/Versuche
    zurückgesetzt), `ocr` → `uebersprungen`
    (`WorkerAusfall`). Grants gehen mit dem letzten Worker-Job; was der
    Job schon festgeschrieben hatte, wird verworfen (Präfix `!`), weil der
    umgeleitete Job seine ID behält. Ebenso nach endgültigem `fail`.
  - „OCR nachholen“ (Admin, Sammelaktion, 50 je Klick, Tresor entsperrt):
    Belege mit `ocr_status = uebersprungen`, nicht festgeschrieben, ohne
    wartenden/fertigen `ocr`-Job und ohne übernommenen Worker-Text (ein
    verworfener/defekter blockiert nicht). Seit #248 auch **je Beleg** in
    der Posteingangs-Ansicht (`POST /app/posteingang/{id}/ocr-nachholen`,
    `document.edit`, Tresor entsperrt, dieselbe Regel; der Knopf erscheint
    nur, wenn der Beleg nachholbar ist und der Worker OCR annimmt).
  - Admin-Seite: Modul-Schalter (Einschalten nur mit entsperrtem Tresor)
    und Wartezeit, online/offline, Warteschlange je Typ, erledigt in 24 h,
    verworfene Ergebnisse, letzte Fehler (Typ, Code, Zeit), „OCR
    nachholen“.
  - Alarm-Mail (`worker-ausfall`) an alle aktiven Konten mit
    `admin.settings`: Modul an, Worker > 24 h ohne Meldung, in diesem
    Ausfall Jobs zurückgefallen oder noch frei; einmal je Ausfall.
- **Stand #88 (OCR-Nachverarbeitung des Bestandsarchivs, E-55):**
  - Belege aus dem Archiv-Import (`source = archiv`, 05 §3) gehen **nicht**
    einzeln an den Worker: `extract_text` lässt `ocr_status =
    uebersprungen` stehen, „OCR nachholen“ lässt sie aus.
  - Stattdessen Admin-Seite „Worker“, Abschnitt „Archiv nachträglich
    durchsuchbar machen“: Auswahl eines Import-Laufs (dessen
    Dokument-ID-Bereich aus `archive_import.stats`) oder aller
    Archiv-Belege, Start nur mit entsperrtem Tresor, immer nur ein Lauf,
    Abbrechen. Fortschritt: Belege mit Textebene / gesamt, beim Worker,
    noch ohne, in diesem Lauf übergeben/ausgelassen.
  - Ablauf: Session-Job `bestand_ocr` (`App\Service\Worker\BestandsOcr`,
    Recht `admin.settings`). Kandidaten: Archiv, `ocr_status =
    uebersprungen`, nicht `abgelehnt` – **festgeschriebene eingeschlossen**
    –, ohne wartenden/fertigen `ocr`-Job, ohne übernommenen Worker-Text.
    Je Schritt höchstens 25 geprüft; die Worker-Warteschlange für `ocr`
    wird nur bis **5** aufgefüllt (OCR-Jobs neuer Belege zählen mit, der
    Worker holt die ältesten zuerst). Voll → 20 s warten, Worker nicht
    verfügbar oder keine Grants möglich (Tresor-Rotation) → 5 min warten,
    Zeiger bleibt stehen; zwischen zwei Schritten 2 s Pause. Am Ende ein
    weiterer Durchlauf von vorn, solange im Bereich noch eine eigene
    Texterkennung (`extract_text`) aussteht oder OCR-Jobs beim Worker
    sind. Ein Beleg, dessen `ocr`-Job in diesem Lauf endgültig
    gescheitert ist (`fehler`), ist kein Kandidat mehr (angezeigt als
    „gescheitert“; ein neuer Lauf versucht ihn erneut). Nur abgeschlossene
    Import-Läufe sind wählbar; Prüfen und Anlegen des Laufs unter
    `GET_LOCK`, damit zwei gleichzeitige Starts nur einen Lauf ergeben. Zeiger und Zähler in
    `job.state` → Wiederaufnahme nach Pause, Abmelden, Worker-Ausfall.
    Der Job zählt nicht zur Kopfzeile „N Belege in Verarbeitung“
    (`App\Service\Job\HintergrundJob`).
  - Festschreibung (01 §7): Die Übernahme ergänzt nur Text-Artefakte,
    `ocr_status` und ein PDF/A-Artefakt; Angaben, Original und
    `pdf_blob_id` bleiben unverändert, kein KI-Auslesen folgt.

## 7. Paketierung und Betrieb

- `worker/Dockerfile` im Repo (Build-Kontext: Repo-Wurzel), Basis das
  offizielle `php:8.5.x-cli-trixie` (Debian trixie, auf eine
  PHP-Patchversion gepinnt – Debians eigenes `php8.x-cli` ist 8.4, das
  Projekt zielt auf 8.5, E-53), dazu aus Debian `ocrmypdf`,
  `tesseract-ocr-deu`/`-eng`/`-osd`, `ghostscript`, `poppler-utils`,
  `unpaper`, `tini` (PID 1: leitet SIGTERM weiter, räumt Enkelprozesse
  ab) und die PHP-Erweiterungen `pcntl`, `gd`, `zip`, `pdo_mysql`
  (+ sodium, curl, mbstring aus dem Basis-Image). Der Build bricht ab, wenn
  eine Erweiterung oder ein Werkzeug (inkl. Sprachdaten) fehlt;
  `bin/worker.php run` verweigert den Start ohne `pcntl` (sonst gäbe
  SIGTERM den laufenden Job nicht zurück). Läuft als Benutzer `worker`
  (UID 10001). Worker-Code = `bin/worker.php` + `worker/src/`
  (`App\Worker`, nicht im Release-ZIP) + gemeinsames `app/src` +
  `app/prompts` + Composer-Autoloader ohne Dev-Pakete (§8).
- GitHub Action `worker-image.yml` baut bei jedem Tag ein
  **Multi-Arch-Image** (`linux/arm64` + `linux/amd64`, buildx/QEMU) nach
  `ghcr.io/mirkosc/vereinsbelege-worker` mit Tags `vX.Y.Z` und `beta`
  (jedes Tag, `beta` nur für das höchste Versions-Tag) – vorher läuft der End-to-End-Test mit dem amd64-Image. Beim
  Hochstufen eines Releases auf „latest“ (Ereignis `release: released`,
  nur wenn es das neueste Release ist) zeigt `stable` auf das schon
  getestete `vX.Y.Z` (kein Neubau; war das Image beim Hochstufen noch
  nicht gepusht, setzt der Tag-Build `stable` nach). Push nur mit dem `GITHUB_TOKEN` des
  Workflows (`packages: write` nur im Job `publish` für Tag-Pushes; PR-Builds
  und die CI laufen mit `contents: read`, Checkout ohne gespeicherte
  Zugangsdaten), kein gespeichertes Secret. PRs, die
  Worker/Composer/Workflow ändern, bauen das Multi-Arch-Image nur.
- `worker/docker-compose.yml` als Vorlage: `restart: unless-stopped`,
  `tmpfs` für das Arbeitsverzeichnis (`/arbeit`), Volume nur für
  Konfiguration + Schlüssel (`/daten`, Identitätsdatei 600), Speicherlimit
  (`mem_limit` – tmpfs-Seiten zählen mit), Logrotation,
  `stop_grace_period: 30s` (Werkzeug beenden ≈ 5 s + `fail` ≤ 10 s; Docker
  wartet sonst nur 10 s), schreibgeschütztes Root-Dateisystem, keine
  Capabilities.
- Updates: `docker compose pull && docker compose up -d` (Doku), optional
  automatisch per systemd-Timer; der Admin zeigt „Worker-Version veraltet",
  sobald die beim Heartbeat gemeldete Version älter als die des Webhosters
  ist (`WorkerProtokoll::versionVeraltet()`, `version_compare` auf
  `X.Y.Z[-…]`; Entwicklungsstände `-dev` und Unlesbares nie) – nur ein
  Hinweis, der Worker arbeitet weiter. Abgelehnt wird er erst bei einer
  nicht unterstützten Protokollversion („Worker-Update nötig“, #248).
- End-to-End-Test: `tests/e2e/worker/run.sh` (CI-Job `worker-e2e` und vor
  jedem Image-Push) startet Webhoster-Container (Dev-Image + Apache-TLS +
  MariaDB) und Worker-Container mit den Einstellungen der Vorlage. Eine
  nur für den Lauf erzeugte Test-CA wird dem Worker als einzige
  vertrauenswürdige CA mitgegeben (`curl.cainfo`), die TLS-Prüfung bleibt
  an. Ablauf: koppeln, Fingerabdruck vergleichen, bestätigen, Fixture-Scan
  (`tests/fixtures/worker/scan-e2e.jpg`, synthetisch) einreichen, OCR an
  den Worker, Übernahme wie in einer Session, Prüfung von OCR-Text und
  PDF/A im Posteingang, `docker stop` mit Exit-Code 0, kein Belegtext in
  den Logs. Die Webhoster-Seite spielt `tests/e2e/worker/treiber.php`.
- Einrichtungsanleitung für den Pi: `docs/worker-einrichtung.md` (M7b-6).

## 8. Worker-Programm (`bin/worker.php`)

**Stand M7b-4 (issue #54, E-50).** Einstieg `bin/worker.php` (nur CLI),
Code in `worker/src/` (Namespace `App\Worker`, eigener Autoloader
`worker/autoload.php` – bewusst nicht in `composer.json`, damit die
Classmap des Webhosters ihn nicht kennt). `worker/` und `bin/worker.php`
kommen **nicht** ins Release-ZIP (`release.yml`: `zip -d … bin/worker.php`). Er nutzt die gemeinsamen Klassen der
Web-App: `WorkerProtokoll` (Signatur), `WorkerKrypto` (Grants, Kontext,
Versiegeln an `VK_pub`), `BlobCipher`/`FieldCipher`, `Service/Processing`
(`Auslesung`, `Textlayer`, `PdfTextlayer`, E-Rechnung, `PngZuJpeg`,
`JpegInfo`) und `Service/Ki` (`KiAbfrage`, `OpenAiCompatibleClient`).
`worker/src/` hängt wie `Service/Processing` nicht an Http, Session,
Repository oder Datenbank (`ProcessingArchitectureTest`).

- **Befehle:** `pair <https://…> <CODE> [name] [--neu]` (Schlüsselpaar
  erzeugen, koppeln, Fingerabdruck ausgeben und mit dem des Webhosters
  vergleichen; nie wiederholt), `run` (Standard), `status`.
- **Konfiguration** per Umgebung: `WORKER_DATEN` (Volume mit
  `worker.json`: ID, Zugangsschlüssel, `W_pub`/`W_priv`, Adresse – Modus
  0600, das Einzige, was dauerhaft gespeichert wird), `WORKER_ARBEIT`
  (Arbeitsverzeichnis, **muss** tmpfs/ramfs sein), `WORKER_NAME`,
  `WORKER_FAEHIGKEITEN` (z. B. `ocr,render` – schränkt die Meldung ein),
  `WORKER_SPEICHER` (PHP-Speichergrenze, Standard 1024M),
  `WORKER_ERLAUBE_HTTP_LOCALHOST=1` (nur Tests, nur localhost).
- **Fähigkeiten:** `render` wenn `pdfinfo`+`pdftoppm` da sind, `ocr` wenn
  `ocrmypdf`+`tesseract` da sind, `ai` immer (die Schlüssel kommen vom
  Webhoster).
- **Schleife:** Heartbeat → neue Konfiguration übernehmen (ersetzt die
  alte vollständig; leere Liste, nicht zu öffnender Umschlag oder fremdes
  Format → alle Schlüssel weg) → nur mit `status = aktiv` und `wartend > 0`
  ein Claim (`max 1`) → Job verarbeiten → sofort weiter; sonst
  `poll_sekunden` warten. Fehler (nicht erreichbar, 5xx, Protokoll,
  entkoppelt) beenden die Schleife nie: Rückzug 5 s, 10 s … 5 min (±20 %),
  der nächste gute Heartbeat setzt zurück.
- **Signierte Anfragen** (`App\Worker\ApiClient`): jeder Versuch mit
  neuem Nonce und Zeitstempel; beide Auth-Header (`Authorization`,
  `X-Worker-Authorization`); signiert wird der Pfad, wie er beim Webhoster
  ankommt (Pfadteil der Basisadresse + API-Pfad). Wiederholt werden
  Netzfehler, 429, 500, 502–504 (bis 4 Versuche, 2/4/8 s), sonst nichts.
  Uhrzeit: Versatz aus `server_zeit` bzw. einmal aus dem `Date`-Header
  einer 401. Nur `https://` (`UrlRegel`), keine Weiterleitungen, Antworten
  höchstens 80 MiB.
- **Ein Job** (`App\Worker\JobLauf`): Kontext holen; Blobs als Chiffrat
  laden und mit dem Grant (`X-Worker-Dek`, an `W_pub`) öffnen – Klartext nur
  im Speicher bzw. als Datei im Job-Verzeichnis; Ergebnisse unter frischen
  DEKs verschlüsseln, DEK sofort an `VK_pub` versiegeln, dreistufig
  hochladen (anmelden, fehlende Chunks, festschreiben; `vorhanden` =
  erledigt). `dek_veraltet`/`dek_ungueltig` (Tresor-Rotation) → Kontext neu
  holen, einmal neu versiegeln. Die Sperre wird alle 10 min verlängert
  (`extend`). Danach `complete`; Fehler als Bezeichner über `fail`
  (`endgueltig` nur, wenn die Eingabe selbst das Problem ist).
- **Schritte:**
  - `render_pages`: jedes PDF-Original Seite für Seite mit `pdftoppm`,
    Grenzen wie der Browser-Weg (150 DPI, längste Seite ≤ 4000 px, ≤ 2 MiB,
    Qualität 85 → 70 → 50, höchstens 200 Seiten), `seq` über alle PDFs
    fortlaufend. Kaputtes/verschlüsseltes PDF → endgültig.
  - `ocr`: je Original (`seq` = seine Stelle) `ocrmypdf --output-type
    pdfa-2 -l deu+eng --rotate-pages --deskew --force-ocr --sidecar`
    (Bilder mit `--image-dpi 300`, verarbeiteter Scan bevorzugt) → `pdfa`
    und `text` `{befund, brauchbar, seiten}` („brauchbar“ nach
    `Textlayer`). Nicht lesbares Original oder kein Text → endgültig.
  - `ai_extract`: Eingabe wie E-50 beschrieben, Kette wie die Session
    (Standardprofil, Reparatur oder Ausweichprofil + Reparatur, höchstens
    drei Aufrufe), jeder Versuch ein `extraction`-Artefakt
    `{versuch, meldung, extraktion, hinweise}` (`seq` = Nummer).
  - Unbekannter Typ → endgültig (`JobTypNichtUnterstuetzt`).
- **Prozess-Grenze** (CLAUDE.md §1, E-50): Programme startet nur
  `worker/src/Prozess.php` (`proc_open()` mit Argumentliste, keine Shell,
  reduzierte Umgebung, Zeitlimit, SIGTERM → SIGKILL), aufgerufen nur über
  `PosixWerkzeuge`. Werkzeug-Ausgaben werden gelesen, nie geloggt.
- **Zwischendateien:** nur im tmpfs-Arbeitsverzeichnis, je Job ein eigenes
  Verzeichnis (0700), im `finally` gelöscht; Reste räumt der Start weg.
  `HOME`/`TMPDIR` der Werkzeuge zeigen hinein.
- **Beenden:** SIGTERM/SIGINT → kein neuer Claim; ein laufendes Werkzeug
  wird beendet, eine laufende Übertragung abgebrochen, der Job mit
  `release` zurückgegeben (kein Versuch verbraucht, kurzes Zeitlimit, #248);
  kennt der Webhoster die Route nicht (älteres Release: 404/405 ohne
  `job_unbekannt`), wie früher `fail` ohne `endgueltig` (`WorkerBeendet`).
  KI-Schlüssel werden verworfen.
- **Log:** JSON-Zeilen auf stderr, nur Ereignis, IDs, Zähler, Dauer,
  HTTP-Status und Fehlercodes; alles andere verwirft `App\Worker\Log`.

## 9. E-Mail-Eingang (IMAP)

**Stand issue #87 (E-54, vorläufig).** Rechnungen, die per Mail kommen,
landen ohne Umweg im Posteingang. Der Worker liest dafür ein **eigenes
Postfach des Vereins** (z. B. `belege@verein.de`), der Webhoster bleibt
Datenquelle und -senke; ohne Worker gibt es keinen Mail-Eingang.

**Abnahmekriterien** (beim Einplanen ergänzt, autonom formuliert):
1. Admin (`admin.settings`) richtet unter `/admin/worker` → „E-Mail-Eingang“
   Server, Port, Verschlüsselung (**nur** SSL/TLS oder STARTTLS), Benutzer,
   Passwort, Ordner, optional Zielordner, optional Absender-Allowlist und das
   Abrufintervall ein. Eingereicht wird nur, was seit dem Einschalten
   ankam. Passwort und Allowlist liegen nur unter dem
   Server-Schlüssel, das Passwort wird nie wieder angezeigt; Audit
   `einstellung.mail_eingang` nur mit Feldnamen.
2. Der Zugang geht nur **an `W_pub` versiegelt** an den Worker (Feld
   `mail_eingang` der Worker-Konfiguration) – und nur mit Modul an,
   E-Mail-Eingang an, vollständigem Zugang und gemeldeter Fähigkeit `mail`.
   Sonst ersetzt eine Konfiguration ohne Zugang die alte; der Worker verwirft ihn.
3. Der Worker verbindet sich nur per TLS (IMAPS oder erzwungenes STARTTLS,
   TLS ≥ 1.2), prüft Zertifikat und Hostnamen und sendet Zugangsdaten nie
   unverschlüsselt.
4. Jede neue Mail mit mindestens einem brauchbaren Anhang (PDF, JPEG, PNG,
   E-Rechnungs-XML nach Magic Bytes) wird **ein** Beleg im Posteingang –
   `submission` + `document` (`source = einreichung`) wie die öffentliche
   Einreichung, verschlüsselt an `VK_pub`, dieselben Session-Jobs, dieselbe
   Benachrichtigung an den Posteingang, **keine** Bestätigungsmail an den
   Absender. Absender und Betreff stehen nur im versiegelten Payload.
5. Grenzen der öffentlichen Einreichung (Seiten, Dateigröße, Gesamtgröße);
   zu große Mails, Spam-Markierung des Mailservers, Absender außerhalb der
   Allowlist (auch serverseitig geprüft), keine/zu viele/zu große Anhänge →
   nicht eingereicht, als abgelehnt markiert. Höchstens 20 Mails je Abruf und
   200 je Tag.
6. Idempotent: eine Mail wird nach Neustart, verlorener Markierung oder
   doppeltem Eingang nie zweimal eingereicht.
7. Verarbeitete Mails werden markiert und optional verschoben, **nie
   gelöscht** (kein `\Deleted`, kein `EXPUNGE`).
8. Keine Mailinhalte (Absender, Betreff, Dateinamen) in Logs; der Admin sieht
   Zeit, Fehlercode und Zähler des letzten Abrufs.

**Webhoster.** Einstellungen `App\Service\Worker\MailEingangEinstellungen`/
`MailEingangSpeicher` (Settings siehe 02 „Betrieb“). Fähigkeit
`WorkerFaehigkeit::Mail` (beansprucht keinen Job-Typ),
`WorkerRouting::mailZugangErlaubt()`. Die Worker-Konfiguration (Format
`worker-config-v1`, um den Schlüssel `mail_eingang` erweitert; ältere
Worker ignorieren ihn) trägt `{host, port, sicherheit, benutzer, passwort,
ordner, ziel_ordner, absender[], abruf_minuten, max_seiten,
max_datei_bytes, max_einreichung_bytes}` oder `null`. Heartbeat nimmt
optional `mail_status {zeit, fehler, eingereicht, abgelehnt}` an.

API (`App\Api\WorkerMailController`, signiert wie alle Worker-Routen;
ohne Freigabe 409 `mail_eingang_aus`):

| Endpunkt | Zweck |
|---|---|
| `POST /api/worker/mail/pruefen` `{schluessel}` | `{vorhanden, referenz}` – schon eingereicht? |
| `POST /api/worker/mail/uploads` `{schluessel, groesse}` | Upload-Ticket wie 03 §4 (Grenze `einreichung_max_datei_mb`) |
| `POST /api/worker/mail/uploads/{u}/chunks/{n}` | Rohkörper = Klartext-Teil (TLS), Ablage wie die öffentliche Einreichung |
| `POST /api/worker/mail/uploads/{u}/finish` `{schluessel, name}` | Magic Bytes (PDF/JPEG/PNG/XML, kein HEIC → 415), Blob an `VK_pub` (`UploadStore`), Bindung an die Mail in `submission_upload` |
| `POST /api/worker/mail/einreichen` `{schluessel, blobs[], absender{name, adresse}, betreff}` | 201 `eingereicht` / 200 `vorhanden` mit Referenz, 422 `{fehler}` |

`App\Service\Submission\MailEinreichung`: `form_hash` =
SHA-256(`mail|` + Schlüssel), Schlüssel = hex SHA-256 über die Message-ID
(ohne Message-ID über die ganze Mail). Payload `{name, freitext, email,
kanal: "mail"}` (Name = Anzeigename, sonst Adresse; Freitext = Betreff, leer
„(E-Mail ohne Betreff)“; keine Erstattung, keine Kostenstelle). Prüft erneut
Allowlist, Seitenzahl, Gesamtgröße und dass jeder Blob unter dieser Mail
hochgeladen wurde. Audit `einreichung.eingegangen` mit `{kanal: mail}`.
Nicht übernommene Uploads räumt `SubmissionUploadCleanupTask` nach 24 h ab.

**Worker** (`worker/src/Mail/`): eigener minimaler IMAP-Client
`ImapClient` über `SocketStrom` (keine Bibliothek, keine Prozesse; E-54),
`MimeNachricht` (RFC 2045–2047/2231, `message/rfc822` wird geöffnet),
`MailEingang` (Abruf je `abruf_minuten` aus der Schleife, Fehler bleiben
dort). Nur Mails, die seit dem Einschalten des Eingangs ankamen
(`aktiv_seit` → IMAP `SINCE`; Altbestand eines Postfachs wird nie
eingereicht). Auswahl der Anhänge: Typ nach Magic Bytes, Bilder ab 8 KiB,
Inline-Bilder (Content-ID) erst ab 50 KiB (Signatur-Logos nein, von Apple
Mail inline gesendete Fotos ja), Duplikate innerhalb der Mail einmal. Ein
Abruf reicht höchstens 120 s lang ein (Heartbeat und Jobs warten nicht); der
Rest folgt in der nächsten Runde. TLS nach STARTTLS startet nur ohne
gepufferte Klartext-Bytes (STARTTLS-Antwort-Injektion). Mails
größer als `max_einreichung_bytes · 4/3 + 1 MiB` werden nicht geladen.
Markierung: Keywords `$Vereinsbelege` (+ `\Seen`) bzw.
`$VereinsbelegeAbgelehnt`; erlaubt der Server keine eigenen Keywords,
`\Seen` bzw. `\Seen \Flagged` und nur ungelesene Mails gelten als neu.
Verschieben nur mit `MOVE` (RFC 6851), sonst bleibt die Mail markiert
liegen. Eine Mail, an der der Worker dreimal scheitert, wird als abgelehnt
markiert. Vor dem Holen bekommt eine Mail das Keyword `$VereinsbelegeVersuch`
(ohne eigene Keywords `\Flagged`), danach wird es entfernt; trägt eine offene
Mail es beim nächsten Abruf noch, ist der Worker an ihr gestorben (z. B.
Speicher) – sie wird als abgelehnt („unlesbar“) markiert statt erneut
geholt. Der MIME-Leser arbeitet mit Byte-Offsets im einen Mailpuffer
(höchstens 4 Ebenen, nur behaltene Anhänge werden dekodiert, Spitze unter
dem Dreifachen der Mailgröße); das geholte Literal ist auf
`max_einreichung_bytes · 4/3 + 1 MiB` begrenzt. Eine Mail, die nur für sich scheitert (FETCH nicht beantwortbar,
Webhoster lehnt einen Anhang mit 5xx ab), hält spätere nicht auf. Log: `mail_abruf`, `mail_abgelehnt` (UID, Grund), `mail_eingereicht`.

## Pflicht-Tests

Routing (Modul aus → nie Worker-Jobs; aktiv + online → Worker; offline →
Fallback nach Frist; OCR übersprungen und nachholbar; gehaltener Job
bleibt beim Worker; endgültiger Fehler → Session-Gegenpart; Alarm-Mail
einmal je Ausfall; KI-Schlüssel nur mit Modul und `ai`); Übernahme
(brauchbare Ergebnisse übernommen, defekte markiert + Rückfall, kein Leser
wirft); Volltext-Index nimmt spät festgeschriebene und überspringt defekte
Worker-Artefakte; Worker-Grant nur bei
aktivem Modul, Löschung nach Abschluss und nach max. Fehlversuchen;
Worker-Kontext enthält nie Name/E-Mail/IBAN; `GET /blobs` ohne
beanspruchten Job → 403; Artefakte sind mit Tresor-Schlüssel lesbar, mit
`W_priv` nicht; HMAC-Signatur (falscher Schlüssel, abgelaufener
Zeitstempel, Nonce-Replay, manipulierter Body); Kopplung (abgelaufener
Code, unbestätigter Fingerabdruck → inaktiv); Protokollversions-Prüfung;
Idempotenz doppelter Artefakt-Uploads; `Service/Processing` hat keine
Abhängigkeit zu Http/Session/Repository (Architekturtest); Worker-Programm
(#54): jeder Wiederholungsversuch mit neuem Nonce/Zeitstempel, http://
abgelehnt (localhost nur mit Testoption), leere Profilliste verwirft die
Schlüssel, Backoff, Arbeitsverzeichnis nur auf tmpfs und nach jedem Job
leer, SIGTERM gibt den laufenden Job per `release` ohne Versuchsverlust
zurück (#248: signiert, idempotent, Ergebnisse des abgebrochenen Versuchs
verworfen, auch nach abgelaufener Sperre beim neuen Claim), Worker-KI-Aufrufe
einmal im KI-Protokoll, „OCR nachholen“ je Beleg, „Worker-Version
veraltet“, `render_pages`/`ocr`/
`ai_extract` gegen den echten Controller und die echte Übernahme,
Prozess-Aufrufe nur in `worker/src/Prozess.php`; Bestandsarchiv (#88):
Auswahl nach Import-Lauf, festgeschriebene eingeschlossen und unverändert,
Drosselung auf 5 wartende OCR-Jobs mit Vorrang neuer Belege, Warten ohne
Worker mit Wiederaufnahme am Zeiger, ein Lauf zugleich und abbrechbar,
Archiv-Belege nie einzeln an den Worker, nicht in der Kopfzeilen-Zahl,
dauerhaft scheiternde OCR beendet den Lauf statt ihn endlos zu wiederholen;
E-Mail-Eingang (#87):
STARTTLS erzwungen und Zugangsdaten nie vor TLS, Zertifikat geprüft
(selbstsigniert abgelehnt), Zugang nur versiegelt und nur mit Freigabe,
STARTTLS-Injektion abgelehnt, Mail → Beleg mit Tresor lesbar, mit `W_priv` nicht, keine Doppel-Einreichung,
Ablehnungen markiert statt gelöscht, Grenzen, verschachtelte große Mail
bleibt im Speicher begrenzt, Mail mit Versuchs-Marke wird nicht erneut geholt, keine Mailinhalte im Log;
Worker-Ablauf
end-to-end im Docker (Webhoster-Container + Worker-Container, OCRmyPDF auf
einem Fixture-Scan, Ergebnis im Posteingang).
