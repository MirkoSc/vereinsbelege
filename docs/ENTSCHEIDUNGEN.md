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

## E-19 ✅ Argon2id-Profil MODERATE für die Tresor-Umhüllung
*Betrifft: M2/M3 (issue #100) – **vorläufig, autonom getroffen – Bestätigung
durch Mirko offen***

Neue Umhüllungen des Benutzer-Privatkeys verwenden Argon2id MODERATE
(3 Durchläufe, 256 MiB) statt INTERACTIVE (2 Durchläufe, 64 MiB): auf dem
Zielhost 309–321 ms je Anmeldung, für einen Angreifer mit DB-Dump der
sechsfache Aufwand je Rateversuch. Parameter und Algorithmus stehen je
Benutzer in `user_key` (`kdf_ops`, `kdf_mem`, `kdf_alg`); ältere Konten
öffnen mit ihren eigenen Werten und werden bei der nächsten Anmeldung
angehoben. **Keine Kalibrierung im Installer** – das Profil ist fest, ob es
auf einem Host trägt, zeigt vorher der Hosting-Check. Einzelheiten und
Speicher-Überlegung: 01 §2 „Argon2id-Profil der Umhüllung".
## E-22 ✅ (vorläufig) Nextcloud-Ablage: einzelne Dateien statt ZIP, Ziel im Tresor
*Betrifft: issue #95 (Backlog) – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

Der Export kann direkt in einen Ordner der Vereins-Nextcloud gelegt werden
(WebDAV). Entschieden wurde:
- **Kein serverseitiges ZIP**, sondern jede Datei einzeln mit den Pfaden
  und Namen des ZIP-Exports, `index.csv` zuletzt – als idempotente
  Schrittkette kurzer Requests in der Nutzer-Session. Ein Server-ZIP
  bräuchte Klartext auf Platte oder einen langen Request (CLAUDE.md §1, §4).
- **Nie überschreiben:** Schon vorhandene gleiche Dateien (SHA-1, sonst
  Größe) werden übersprungen, eine andere Datei gleichen Namens bleibt und
  die neue bekommt „ (2)“.
- **Adresse, Benutzer und App-Passwort liegen im Tresor**, nicht beim
  Server-Schlüssel – das Passwort öffnet das Archiv des Vereins.
- Die Ablage **markiert den Lauf nicht** als exportiert; das bleibt die
  Bestätigung von Hand. Nur `export.zip` ohne Scope darf ablegen.

Details: 05 §2 „Nextcloud-Ablage“.

## E-20 ⏳ Live-Kamera: Viereck anzeigen, automatisch auslösen nur auf Wunsch
*(vorläufig, autonom getroffen – Bestätigung durch Mirko offen; issue #146)*
Der Kameradialog zeigt das live erkannte Viereck und „Beleg erkannt.“, löst
aber **ab Werk nicht selbst aus** – ein Ankreuzfeld im Dialog schaltet das
ein; es gilt bis zum Neuladen der Seite (nicht gespeichert). Grund: die Trefferquote der
Kantenerkennung ist noch nicht belegt (E-03, #142); ein Fehlauslösen auf
eine Tischkante wäre ärgerlicher als ein Tipp auf „Auslösen“. Eckwerte:
Erkennung höchstens alle 400 ms auf 320 px Breite, „stabil“ = 3
aufeinanderfolgende Treffer mit ≤ 2 % Abweichung (Bilddiagonale). Zu
überprüfen, sobald E-03 entschieden ist und ein echtes Mittelklasse-Handy
getestet wurde (Standard „an“? Einstellung merken?).

## E-21 ✅ Eine Regelliste für Buchungen und Belege (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M7-7 (issue #49), 03 §8, 04 §5*

Die Regeln aus M9-6 (`assignment_rule`) schlagen ihre Kategorie auch für
Belege vor, statt eine zweite Regelart „Lieferant → Kategorie“ mit eigener
Seite einzuführen: Wer „Telekom → Telefon“ für Buchungen einrichtet, will
dasselbe für die Telekom-Rechnung. Stichwort gilt am Beleg für den Zweck,
Gegenseite für Name/Aliasse/IBANs des Lieferanten. Für reine
„Lieferant → Kategorie“-Fälle gibt es die Standard-Kategorie des
Lieferanten. „Gelernt“ heißt: die **zwei neuesten** geprüften Belege des
Lieferanten haben dieselbe Kategorie (nicht „irgendwann zwei gleiche“) –
so folgt der Vorschlag einem Wechsel. Herkunft wird nur angezeigt, nicht
gespeichert. Offen für Mirko: ob Regeln nur für Belege bzw. nur für
Buchungen gelten können sollen, und ob die Herkunft am gespeicherten Beleg
festgehalten werden soll (bräuchte eine Migration).

## E-23 ❓ Lieferanten-Auflösung: Schwelle, Vorschlag, Rollen
*M7-6 (issue #48) – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

Die Spec (03 §7) legt die sechs Stufen fest, nicht die Details. Umgesetzt:
- **Ähnlichkeit (Stufe 5):** der bessere von Levenshtein (auf Buchstaben)
  und Trigramm-Dice nach der Normalisierung aus Stufe 4; **Schwelle 0,8**.
  Namen unter 4 Buchstaben gelten nur bei Gleichheit als ähnlich („OBI“ ≠
  „ABI“). Beispiele: „Getränke Müller GmbH“/„Getraenke Muller“ 0,94 →
  Vorschlag; „Sporthaus Nord“/„Sporthaus Süd“ 0,79 → kein Vorschlag;
  „Ballsport Meier“/„Ballsport Maier“ 0,93 → Vorschlag (ein Mensch prüft).
- **Vorschlag in der Prüfansicht:** der ähnlichste Lieferant ist
  **vorausgewählt, aber gelb markiert** („KI unsicher“) mit Hinweis und
  Prozentwert; bis zu zwei weitere Kandidaten werden genannt. Angelegt wird
  bei einem Vorschlag nichts.
- **Falsche Rolle:** trifft eine Kennung oder der Name einen Partner der
  anderen Rolle (Zahler bei einer Ausgabe), wird **nichts gewählt und nichts
  angelegt** – es wäre derselbe Partner doppelt; die Seite nennt ihn und
  bittet, die Rolle anzupassen. Ebenso bei **mehreren gleichnamigen**
  Lieferanten (Stufe 4): keine Wahl, keine Anlage.
- **Automatische Anlage** nur nach einem gültigen KI-Vorschlag (Job
  `resolve_supplier` nach `ai_extract`), nicht für E-Rechnungen ohne KI;
  übernommen wird, was die KI zum Lieferanten las, **unbrauchbare Einzelwerte
  (falsche IBAN-Prüfziffer, ungültige USt-ID/E-Mail) werden weggelassen**
  statt die Anlage scheitern zu lassen; keine Standard-Kategorie. Belege aus
  dem Archiv-Import, deren Pfad schon einen Lieferanten nennt, werden nicht
  aufgelöst.
- **„Geprüft“:** Speichern des Lieferanten-Formulars gilt als geprüft;
  zusätzlich „Als geprüft markieren“ ohne Änderung. Zusammenführen ändert
  `needs_review` des Ziels nicht (M6-5).
- **Noch nicht umgesetzt:** „Neu gelernte Merkmale (weitere IBAN,
  Alias-Name) werden nach Bestätigung hinzugefügt“ (03 §7) – kein
  Abnahmekriterium von #48, eigenes Folge-Issue.

## E-26 ❓ Wiederkehrende Rechnungen: Erkennung, Verknüpfung, Verwerfen
*M8-1 (issue #57) – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

Die Spec (03 §9) nennt nur die Eckwerte. Umgesetzt (Details 03 §9 „Stand M8-1“):
- **Belege über eine eigene Tabelle** `recurring_series_invoice` statt
  `invoice.recurring_series_id`: festgeschriebene Belege sind unveränderlich
  (01 §7), und die meisten Belege, in denen man eine Serie erkennt, sind schon
  festgeschrieben. Spalte `period` statt `interval` (reserviertes Wort),
  `status` (Vorschlag/bestätigt/verworfen) statt `active`/`confirmed`.
- **Auslöser „Geprüft“**: nur geprüfte/festgeschriebene Belege zählen
  (Beträge sind von Menschen bestätigt), je Lieferant **und** Richtung.
- **Betrag:** ±15 % jeweils zum nächstneueren Beleg (nicht zum Median) – eine
  Preissteigerung in Schritten bleibt eine Serie. **Rhythmus:** Median der
  Abstände ± 5/12/20/30 Tage, kein Abstand unter halbem Rhythmus, eine Lücke
  über 2,5 Rhythmen schneidet ab. Toleranz bis „überfällig“: 7/14/21/30 Tage.
  Die Betragstoleranz ist ein Setting ohne Admin-Seite (Folge-Issue).
- **Bestätigte Serie verknüpft neue passende Belege automatisch** (mit Audit,
  auf der Serienseite wieder abwählbar) – die Serie hat ein Mensch bestätigt.
- **Verwerfen** behält die Verknüpfungen: diese Belege kommen nie wieder als
  Vorschlag; erst neue Belege können eine neue Serie bilden. Eine verworfene
  Serie ist nur noch lesbar (kein „Wiederherstellen“).
- **Nur ohne Scope:** die Seiten verlangen `document.edit` ohne Kostenstellen-/
  Zeitraum-Einschränkung, weil eine Serie alle Belege eines Lieferanten zeigt.
- **Noch nicht:** Serien aus Bankbuchungen (Mandatsreferenz), Übersicht mit
  Jahreshochrechnung/Trend/„überfällig“ (M8-2, #58), Hinweis auf die Serie in
  der Prüfansicht.

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

## E-10 Nachtrag: HEIC (Issue #147) (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)

HEIC/HEIF wird serverseitig als Original zugelassen (Magic Bytes, nicht
Endung/MIME); das im Browser erzeugte JPEG ist die aufbereitete Fassung.
E-10 (Original bleibt immer erhalten) gilt damit unverändert, statt dass das
konvertierte JPEG zum „Original" wird. Konvertierung nur mit Browser-eigenen
Dekodern; keine WASM-Bibliothek, weil sie `'wasm-unsafe-eval'` in der CSP
bräuchte. Wo der Browser HEIC nicht lesen kann, wird die Seite vor dem Upload
abgelehnt (bisherige Fehlermeldung plus iPhone-Tipp); ein HEIC-Original wird
nur zusammen mit dem im Browser erzeugten JPEG angenommen. Im Archiv-Import
wird HEIC übersprungen. Details: Spec 03 §1 „HEIC".

## M7-8 KI-Protokoll: Umfang (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)

- Protokolliert werden die Aufrufe des `ai_extract`-Jobs (inkl. Reparatur und
  Fallback), nicht der „Verbindung testen“-Probe.
- Die Tabelle `ai_call_log` ist Klartext (Betriebsdaten ohne Belegbezug – auch
  keine Beleg-ID), damit sie ohne Tresor lesbar ist; Anbieter und Modell werden
  kopiert, damit die Historie ein gelöschtes Profil überlebt.
- Anzeige: letzte 30 Tage, je Anbieter die neuesten 25 Aufrufe; keine
  Bereinigung, kein Filter/Export.

## Issue #91 CAMT.053-Import: Abgrenzungen (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
Details in 04 §2a. Getroffen ohne Rückfrage, jeweils die konservative
Variante:

- **Sammelbuchung bleibt eine Buchung.** Ein `Ntry` mit mehreren `TxDtls`
  wird nicht in Einzelposten aufgeteilt – so stimmen Saldo und Abgleich mit
  dem Kontoauszug überein und MT940 zeigt es genauso. Die Zwecke aller
  Posten werden verbunden; eine Gegenseite gibt es dann nicht. Damit sie
  beim Formatwechsel nicht doppelt kommt, gilt eine Buchung ohne
  Gegen-IBAN auch über Tag + Betrag als vorhanden (Ersatzabgleich, 04 §4) –
  Restrisiko: zwei verschiedene Buchungen ohne Gegenseite mit gleichem Tag
  und Betrag aus verschiedenen Formaten werden als eine erkannt. Aufteilen
  (z. B. für die Zuordnung einzelner Rechnungen) wäre ein eigenes Issue.
- **Nur gebuchte Einträge.** `PDNG`/`INFO` werden übersprungen und als
  „vorgemerkt“ gezählt – wie vorgemerkte CSV-Zeilen.
- **camt.052 (untertägiger Umsatzbericht) und camt.054 werden abgelehnt**
  mit dem Hinweis, CAMT.053 zu exportieren – sie haben keinen festen
  Schlusssaldo bzw. sind keine Kontoauszüge.
- **ZIP:** nur `.xml`-Einträge zählen, alles andere im Archiv (PDF-Auszüge,
  Textdateien) wird stillschweigend ignoriert; Grenzen 5 MB je Datei,
  10 MB entpackt.
