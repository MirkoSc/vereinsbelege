# Grundsatzentscheidungen

Status: **✅ entschieden** · **❓ offen – vor dem genannten Meilenstein klären**.
Wer eine Entscheidung ändert, passt die betroffenen Specs im selben PR an.

## E-01 ✅ Tresor-Modell statt „Schlüssel liegt auf dem Server"
*Betrifft: alles ab M2*

Anforderung: Wer FTP- oder DB-Zugriff hat, soll die Daten nicht im Klartext
lesen können. Liegt der Schlüssel auf dem Server (z. B. in `config.php`),
schützt Verschlüsselung nur gegen DB-only-Zugriff – mit FTP-Zugriff liest man
Schlüssel und Daten. Deshalb: asymmetrischer Tresor, Private Key nur
gewrappt mit Benutzerpasswörtern und als Papier-Wiederherstellungsschlüssel.

**Konsequenzen, die bewusst in Kauf genommen werden:**
- KI-Auswertung, Abgleich, Export laufen nur, während ein berechtigter
  Nutzer angemeldet ist (Browser treibt die Jobs) – nicht nachts im Cron.
- Nach „Passwort vergessen" muss ein Admin den Zugriff neu freigeben.
- Geht der Wiederherstellungsschlüssel verloren **und** haben alle Admins
  ihr Passwort vergessen, sind die Daten weg. → Schlüssel ausdrucken,
  Kopie im Vereinstresor/bei zweitem Vorstandsmitglied.
- Gegen einen Angreifer, der Code auf dem Server **verändert**, hilft keine
  Verschlüsselung vollständig (siehe Bedrohungsmodell).

## E-02 ✅ Beträge werden mitverschlüsselt
SQL kann dadurch nicht summieren; PHP rechnet nach dem Entschlüsseln. Bei
Vereinsmengen unkritisch (M13-5 prüft das). Datumsfelder, Status und
Kategorie-IDs bleiben Klartext für Filter/Sortierung.

## E-04 ✅ Texterkennung über die KI, keine serverseitige OCR
Tesseract ist auf Shared Hosting nicht ausführbar. Reihenfolge:
E-Rechnungs-XML → PDF-Textlayer → Vision-Modell mit Seitenbildern.
Tesseract.js im Browser nur als Backlog-Option für Volltextsuche ohne KI.

## E-05 ✅ Kein Event Sourcing (anders als der Vereinskalender)
Dort war es wegen öffentlicher Schreibzugriffe und selektivem Rollback
bösartiger IPs sinnvoll. Hier schreibt die Öffentlichkeit nur in den
Posteingang; verschlüsselte Event-Payloads würden Replay/Rebuild an eine
Session binden und die Komplexität stark erhöhen. Ersatz: Audit-Log mit
Hash-Kette + Festschreibung.

## E-06 ✅ Speicher-Backend: Dateisystem als Default
Blobs liegen standardmäßig in `shared/var/blobs/` – **immer verschlüsselt**
(Dateiname = Zufalls-ID, Inhalt = Chiffrat). `db` bleibt als Alternative
wählbar und per Schrittkette umstellbar.

## E-07 ✅ Frontend: htmx + handgeschriebenes CSS
htmx ist eine einzelne, vendored JS-Datei ohne Server-Anforderungen – läuft
auf jedem Webhoster, kein Build-Schritt. CSP-konform: kein `hx-on`,
`htmx.config.includeIndicatorStyles = false`, keine Inline-Skripte.
M1-3 prüft das im Docker und auf der Testinstanz.

## E-08 ✅ KI-Anbieter: OpenAI Standard, dann Anthropic, dann llama.cpp
Reihenfolge der mitgelieferten Profil-Vorlagen und der Umsetzung:
1. **OpenAI** (Standard) – Vision + Structured Outputs (`json_schema`).
2. **Anthropic** über den OpenAI-kompatiblen Endpunkt – Fähigkeiten
   (Bilder, `response_format`) beim Verbindungstest ermitteln; fehlt
   `json_schema`, greift der Prompt-Schema-Pfad mit Validierung.
3. **llama.cpp-Server** (Vision-Modell mit mmproj) – nur über
   Reverse-Proxy mit TLS + API-Key erreichbar.

