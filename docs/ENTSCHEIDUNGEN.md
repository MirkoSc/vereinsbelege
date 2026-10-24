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
## E-25 ❓ Restore ohne `config.php`: Zugang über Wiederherstellungsschlüssel + Passwort
*(vorläufig, autonom getroffen – Bestätigung durch Mirko offen; issue #154)*

Befund: `user.email_bi` (Login-Index), `email_enc`, `display_name_enc` und
TOTP-Geheimnisse hängen am Server-Schlüssel aus `config.php`. Das
Standard-Backup enthält ihn bewusst nicht (E-01: Chiffrat darf den Server
verlassen, der Schlüssel nicht). Nach dem Einspielen auf einem neuen Server
konnte sich deshalb niemand mehr anmelden – auch nicht mit dem
Wiederherstellungsschlüssel, der einen angemeldeten Admin voraussetzte.

Gewählt (Kombination aus Weg 3 und Weg 1 des Issues, dazu ein Stück Weg 2):
- **Weg 3, Warnen:** `bin/backup.php` warnt bei jedem Backup ohne
  `--mit-config` auf STDERR; der Installer warnt vor dem Einspielen eines
  Backups ohne Schlüssel unübersehbar; Spec 06 §2 verlangt, `config.php`
  getrennt neben dem Wiederherstellungsschlüssel zu sichern.
- **Weg 1, Neuverknüpfung:** Der Installer schreibt `config.php` ohne alten
  Schlüssel nicht mehr still, sondern verlangt einen Zugangsschritt. Dort
  verknüpft der Admin sein **bestehendes** Konto neu: Wiederherstellungs-
  schlüssel (Berechtigung des Vereins; muss zum Tresor des Dumps passen)
  **plus bisheriges Passwort** (bestimmt das Konto – Namen und Adressen sind
  unlesbar, das Passwort ist das einzige Merkmal; Schlüsselpaar und Freigabe
  bleiben so erhalten). Adresse und Name werden unter dem neuen Schlüssel
  neu gesetzt, TOTP zurückgesetzt, ein fehlender Grant aus dem Tresor
  erzeugt, alles auditiert. Der Wiederherstellungsschlüssel erhält dadurch
  keine Befugnis, die er nicht hat: er enthält `VK_priv` und öffnet ohnehin
  jede fachliche Zeile. Ohne ihn kommt niemand hinein – ein geleaktes
  Backup allein führt weiterhin zu nichts.
- **Weg 2, nur als Angebot:** Wer `config.php` getrennt gesichert hat, lädt
  sie im selben Schritt hoch (oder trägt den Schlüssel ein); übernommen wird
  sie nur, wenn sie ein Konto des Dumps entschlüsselt. Als **Zwang**
  (Restore ohne alten Schlüssel ablehnen) verworfen: dann wäre ein Verein,
  der nur Backup und Papierschlüssel hat, endgültig ausgesperrt – genau das
  Szenario, das der Wiederherstellungsschlüssel abdecken soll.

Bewusst in Kauf genommen:
- Die Adressen der **übrigen** Benutzer sind nicht rekonstruierbar (sie
  liegen nirgends unter dem Tresor). Der Admin trägt sie unter
  `/admin/benutzer/<id>` neu ein; das Formular bietet das Feld nur für
  unlesbare Adressen an. Bis dahin können sich diese Konten nicht anmelden;
  ihre Authenticator-Apps sind ungültig, Passwort und Freigabe intakt.
- Ein Admin, der **Passwort und** `config.php` verloren hat, kommt mit dem
  Wiederherstellungsschlüssel allein nicht hinein. Nahe liegender Ausbau
  (nicht umgesetzt, Folge-Idee): „neues Admin-Konto mit dem Wiederherstellungs-
  schlüssel anlegen“ – Tresor-Grant aus dem Papierschlüssel, wie bei der
  frischen Installation ohne neuen Tresor.
- Haben zwei Admins dasselbe Passwort, lehnt der Schritt ab (nicht
  unterscheidbar; ein anderes Admin-Konto muss verknüpfen).
- Die Mail-Warteschlange wird bei Weg 2 geleert: ihre Zeilen sind Chiffrat
  unter dem verlorenen Schlüssel und würden sonst den Versand blockieren.
- Folge-Idee gegen den Datenverlust der Adressen: je Konto eine an `VK_pub`
  versiegelte Kopie von Adresse und Name, dann könnte der verknüpfte Admin
  alle Konten in einer Session automatisch neu berechnen.

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
  Abnahmekriterium von #48, eigenes Folge-Issue (umgesetzt mit #201, E-29).

## E-29 ❓ Lieferanten lernen Merkmale aus bestätigten Belegen
*M7-6b (issue #201) – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

03 §7 sagt nur „nach Bestätigung hinzugefügt“. Umgesetzt:
- **Sichtbar und vorausgewählt:** die Prüfansicht zeigt vor dem Speichern
  ein Ankreuzfeld „Neue Merkmale beim Lieferanten „…“ ergänzen“ mit den
  Werten (IBAN, Name als Alias, Gläubiger-ID, Mandatsreferenz). Angekreuzt
  ist es vorausgewählt – wer nichts tut, lässt den Lieferanten lernen; wer
  zweifelt, nimmt den Haken heraus. Ohne Anzeige zu lernen hätte Stammdaten
  unbemerkt verändert.
- **Wann:** beim **ersten** Speichern bzw. „Geprüft“ des Belegs, nur für den
  Lieferanten, den die Seite aus KI/E-Rechnung/Auflösung gewählt hat und den
  die Person **behalten** hat (Treffer Stufe 1–4 und bestätigter Vorschlag
  Stufe 5 – dort ist es am wertvollsten). Wählt die Person einen anderen
  oder selbst einen (z. B. bei „mehrdeutig“), wird nichts gelernt – wer
  selbst wählt, kann Merkmale unter „Lieferanten“ pflegen. Auch nicht bei
  einem Partner aus der Archiv-Vorgabe und nicht bei späteren Speichervorgängen.
- **Recht:** nur mit `supplier.manage` (es ändert Stammdaten); ohne das Recht
  wird nichts angeboten und nichts gelernt.
- **Was:** IBAN und Name als Alias; Gläubiger-ID nur, wenn der Lieferant
  keine hat (eine abweichende wird gemeldet, nicht ersetzt), Mandatsreferenz
  nur mit dieser Gläubiger-ID. **Nicht** USt-ID/Steuernummer (finden den
  Lieferanten schon in Stufe 1, wenn vorhanden; eine abweichende wäre eher ein
  Lesefehler) und keine übrigen Angaben (Anschrift, E-Mail …).
- **Konflikte:** IBAN/Gläubiger-ID eines anderen Lieferanten, oder ein Name,
  der Name/Alias eines anderen ist → nicht lernen, die Seite nennt ihn; volle
  Listen (20) → nicht lernen, Hinweis. Der Beleg wird immer gespeichert; das
  Lernen läuft danach in eigener Transaktion und nur, wenn der Plan noch
  genau dem gezeigten entspricht – sonst gar nichts (mit Hinweis), denn die
  Person hat etwas anderes bestätigt.
- **Vereins-IBAN:** eine IBAN eines eigenen Kontos (`bank_account`) wird nie
  gelernt, bei der KI-Neuanlage (Stufe 6, #48) weggelassen und in Stufe 2 der
  Auflösung ignoriert – auf Lastschrift-Rechnungen und Einnahme-Belegen ist sie
  oft die einzige IBAN; gelernt, würde sie jeden solchen Beleg diesem
  Lieferanten zuordnen (Hinweis aus dem Koordinator-Review).
- `needs_review` eines von der KI angelegten Lieferanten bleibt – ein
  bestätigter Beleg ist keine Prüfung seiner Stammdaten.

## E-27 ✅ Allocation-Modell: Zahlungsweg am Beleg, materialisiert abgeleitete Status (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M10-1 (issue #65), 02 „Fachdaten“, 04 §5, 05 §2/§3*

- **Abgeleitet, aber gespeichert:** `invoice.payment_status` und
  `bank_transaction.doc_status` (`teilweise`/`zugeordnet`) sind reine
  Funktionen der Allocation-Summen und werden von genau einem Service
  (`App\Service\Bank\Zuordnung`) nach jeder Änderung neu geschrieben –
  niemand setzt sie von Hand, kein Formular zeigt sie editierbar. Gespeichert
  werden sie trotzdem, weil die Beträge verschlüsselt sind: Die Listen von
  M10-5 (unbezahlt, fällig, offene Erstattungen, Buchungen ohne Beleg)
  müssen in SQL filtern und blättern können, ohne jeden Beleg zu
  entschlüsseln. Das ist dieselbe Stelle, an der M9-6 schon `zugeordnet`
  vorgesehen hat.
- **Zahlungsweg als Klartext-Strukturfeld** (`payment_kind`): `konto`,
  `erstattung`, `bar`, `keine`. Nur die ersten beiden erwarten Buchungen.
  `bar` = „bar bezahlt ohne Kassenbuchung“ ist **immer bezahlt** (der Beleg
  ist der Zahlungsnachweis) – so landen Archiv-Belege aus dem Ordner
  `Kasse` nicht dauerhaft in „unbezahlt“. Wer Kassenbons mit dem Kassenbuch
  (manuelle Kassenbuchungen, M9-5) abgleichen will, wählt `konto`.
  **Offen für Mirko:** ob „Bar erhalten“ aus der Einreichung besser `konto`
  (Kassenbuchung erwartet) als `bar` wäre; ob ein Backfill für vor M10-1
  erfasste Archiv-Belege (alle `konto`/`offen`) nötig ist – ein
  Session-Job könnte `kasse` aus dem `vorgabe`-Artefakt nachziehen.
- **Teilbeträge positiv, Richtung passt:** Der Betrag einer Zuordnung ist
  der Teil des Belegbruttos, den die Buchung ausgleicht – nie negativ. Eine
  Gutschrift wird von einer Buchung der **Gegenrichtung** ausgeglichen
  (Rückzahlung); „Gutschrift gegen Rechnung“ heißt: Rechnung mit
  Differenzgrund `gutschrift` abschließen, Gutschrift selbst `keine`.
  Verworfen: negative Allocation-Beträge (eine Gutschrift „im“ Betrag einer
  Sammelüberweisung) – sie hätten jede Summenprüfung und M10-2/M10-3
  verkompliziert.
- **Skonto als Differenzgrund am Beleg** (`difference_reason`), nicht als
  eigene Allocation-Zeile ohne Buchung: Der Rest ist Brutto − zugeordnet
  und wird berechnet, nicht gespeichert. Gründe: `skonto`, `gutschrift`,
  `sonstiges`.
- **Festgeschriebene Belege** nehmen Zuordnungen an; die Festschreibung
  schützt den Inhalt des Belegs, nicht seinen Zahlungsverlauf.
- **Export:** „Referenz“ = Verwendungszweck der ausgleichenden Buchungen
  (die E-Rechnungs-/SEPA-Referenz `eref` wird derzeit nicht in den
  Buchungs-Klartext gehoben). **Offen für Mirko:** ob „Referenz“ eher die
  Buchungs-ID oder `eref` sein soll; ob „bezahlt am“ auch bei teilbezahlt
  (Datum der letzten Rate) gefüllt werden soll.
- **Umbuchungen eigene Konten (M10-6)** brauchen keine Allocation: Das sind
  Buchung↔Buchung-Paare; vorgesehen ist eine eigene Verknüpfung plus ein
  `doc_source`-Wert, der beide auf `nicht_noetig` setzt.
## E-24 ❓ Volltextsuche nur mit Session-Index
*(vorläufig, autonom getroffen – Bestätigung durch Mirko offen; issue #94)*
Die Volltextsuche über erkannte Texte (Textebene, E-Rechnung, KI-Auslesung,
später Worker-OCR) legt **keinen dauerhaften Index** an – weder Klartext
noch Wort-Blind-Indexes, die Worthäufigkeiten verrieten. Jede Session baut
nach dem Entsperren beim ersten Aufruf der Suche einen eigenen Index per
kurzer Schrittkette; er liegt verschlüsselt in `shared/var/tmp/suche/`, der
Schlüssel nur an den Tresor versiegelt in der Session, und endet mit ihr
(Details 03 §10). Weitere vorläufige Festlegungen: Recht `inbox.view` mit
dessen Scope (Treffer verlinken in den Posteingang); Suche per POST, damit
Suchbegriffe nicht in URLs/Logs landen; UND-Verknüpfung mit Phrasen, keine
Platzhalter, keine Rangfolge (neueste zuerst, höchstens 50); Freitext der
Einreichung und von Hand erfasste Belegfelder (Zweck, Notiz) gehören nicht
zum Index – das Issue nennt nur erkannte Texte. Kosten: Der erste Aufbau
dauert je Session einige Sekunden je 1000 Belege.

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
  Abstände ± 5/12/20/30 Tage, von zu dicht liegenden Belegen bleibt der besser
  passende (Einzelkauf mit ähnlichem Betrag), eine Lücke
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

## E-33 ❓ Übersicht „Wiederkehrende Kosten“: Kennzahlen und „überfällig“
*M8-2 (issue #58) – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

Die Spec (03 §9) nennt nur die Spalten. Umgesetzt (Details 03 §9 „Übersicht“):
- **Keine neue Seite**, sondern `/app/serien` erweitert (Titel „Wiederkehrende
  Kosten“, bestätigte Serien vor den Vorschlägen); Recht und „nur ohne Scope“
  wie E-26. Keine Migration.
- **Jahreshochrechnung** = erwarteter Betrag × Belege pro Jahr, nicht die
  Summe der letzten zwölf Monate (die wäre bei neuen Serien zu klein);
  „unregelmäßig“ bekommt keine. Summe je Richtung und Währung.
- **Trend** gegen den Beleg etwa ein Jahr davor (≥ 335 Tage), sonst gegen den
  ältesten der Serie – eine Prozentzahl, kein Diagramm.
- **„Überfällig“** = heute nach nächster Fälligkeit + Toleranz, nur bei
  bestätigten Serien; dazwischen „fällig“. Es zählen nur **geprüfte** Belege
  (wie E-26) – ein noch ungeprüfter Beleg im Posteingang hebt die Markierung
  nicht auf. Verworfen: Posteingang mitzählen (dort ist der Betrag noch nicht
  bestätigt und der Lieferant oft noch offen).
- **Nur Anzeige:** keine Mail, kein Hinweis auf der Startseite/Navigation,
  kein Cron (der Cron kann nicht entschlüsseln). Eine Erinnerung per Mail
  wäre ein Folge-Issue.

## E-28 ❓ Worker-Kopplung: Zugangsschlüssel verschlüsselt statt als Hash, Protokoll-Details
*M7b-1 (issue #51), 07 §4–5 – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

- **Zugangsschlüssel serverschlüssel-verschlüsselt, nicht als Hash:** 07 §4
  sagte „auf dem Webhoster nur als Hash“ – eine HMAC-Signatur lässt sich
  aber nur mit dem Schlüssel selbst prüfen, ein Hash davon wäre
  gleichwertig zum Schlüssel. Gespeichert wird er deshalb mit dem
  Server-Schlüssel verschlüsselt (`worker.secret_enc`, wie die API-Keys der
  KI-Anbieter); ein DB-Dump oder DB-Backup allein reicht nicht. Beim
  Entkoppeln wird er gelöscht.
- **Kopplungscode:** 8 Zeichen Crockford-Base32 (ohne I/L/O/U, 40 Bit),
  Anzeige `ABCD-EFGH`, 15 min, einmal verwendbar; gespeichert nur als HMAC
  unter einem aus dem Server-Schlüssel abgeleiteten Schlüssel. Ein neuer
  Code ersetzt den alten. Raten ist begrenzt: 10 Fehlversuche je IP und 50
  insgesamt je 15 min. Codes gibt es nur, solange kein Worker gekoppelt ist
  (v1: einer) – zum Tausch erst entkoppeln.
- **Bestätigung:** Bis der Admin den Fingerabdruck (SHA-256 von `W_pub`,
  angezeigt als 8 × 4 Hex-Zeichen) bestätigt hat, darf der Worker nur
  Heartbeats senden, keine Jobs beanspruchen.
- **Signatur:** `METHODE|PFAD|ZEIT|NONCE|hex(SHA-256(Body))`, Header
  `Authorization: Worker <id>` bzw. `X-Worker-Authorization` (hat Vorrang;
  falls der Hoster `Authorization` an FastCGI nicht durchreicht oder die
  Seite hinter HTTP-Basic liegt), `X-Worker-Timestamp`,
  `X-Worker-Nonce`, `X-Worker-Signature`. Query-Strings sind auf Worker-
  Routen verboten (sie wären unsigniert). Fehlversuche: 30 je IP/15 min;
  Anfragen: 300 je Worker/min. Uhrabweichung wird nicht als Fehlversuch
  gezählt, die Antwort nennt die Serverzeit.
- **Jobs:** Ein Claim vergibt je Job eine eigene Sperre (`w<id>-<zufall>`),
  die `complete`/`fail` mitschicken; ein `fail` gibt den Job bis zum 3.
  Versuch wieder frei (`endgueltig` beendet sofort); als Fehler wird nur ein
  Bezeichner gespeichert, nie ein Text. Entkoppeln gibt gehaltene Jobs
  sofort frei.
- **Admin-Recht:** `admin.settings` (wie KI-Anbieter).
- **Kopplungs-Gesamtlimit:** Wer die öffentliche Route mit falschen Codes
  flutet, kann das Koppeln für 15 min blockieren (Verfügbarkeit gegen
  Ratesicherheit bei vielen IPs – bewusst zugunsten der Sicherheit).

Offen für Mirko: ob „Hash“ in 07 §4 eine bewusste Vorgabe war (dann
bräuchte es ein asymmetrisches Signaturverfahren, z. B. Ed25519 mit
`W_sign_pub` statt HMAC); ob der Tausch eines Workers ohne vorheriges
Entkoppeln möglich sein soll.

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

## E-30 ❓ Admin-Seite „Backups“: Erstellen als Schrittkette, Download mit `config.php` nur mit Passwort
*(vorläufig, autonom getroffen – Bestätigung durch Mirko offen; issue #112)*

- **Erstellen als Schrittkette statt `BackupService::create()` in einem
  Request:** Ein Backup mit Belegdateien passt auf dem Zielhost in keinen
  Request (06 §2). Die Seite baut das ZIP deshalb in kurzen Schritten (Dump,
  Blobs in Portionen, Abschluss) und hängt dabei selbst an das Archiv an
  (`ZipAnhang`, ZIP64), weil libzip bei jedem `close()` das ganze Archiv neu
  schreibt. Ergebnis und Format sind dieselben wie bei `create()`.
  Verworfen: Backup über die Seite nur ohne Blobs (unvollständig bei
  Backend `fs`), Blobs per `ZipArchive` portionsweise (Kopie des ganzen
  Archivs je Portion). Der Dump bleibt ein einzelner Request – wie in der
  Update-Kette; bei sehr großen `db`-Blob-Beständen ist das die Grenze.
- **Download eines Backups mit `config.php` nur per POST mit dem aktuellen
  Passwort** (zusätzlich zu `admin.system` und CSRF), Fehlversuche begrenzt.
  Ein solches ZIP enthält den Server-Schlüssel; eine liegengelassene,
  angemeldete Sitzung soll ihn nicht mit einem Klick herausgeben. Kein
  erneuter zweiter Faktor: das bräuchte einen eigenen Bestätigungsablauf je
  Methode (TOTP, E-Mail-Code) – Folge-Idee, falls gewünscht. Backups ohne
  `config.php` lädt der angemeldete Admin per GET.
- **Eine Erstellung gleichzeitig** (409), eine über 1 h nicht fortgesetzte
  darf ein neuer Start verwerfen; Erstellen ist nur mit JavaScript möglich
  (wie Update und Speicher-Umzug).
- **Kein Löschen einzelner Backups** über die Seite (nicht im Umfang des
  Issues); es bleibt bei der Rotation (10).

## E-31 ✅ Dump strömend einspielen: Byte-Offset, Byte-Budget, Fortschritt (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #120, 06 §2*

Die Dump-Phase des Restores merkt sich einen Byte-Offset hinter der letzten
ausgeführten Anweisung statt eines Statement-Index; jeder Schritt liest nur
ab dort (`SqlStatementStream`). Getroffen ohne Rückfrage:

- **Byte-Budget 8 MiB je Schritt** zusätzlich zu den 200 Anweisungen (wie
  bei den Blob-Dateien). Mit Backend `db` trägt ein INSERT von
  `file_blob_chunk` rund 512 KiB Hex – 200 davon wären ein Request über
  100 MB. Mindestens eine Anweisung je Schritt, damit die Kette weiterläuft.
- **Fortschritt in Bytes.** Die Gesamtzahl der Anweisungen kennt man nur
  nach einem Lesen der ganzen Datei; das wäre wieder ein langer Request.
  Die Seite zeigt deshalb „Anweisung X eingespielt (P %)“ statt
  „Anweisung X von Y“.
- **Offset nach jeder Anweisung in der Session**, nicht erst am Blockende:
  ein Abbruch durch das Zeitlimit wiederholt höchstens die eine Anweisung,
  die gerade lief, statt des ganzen Blocks.
- **Alte Restore-Session wird umgerechnet** statt abgebrochen: der
  Statement-Index wird einmal durch Lesen bis dorthin in einen Byte-Offset
  übersetzt (gleiche Zerlegung, nichts wird ausgeführt).
- **Sitzungseinstellungen des Dumps je Schritt wiederholen** (`SET NAMES`,
  `FOREIGN_KEY_CHECKS = 0`, am Ende wieder `= 1`) – als Konstanten im
  `MysqlDumper`, die er selbst schreibt und der `RestoreService` nachspielt; ein
  allgemeines Nachspielen aller `SET`-Anweisungen gibt es nicht. Behebt
  nebenbei, dass Restores über 200 Anweisungen schon vorher ab dem zweiten
  Schritt mit Fremdschlüssel-Prüfung liefen und scheiterten.
- **Anweisungen über 16 MiB werden abgelehnt** (Speicher konstant; der
  Dumper erzeugt höchstens ~1 MiB, MariaDBs Standard-`max_allowed_packet`
  ist 16 MiB).

Offen für Mirko: Die beim Abbruch gerade laufende Anweisung bzw. bei einem
harten Prozessabbruch (Session nicht mehr geschrieben) der Rest des Blocks
kann doppelt laufen – dann hilft nur ein
Restore von vorn. Ganz schließen ließe sich das nur mit einem
Fortschrittsstand in der Datenbank selbst (Migration, eigenes Issue).

## E-39 ❓ Worker-Grants, Worker-Kontext und Artefakt-Upload (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #52/M7b-2, 07 §3 und §5, 02 „Betrieb“, Migration 040*

Der Worker bekommt nie den Tresor-Private-Key, nur je Dokument versiegelte
Daten-Schlüssel. Getroffen ohne Rückfrage:

- **Grant je Blob, gebunden an Worker und Dokument, nicht an einen Job.**
  `worker_grant` (worker_id, document_id, blob_id, sealed_dek an `W_pub`,
  expires_at). Zugriff (`GET /blobs/{id}`) nur, wenn der Worker zusätzlich
  **gerade einen laufenden, nicht abgelaufenen Job desselben Dokuments
  hält** – eine SQL-Abfrage prüft beides zusammen. So reicht ein Grant für
  mehrere Worker-Jobs eines Dokuments (`ocr`, dann `ai_extract`), und die
  Spalte `job_id` aus der alten Tabellenbeschreibung entfällt.
- **Löschen**: nach `complete`, nach endgültigem `fail` und wenn der Claim
  einen Job mit erschöpften Versuchen beendet – sobald **kein** Worker-Job
  des Dokuments mehr wartet oder läuft (ein Wiederholungsversuch behält die
  Grants). Beim Entkoppeln alles des Workers. Der Cron löscht als Frist
  Grants nach `expires_at` (3 Tage) und verwaiste (kein wartender
  Worker-Job, älter als 15 min – z. B. nach Rückfall auf den Browser-Weg,
  #53). Gleiches für `worker_context`.
- **Zwei Wege zum Grant**: `WorkerFreigaben::ausSession()` („an den Worker
  übergeben“: Session öffnet die DEKs mit dem Tresor, versiegelt an
  `W_pub`) – verweigert während einer Tresor-Rotation (#90) und mit einem
  Tresor, der nicht die aktuelle Generation ist; und `beiAnlage()`/
  `kontextBeiAnlage()` für frisch erzeugte DEKs ohne Geheimnis. Das
  Einhängen in Einreichung/Routing gehört zu #53; dort entscheidet sich, ob
  ein Dokument zum Worker geht (Setting `worker_modul_aktiv`). Bis dahin
  gilt „nur bei aktivem (bestätigtem) Worker“.
- **Worker-Kontext** nur `{freitext, kostenstelle_hinweis}` – die
  Signatur von `WorkerKrypto::kontextVersiegeln()` kann nichts anderes
  tragen.
- **Artefakt-Upload in drei Schritten** (anmelden → Chunks → festschreiben),
  weil das AAD von `data_enc` die Zeilen-ID enthält, die der Worker vorher
  nicht kennen kann: die Anmeldung legt die `document_artifact`-Zeile mit
  Kind-Präfix `~` an (für Leser unsichtbar, weil jede Abfrage ein Kind
  nennt) und gibt ihre ID samt AAD zurück; das Festschreiben setzt Inhalt
  und Kind in **einer** Anweisung. Blobs (`page_image`, `pdfa`) kommen als
  fertiges secretstream-Chiffrat mit Header und an `VK_pub` versiegeltem
  DEK (`BlobService::storeCipher()`, ohne `meta_enc`, da dessen AAD die
  Blob-ID enthält). Erlaubte Kinds je Job-Typ: `render_pages` →
  `page_image`, `ocr` → `pdfa`+`text`, `image_cleanup` → `pdfa`,
  `ai_extract` → `extraction`. Grenzen: Blob 64 MiB, `data_enc` 4 MiB,
  10 offene Uploads je Worker, seq ≤ 9999. Der Webhoster prüft nur die Form
  (Längen, Versionsbytes, aktuelle Tresor-Generation, SHA-256 des
  Blob-Chiffrats) – den Inhalt kann er ohne Session nicht prüfen.
- **Idempotenz**: ein bereits festgeschriebenes (Dokument, Kind, Job, seq)
  wird als `vorhanden` gemeldet; eine wiederholte gleiche Anmeldung führt
  den offenen Upload fort, eine abweichende oder die eines früheren
  Versuchs ersetzt ihn. Uploads sind an Worker, Job und Versuch gebunden;
  ein neuer Claim kann den Upload des alten nicht fortsetzen.
- **KI-Zugangsdaten** werden nicht an jeder Admin-Aktion eingehängt,
  sondern beim Heartbeat eines aktiven Workers neu versiegelt, wenn sich ein
  HMAC (Schlüssel aus dem Server-Schlüssel, `worker.config_hash`) über die
  aktiven Anbieter-Profile geändert hat. Eine Änderung erreicht den Worker
  so binnen eines Polling-Intervalls; Klartext wird nie gespeichert.
