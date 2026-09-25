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
*Nach M5-2 – Zwischenstand, noch nicht abschließend entschieden*
Empfehlung weiterhin: eigene schlanke Implementierung zuerst; OpenCV.js
(lazy geladen, vendored) nur, wenn die Trefferquote < 80 % liegt. Manuelle
Eckkorrektur gibt es in beiden Fällen.

M5-2 hat eine eigene Implementierung umgesetzt (Sobel-Kanten + Hough-
Linien + Viereck-Auswahl, `public/js/scanner/kanten.js`) und gegen 9 echte,
anonymisierte Belegfotos gemessen (nicht Teil dieses Repos – siehe unten).
Ergebnis auf den 8 auswertbaren Fotos (1 Foto ausgeschlossen, weil die
Soll-Ecken nicht zuverlässig bestimmbar waren – ein gefaltetes Schreiben auf
unruhigem Hintergrund):

- **3/8 (38 %)** Treffer streng nach Vorgabe (jede der 4 Ecken ≤ 3 % der
  Bilddiagonale von der Soll-Ecke).
- **6/8 (75 %)** bei 5 % Toleranz – 3 der 5 „Fehlschläge" lagen bei
  3,7–4,2 %, also knapp über der strengen Schwelle und im Kontrollbild
  visuell praktisch auf der echten Kante (Nachkorrektur im Eck-Editor M5-3
  wäre ein kleiner Korrektur-Zug).
- 2 echte Fehlschläge ohne gezielt behebbare Ursache: ein sehr langer,
  geknickter Kassenbon (die Unterkante wird zu früh angenommen) und ein
  fast bildfüllendes, deutlich gedrehtes Blatt mit einem unruhigen
  Hintergrund (Tastatur sichtbar), bei dem die Liniensuche die falschen
  Kandidaten bevorzugt.

Zwei Fehlerbilder aus einer ersten Messrunde (Trefferquote danach 11 %)
waren gezielt behebbar und sind jetzt als Regressionstests festgehalten
(`tests/js/scanner-kanten.test.js`): eine helle, bildbreite Linie neben dem
Beleg (z. B. eine Tischkante) wurde fälschlich als Belegkante gewählt, und
eine gedruckte Linie *innerhalb* eines großen Blatts wurde der echten
Außenkante vorgezogen. Behoben durch eine zusätzliche Prüfung je
Kantenpunkt: die Innenseite des Vierecks muss dort merklich heller sein als
die Außenseite (ein Beleg ist Papier, heller als das, worauf er liegt) –
eine Tischkante hat beidseitig dunklen Untergrund, eine Innenlinie beidseitig
helles Papier, beides fällt jetzt durch.

**Damit ist E-03 noch nicht entscheidbar:**
- Issue #32 verlangt ≥ 20 Fotos, es liegen erst 9 vor.
- 38–75 % ist keine belastbare Aussage gegen die 80 %-Schwelle bei n = 8.
- Die zwei verbleibenden Fehlschläge brauchen entweder mehr Beispielfotos
  (um ein Muster zu erkennen) oder eine grundsätzlichere Änderung
  (z. B. Bevorzugung von Kandidatenlinien nahe am Bildrand), beides über den
  Rahmen von M5-2 hinaus.

**Nächster Schritt** (Folge-Issue): ≥ 20 weitere anonymisierte Fotos
(Beträge/Namen unkenntlich gemacht) als committete Fixtures sammeln, damit
die Trefferquote reproduzierbar in der CI gemessen werden kann, und E-03
danach abschließend entscheiden.

Die 9 Fotos für diese Messung liegen **nicht** in diesem Repository (CLAUDE.md
§1, E-13 – öffentliches Repo, keine echten Belegdaten): sie zeigen reale
Beträge, Lieferanten und teils Namen/Adressen. Auswertung lief einmalig
lokal in der Bearbeitungssession; weder die Fotos noch daraus erzeugte
Bilder wurden committet.
