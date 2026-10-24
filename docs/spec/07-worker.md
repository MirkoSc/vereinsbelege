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
| `image_cleanup` für Belege ohne Browser-Aufbereitung | GD-Fallback | OCRmyPDF `--clean`/`--deskew` (unpaper) |
| `extract_text` (digitale PDFs, E-Rechnung) | Server (Webhoster) | bleibt auf dem Webhoster (schnell, braucht keinen Worker) |
| `ai_extract` | Session-Schritt | Worker, **ohne Zeitlimit, rund um die Uhr**, mit OCR-Text + Bildern |
| `resolve_supplier`, `classify`, `detect_*`, `match_transactions` | Session | **bleibt Session** (braucht Tresor) |

Später erweiterbar (Backlog): Beleg-Eingang per E-Mail (IMAP-Abruf durch den
Worker), Nachverarbeitung des Bestandsarchivs mit OCR.

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
- **Stand M7b-2 (issue #52, Migration 040, E-39):** `worker_grant` je Blob
  (worker_id, document_id, blob_id, `sealed_dek` = `crypto_box_seal(DEK,
  W_pub)`, expires_at = +3 Tage) und `worker_context` je Dokument
  (`{freitext, kostenstelle_hinweis}` an `W_pub`, Freitext auf 1000 Zeichen
  gekürzt). Erteilt von
  `App\Service\Worker\WorkerFreigaben`: `ausSession(InboxItem, Vault)`
  („an den Worker übergeben“ – alle Blobs aus `Document::blobIds()`, nur
  für den aktiven, bestätigten Worker; **verweigert während einer
  Tresor-Rotation** (#90) und mit einem Tresor, der nicht die aktuelle
  Generation ist) sowie `beiAnlage()`/`kontextBeiAnlage()` für frisch
  erzeugte DEKs ohne Geheimnis (Einhängen in Einreichung und Routing: #53).
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
  Tag. Grants löschen (#52, umgesetzt) und Rückkehr zum Browser-Weg (#53)
  hängen sich an `WorkerKopplung::entkoppeln()`.

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
    der gemeldeten Fähigkeiten (`ocr` → `ocr`/`image_cleanup`, `render` →
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
    versiegelt der Worker seine Ergebnisse.
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
       `image_cleanup` → `pdfa`; `ai_extract` → `extraction`),
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
  - Wer die Ergebnisse in den Ablauf übernimmt (z. B. ältere Läufe
    ablöst, `ocr_status` setzt), ist Sache von #53/#54.

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

## 7. Paketierung und Betrieb

- `worker/Dockerfile` im Repo, Basis `debian:trixie-slim` mit
  `php8.x-cli` (+ sodium, curl, mbstring), `ocrmypdf`,
  `tesseract-ocr-deu`, `ghostscript`, `poppler-utils`, `unpaper`.
  Worker-Code = `bin/worker.php` + gemeinsames `app/src`.
- GitHub Action baut bei jedem Tag ein **Multi-Arch-Image**
  (`linux/arm64` + `linux/amd64`) nach
  `ghcr.io/mirkosc/vereinsbelege-worker` mit Tags `vX.Y.Z`, `beta`
  (jedes Tag) und `stable` (beim Hochstufen auf „latest").
- `worker/docker-compose.yml` als Vorlage: `restart: unless-stopped`,
  `tmpfs` für Arbeitsverzeichnis, Volume nur für Konfiguration + Schlüssel
  (Dateirechte 600), Speicherlimit, Logrotation.
- Updates: `docker compose pull && docker compose up -d` (Doku), optional
  automatisch per systemd-Timer; der Admin zeigt „Worker-Version veraltet".
- Einrichtungsanleitung für den Pi: `docs/worker-einrichtung.md` (M7b-6).

## Pflicht-Tests

Routing (Modul aus → nie Worker-Jobs; aktiv + online → Worker; offline →
Fallback nach Frist; OCR übersprungen und nachholbar); Worker-Grant nur bei
aktivem Modul, Löschung nach Abschluss und nach max. Fehlversuchen;
Worker-Kontext enthält nie Name/E-Mail/IBAN; `GET /blobs` ohne
beanspruchten Job → 403; Artefakte sind mit Tresor-Schlüssel lesbar, mit
`W_priv` nicht; HMAC-Signatur (falscher Schlüssel, abgelaufener
Zeitstempel, Nonce-Replay, manipulierter Body); Kopplung (abgelaufener
Code, unbestätigter Fingerabdruck → inaktiv); Protokollversions-Prüfung;
Idempotenz doppelter Artefakt-Uploads; `Service/Processing` hat keine
Abhängigkeit zu Http/Session/Repository (Architekturtest); Worker-Ablauf
end-to-end im Docker (Webhoster-Container + Worker-Container, OCRmyPDF auf
einem Fixture-Scan, Ergebnis im Posteingang).