Datenschutz: Für OpenAI/Anthropic ist ein AV-Vertrag nötig (Einreicher-
Namen und -IBANs können auf Belegen/im Freitext stehen); Hinweis in der
Datenschutzerklärung (M13-3). Qualitätsprüfung gegen 30 echte Belege in M7-5.

## E-09 ✅ Kein Vier-Augen-Prinzip
Nicht benötigt. Statusweg: geprüft → festgeschrieben durch dieselbe Person
möglich. Festschreibung + Audit-Log bleiben.

## E-10 ✅ Originale bleiben immer erhalten
Neben der aufbereiteten Schwarzweiß-Fassung wird das unveränderte Foto/PDF
gespeichert (Nachweisbarkeit, bildliche Übereinstimmung). Aufbewahrungs-
fristen und Anforderungen ans ersetzende Scannen mit dem Steuerberater
klären – die App stellt Festschreibung, Audit-Log und Verfahrens-
dokumentations-Vorlage bereit, trifft aber keine Rechtsaussage.

## E-11 ✅ Name: `vereinsbelege`
Repo, composer-Paket `vereinsbelege/vereinsbelege`, Release-ZIP
`vereinsbelege-vX.Y.Z.zip`, App-Name-Default „Vereinsbelege" (im Admin
änderbar, wie beim Vereinskalender).

## E-12 ✅ Keine Mandantenfähigkeit
Eine Installation = ein Verein. Weitere Vereine bekommen eine eigene
Installation (eigene Subdomain, eigene DB, eigener Tresor). Kein
`verein_id` im Datenmodell.

## E-13 ✅ Öffentliches Repo, GPLv3
Nötig für Updates ohne GitHub-Token (wie Vereinskalender). Das
Sicherheitsmodell beruht nicht auf geheimem Code; Secrets gehören nie ins
Repo (`.gitignore`, CI-Check auf versehentlich committete Schlüssel).

## E-14 ✅ Öffentliche Einreichung ohne Code
Jeder mit dem Link darf einreichen. Spamschutz ohne Hürde für Menschen:
Rate-Limit, unsichtbarer Proof-of-Work, Honeypot-Feld, Größen-/Typ-/
Seitenlimits, Mindest-Ausfülldauer.

## E-15 ✅ Steuerliche Sphären ausgeblendet
Setting `sphaeren_aktiv` = aus (Default). Dann: kein Feld in UI, kein
Sphären-Teil im KI-Prompt, keine Sphären-Auswertung. Spalte bleibt im
Datenmodell (NULL), damit ein späteres Einschalten keine Migration braucht.

## E-16 ✅ Geschäftsjahr = Kalenderjahr
Auswertungen und Export-Default nutzen das Kalenderjahr; kein
Geschäftsjahres-Setting in v1.

## E-17 ✅ Einnahmen mit und ohne Beleg
Einnahmen sind vollwertig: Einnahme-Belege (z. B. Sponsoring-,
Werbe-, Vermietungsrechnungen) können hochgeladen und zugeordnet werden,
Einnahme-Buchungen sind aber **standardmäßig „kein Beleg nötig"** (Setting).
Manuelle Buchungen (v. a. Kasse) ohne Beleg sind jederzeit möglich.

## E-18 ✅ Optionaler Verarbeitungs-Worker
Die Anwendung läuft vollständig auf dem Webhoster. Zusätzlich gibt es ein
im Admin aktivierbares Worker-Modul (Docker-Container, Referenz:
Raspberry Pi 4 beim Verein/bei Mirko, alternativ ein VPS), das per
ausgehendem Polling OCR (OCRmyPDF → durchsuchbares PDF/A), PDF-Rasterung
und KI-Auslesen übernimmt – ohne Zeitlimit und auch nachts. Minimalrechte:
Der Worker sieht nur wartende Dokumente, nie Archiv, Bankdaten oder
Einreicher-IBANs. Ausfall → automatischer Rückfall auf den Browser-Weg.
Keine Zusatzkosten; DigitalOcean nicht nötig.

## E-03 ❓ Kantenerkennung: eigene Implementierung oder OpenCV.js
*Vor M5-2 – anhand echter Belegfotos*
Empfehlung: eigene schlanke Implementierung zuerst; OpenCV.js (lazy
geladen, vendored) nur, wenn die Trefferquote < 80 % liegt. Manuelle
Eckkorrektur gibt es in beiden Fällen.
