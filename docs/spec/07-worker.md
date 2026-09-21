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
   Worker-ID + Zugangsschlüssel (32 Byte, auf dem Webhoster nur als Hash).
3. Admin sieht den Fingerabdruck von `W_pub` (auch in der Worker-Konsole
   ausgegeben), vergleicht und bestätigt. Erst dann ist der Worker aktiv.
- Jede Anfrage: `Authorization: Worker <id>` + HMAC-SHA256-Signatur über
  Methode | Pfad | Zeitstempel | Nonce | SHA256(Body) mit dem
  Zugangsschlüssel. Zeitfenster ±5 min, Nonce-Replay-Schutz (Tabelle,
  Cron räumt auf). Rate-Limit je Worker.
- Mehrere Worker sind technisch möglich, v1 unterstützt **einen** aktiven.
- Entkoppeln im Admin: Grants löschen, Zugang sperren, sofortige Rückkehr
  zum Browser-Weg.

## 5. Protokoll (Webhoster-API `/api/worker/*`)

| Endpunkt | Zweck |
|---|---|
| `POST /pair` | Kopplung (nur mit gültigem Code) |
| `POST /heartbeat` | Version, Protokollversion, Fähigkeiten (`ocr`, `render`, `ai`), freie Ressourcen; Antwort: versiegelte Konfiguration falls geändert, Anzahl wartender Jobs |
| `POST /jobs/claim` | bis zu N Jobs übernehmen (setzt `locked_until`, z. B. 30 min) |
| `GET /blobs/{id}` | Chiffrat streamen + an den Worker versiegelter DEK (nur mit gültigem Grant für einen beanspruchten Job) |
| `GET /jobs/{id}/context` | Worker-Kontext + nicht-sensible Stammdaten für den Prompt (Kategorien mit `ai_hint`, Kostenstellen, Settings) |
| `POST /jobs/{id}/artifacts` | Ergebnis-Upload (Chunk-Upload wie 03 §4), bereits an `VK_pub` verschlüsselt |
| `POST /jobs/{id}/complete` / `/fail` | Abschluss bzw. Fehler mit Meldung (ohne Klartext-Inhalte) |

- Polling-Intervall: 60 s ohne Arbeit, sofort weiter, solange Jobs warten.
- **Protokollversion**: Webhoster lehnt inkompatible Worker mit klarer
  Meldung im Admin ab („Worker-Update nötig").
- Idempotenz: Artefakte tragen eine Job-Versuchs-ID; doppelte Uploads
  werden erkannt.

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
