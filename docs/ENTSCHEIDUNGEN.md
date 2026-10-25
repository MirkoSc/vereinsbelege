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
## E-36 ✅ Erstattungen: Einreicher-IBAN ohne gespeicherten Index, begrenzte Kombinationssuche (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M10-3 (issue #67), 04 §5*

- **Keine Migration, kein neuer Index:** Die IBAN des Einreichers steht nur
  in der versiegelten Einreichung (`submission.payload_enc`). Statt einen
  Blind Index am Beleg zu speichern (müsste in einer Session nachgezogen
  und bei jeder Änderung gepflegt werden), wird sie bei der Suche in der
  Session entschlüsselt und als Blind Index mit demselben Zweck wie
  `bank_transaction.counterparty_bi` (`bank_transaction.counterparty`)
  verglichen. Von der Buchung aus werden dafür die offenen Erstattungen
  gelesen (höchstens 500, ältester Beleg zuerst – ein Verein hat ein paar
  Dutzend; wird abgeschnitten, sagt die Seite das); vom Beleg aus findet
  SQL die Buchungen direkt über `counterparty_bi`. **Offen für Mirko:** Reicht das bei sehr vielen offenen
  Erstattungen nicht mehr, ist ein gespeicherter Index am Beleg die
  Erweiterung.
- **Nur „Erstattung per Überweisung“ mit gültiger IBAN** zählt; ein Beleg
  mit Zahlungsweg `erstattung`, dessen Einreichung bar oder ohne IBAN war,
  wird nur manuell zugeordnet (M10-4). Die IBAN ist nicht in der
  Prüfansicht änderbar – maßgeblich ist, was der Einreicher angegeben hat.
- **Kombinationssuche exakt und begrenzt:** Teilmengensumme über die
  *offenen* Beträge (Brutto − zugeordnet), Ziel = noch nicht zugeordneter
  Betrag der Buchung, **genau** gleich (keine Toleranz). Grenzen: 6 Belege
  je Kombination (Spec), die 20 ältesten Kandidaten, 20 000 Rechenschritte
  (deterministischer Ersatz für ein Zeitlimit, wenige Millisekunden),
  5 Treffer; kleinere Kombinationen zuerst. Schneidet eine Grenze ab, sagt
  die Seite das.
- **Nie automatisch:** Die Kombination wird immer von einem Menschen
  übernommen (`vorschlag_bestaetigt`, `rule_trace` `erstattung_iban: 60`
  aus der Punktetabelle plus Zahl der Belege, `score` leer). Die
  automatische Zuordnung ab 85 Punkten bleibt M10-2 vorbehalten.
- **Eine Transaktion für die ganze Kombination:** `Zuordnung::zuordnen()`
  läuft dafür in einer offenen Transaktion des Aufrufers mit (kleine,
  additive Änderung); jeder Beleg wird mit seinem vollen offenen Betrag
  ausgeglichen. Sperren Dokumente → Belege → Buchung, jeweils aufsteigend.
- **Recht `matching.edit`** für Seite und Zuordnen; dessen Zugriffsbereich
  gilt für Buchung (Buchungsdatum) und Belege (Belegdatum, Kostenstelle).
  **Offen für Mirko:** ob die Seite später in die Abgleich-Oberfläche
  (M10-4) wandert; ob eine Teilerstattung (Überweisung kleiner als die
  Belegsumme) vorgeschlagen werden soll.

## E-38 ✅ Umbuchungen eigene Konten: eigenes Paar, `doc_source = umbuchung`, nur Eindeutiges automatisch (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M10-6 (issue #70, Migration 039), 02 „Fachdaten“, 04 §5 „Stand M10-6“, 05 (Auswertungen)*

- **Eigene Tabelle `transfer`** (Buchung ↔ Buchung, wie in E-27
  vorgesehen): Ausgabe auf dem abgebenden, Einnahme auf dem empfangenden
  eigenen Konto (Bank oder Kasse), je Buchung höchstens ein Paar. Kein
  Betrag in der Zeile – beide Buchungen haben ihn schon; Bedingung ist
  **gleicher Betrag**, Gegenrichtung, verschiedene Konten. Verworfen:
  Allocation-Zeilen ohne Beleg (vermischt zwei Bedeutungen) und eine
  Partner-Spalte an `bank_transaction` (zwei Zeilen müssten sich
  gegenseitig konsistent halten).
- **Status:** Beide Hälften bekommen `doc_source = umbuchung`,
  `doc_required = 0`, `doc_status = nicht_noetig` – kein neuer
  `doc_status`-Wert, damit Listen, Filter und Scoring (M10-2, M10-5)
  Umbuchungen ohne Änderung als „kein Beleg nötig“ sehen; wer sie
  unterscheiden muss, prüft `doc_source`. Regeln und „Einordnung“ lassen
  den Beleg-Bedarf einer Umbuchung in Ruhe; eine Umbuchung nimmt keine
  Zuordnung an und eine Buchung mit Zuordnung wird keine Umbuchung.
  Lösen setzt beide auf den **Standard ihrer Richtung** zurück – eine
  vorher von Hand oder per Regel gesetzte Wahl ist danach weg.
- **Automatisch nur Eindeutiges:** Die IBAN der Gegenseite ist die eines
  anderen eigenen Kontos (Blind Index, kein Entschlüsseln aller Buchungen),
  auf diesem Konto gibt es **genau eine** Gegenbuchung mit gleichem Betrag,
  Gegenrichtung, ±5 Tagen und leerer oder passender Gegenseiten-IBAN – und
  diese hat ihrerseits keinen zweiten Kandidaten. Läuft im letzten
  Import-Schritt (Ausgangsbuchungen im Datumsbereich der Datei ±5 Tage,
  Gegenbuchungen überall, nur Struktur wird geschrieben)
  und per Knopf „Umbuchungen erkennen“ (Zugriffsbereich des Klickenden).
  Alles andere – v. a. Bank ↔ Kasse, wo die Bank keine IBAN meldet – paart
  ein Mensch an der Buchung (Auswahl: anderes Konto, gleicher Betrag,
  Gegenrichtung, ±31 Tage).
- **Auswertung:** Umbuchungen zählen weder als Einnahme noch als Ausgabe.
  Die Buchungsliste zeigt sie als eigene Zeile „Umbuchungen eigene Konten“
  (Summe mit Vorzeichen); der Saldo enthält sie weiterhin, weil er den
  Kontostand erklärt. Spätere Auswertungen (M11) filtern
  `doc_source = umbuchung` heraus.
- **Offen für Mirko:** ob Abweichungen im Betrag (Gebühr bei der
  Umbuchung) erlaubt sein sollen – derzeit nein; ob das Zeitfenster (5/31
  Tage) passt; ob nach dem Lösen die frühere Beleg-Wahl wiederhergestellt
  werden soll (dafür müsste sie gespeichert werden); ob die automatische
  Erkennung beim Import auf den Zugriffsbereich des Importierenden
  beschränkt werden soll.
## E-42 ❓ SEPA-Zahllauf für Erstattungen: pain.001.001.09, nur Eindeutiges, Datei nie gespeichert (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #85 (Migration 043), 02 „Fachdaten“, 04 §5 „Stand SEPA-Zahllauf“*

- **Format pain.001.001.09** nach DK (DFÜ-Abkommen Anlage 3): eine
  `PmtInf` je Lauf, Service-Level `SEPA`, `SLEV`, nur EUR, IBAN-only beim
  Empfänger, Auftraggeberbank per BIC oder `NOTPROVIDED`. Getestet gegen
  das **ISO-20022-Schema** (frei erhältlich, unter `tests/fixtures/sepa/`
  mit Herkunft und Lizenzhinweis); die zusätzlichen DK-Regeln
  (Zeichensatz, Längen, Referenzen) prüft `Pain001` selbst. Das DK-eigene
  GBIC-Schema liegt nicht bei. **Offen für Mirko:** Abnahme mit einem
  echten Upload ins Online-Banking des Vereins (Prüfpunkt im PR); ob die
  Bank `.09` annimmt (Pflicht seit Nov. 2025) oder noch `.03` erwartet.
- **`BtchBookg = false`:** jede Überweisung einzeln auf dem Auszug, damit
  der Erstattungs-Abgleich (E-36) sie über die Empfänger-IBAN findet. Eine
  Sammelbuchung bräuchte einen eigenen Abgleich Lauf ↔ Buchung. **Offen für
  Mirko:** ob die Bank dafür Gebühren je Posten berechnet.
- **Nur Eindeutiges in den Lauf:** Empfänger mit gleichem Namen und
  anderer/fehlender IBAN (E-37), ohne Namen, ohne gültige IBAN, mit
  Vereins-IBAN, Barerstattungen, ungeprüfte Belege und nicht-EUR werden
  **nicht** angeboten (auch nicht abwählbar vorbelegt) – sie werden nach
  Prüfung von Hand überwiesen. Verworfen: mehrdeutige Gruppen mit
  Bestätigungshaken anbieten (zu leicht weggeklickt).
- **Eine Überweisung je Empfänger** (Name + IBAN) über die Summe der
  offenen Beträge (Teilbezahlte mit ihrem Rest); der Abgleich findet sie
  über die Kombinationssuche (E-36, bis 6 Belege). Verwendungszweck mit
  Belegnummer bzw. Belegdatum, damit der Empfänger zuordnen kann.
- **Doppelte Anweisung ausgeschlossen** über `payment_run_item.active_invoice_id`
  (`UNIQUE`, NULL nach Verwerfen) statt nur per Prüfung – hält auch bei
  zwei gleichzeitigen Anfragen. Ein Beleg bleibt reserviert, solange sein
  Lauf `angewiesen` ist, auch nach dem Ausgleich.
- **Status:** gespeichert nur `angewiesen`/`verworfen`; „ausgeglichen“ wird
  aus den Belegen abgeleitet (keine zweite Wahrheit neben `payment_status`).
  Verwerfen ist nur möglich, solange kein Beleg des Laufs seit dessen
  Anlage eine Zuordnung bekommen hat (Teilausführung = Geld ist raus), und
  gedacht für
  eine abgelehnte oder nie eingereichte Datei. **Offen für Mirko:** ob ein
  Lauf nach einer Frist ohne Ausgleich markiert werden soll.
- **Datei nie gespeichert:** alle Angaben verschlüsselt in
  `payment_run.data_enc`; die XML entsteht bei jedem Download im Speicher
  (`attachment`, `no-store`) und wird beim Anlegen einmal probehalber
  erzeugt. Kein Blob, nichts in `var/tmp`.
- **Recht `matching.edit`** (es ist die Gegenseite des Erstattungs-Abgleichs)
  mit dessen Zugriffsbereich: Kandidaten im Bereich; ein Lauf ist nur
  sichtbar, wenn **alle** seine Belege im Bereich liegen. Kein eigenes
  Recht (die Spec sieht keines vor). **Offen für Mirko:** ob ein
  Vier-Augen-Prinzip (Anlegen ≠ Herunterladen) gewünscht ist.
- **Empfängername = Kontoinhaber** laut Einreichung (sonst Name des
  Einreichers), weil die Bank Name und IBAN abgleichen kann (Empfänger-
  überprüfung); nennen Einreichungen derselben IBAN verschiedene Inhaber,
  wird die Gruppe nicht angeboten.
- **Auftraggeber-Name** frei eingebbar, vorbelegt mit dem Vereinsnamen aus
  den Mail-Einstellungen (kein eigenes Setting), Umlaute ausgeschrieben.

## E-41 ✅ Abgleich-Oberfläche: verworfene Vorschläge speichern, Betrag/Suche nur per POST, Seiten-Integration über Links (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M10-4 (issue #68, Migration 042), 02 „Fachdaten“, 04 §5 „Stand M10-4“*

- **„Verwerfen“ wird gespeichert, Vorschläge weiterhin nicht:** Tabelle
  `match_rejection` (nur IDs, wer, wann). Ohne sie käme ein verworfener
  Vorschlag beim nächsten „Abgleich berechnen“ wieder – E-35 („nichts
  gespeichert“) bleibt für die Vorschläge selbst. Ein verworfenes Paar wird
  nicht bewertet und zählt in der Abstandsprüfung nur mit der Obergrenze
  nicht bewerteter Paare: Das „Nein“ des Nutzers kann also ein anderes
  Paar **eindeutig** (automatisch) machen – gewollt, es ist dann der
  einzige plausible Kandidat. Verwerfen sperrt nicht: von Hand zuordnen
  geht weiter, „Wieder vorschlagen“ löscht die Zeile. Fremdschlüssel
  `CASCADE` (nur ein Hinweis zum Paar). Verworfen: Verwerfen nur in der
  Session (überlebt keine Anmeldung) und ein Ablaufdatum (unnötig).
- **Betrag und Suche nur per POST** (wie die Volltextsuche, 03 §10): Sie
  sind Vereinsdaten und gehören nicht in URLs/Server-Logs. Konto,
  Zeitraum, gewählter Beleg und Seite bleiben GET (Struktur, verlinkbar).
  Folge: Nach einer Aktion (Zuordnen, Lösen …) gelten wieder nur die
  Struktur-Filter.
- **Listen:** älteste zuerst (Arbeitsvorrat), 25 je Seite; „Buchungen ohne
  Beleg“ = `fehlt`/`teilweise` (Einnahmen ohne Belegbedarf fluten die
  Liste sonst mit Beiträgen) – ein Einnahme-Beleg findet seine Buchung
  trotzdem, weil die Kandidaten des gewählten Belegs alle offenen
  Buchungen seiner Richtung ab Fensterbeginn umfassen (bewertet, beste
  20; ältere Buchungen nicht – Vorauszahlungen weit vor dem
  Rechnungsdatum findet man so nicht, dafür wächst die Arbeit nicht mit
  jedem Jahr).
- **Von Hand** = Methode `manuell`, aber mit Punkten und `rule_trace` der
  Bewertung, sofern bewertbar (nachvollziehbar, warum das Paar passte).
- **Integration statt Verdopplung:** „kein Beleg nötig“, Regeln,
  Umbuchung paaren (`bank.book`) und die Erstattungs-Kombination (M10-3)
  bleiben auf ihren Seiten und sind aus der Buchungsliste verlinkt –
  eigene Rechte, keine zweite Implementierung derselben Regeln.
- **Offen für Mirko:** ob „kein Beleg nötig“ direkt in der Abgleich-Liste
  schaltbar sein soll (braucht `bank.book` zusätzlich zu `matching.edit`);
  ob die Belegliste auch Belege im Status „in Prüfung“ zeigen soll (heute
  nur geprüfte, wie E-35); Seitengröße 25 und „älteste zuerst“.

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

## E-37 ❓ Arbeitslisten „Offen“: Rechte, Altbestand, „Beleg anfordern“
*M10-5 (issue #69) – (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)*

Die Spec (04 §5 „Übersichten“) nennt nur die vier Listen. Umgesetzt (Details
04 §5 „Stand M10-5“):
- **Vier Seiten unter `/app/offen/...`** mit Reiterzeile statt einer
  Sammelseite: Jede Liste hat ihr eigenes Recht, die Route prüft es. Belege
  (unbezahlt, Erstattungen) `report.view` – es ist eine Auswertung, und so
  sehen Vorstand, Kassenprüfer und Steuerberater sie lesend, ein
  Vereinsverantwortlicher die seiner Kostenstelle. Buchungen `bank.view`
  (eine Buchung hat keine Kostenstelle). Erwartete Belege `document.edit`
  ohne Scope wie die Serienseiten (E-26). Verworfen: `matching.edit` – das
  schlösse alle Lesenden aus.
- **Altbestand getrennt:** Migration 037 hat alle Belege auf `konto`/`offen`
  gesetzt (E-27), auch Archiv-Kassenbelege. Statt einer Fehlerflut zählt die
  Liste Belege, die vor Migration 037 erfasst wurden, nur in einem Hinweis
  und zeigt sie auf Klick gesondert. Kriterium: `created_at` und `updated_at`
  vor `schema_version.applied_at` von 037 – wer den Beleg in der Prüfansicht
  einmal speichert (Zahlungsweg bestätigt), holt ihn in die normale Liste.
  Keine Migration, kein Backfill.
  Verworfen: automatischer Backfill auf `bar` (die Spec sagt nicht, welche
  Alt-Belege bar waren; der offene Punkt aus E-27 bleibt bei Mirko).
- **„Fällig“** = Fälligkeit heute bis in 7 Tagen, **„überfällig“** = Fälligkeit
  vorbei (fest, kein Setting); Belege ohne Fälligkeitsdatum zuletzt, ohne
  Marke.
- **„Beleg anfordern“** zeigt nur einen Text zum Kopieren in eine eigene Mail
  (`<details>`, kein JS) – kein Versand über die Mail-Queue: Empfänger ist
  meist kein Benutzer, und die Queue dürfte keine fachlichen Klartexte
  tragen. Ebenso kein `mailto:`-Link (Klartext in einer URL).
- **Erstattungen je Einreicher und IBAN:** gruppiert nach Name **und** IBAN
  aus der Einreichung. Nur nach Name wäre unsicher: `/einreichen` ist
  öffentlich, jemand könnte unter einem fremden Namen mit eigener IBAN
  einreichen, und die Gruppe zeigte dann seine IBAN neben der ganzen Summe
  der echten Person (Befund im Koordinator-Review). Derselbe Name mit
  verschiedenen oder fehlender IBAN ergibt mehrere Gruppen mit Warnung.
  Belege ohne Einreichung bilden eine eigene Gruppe. SEPA-Sammelüberweisung
  (pain.001) bleibt Backlog; #67 (Erstattungs-Abgleich) und #86 (GiroCode)
  setzen auf diese Listen auf.
- **Link im Mailtext** über `PublicUrl::resolve()` (konfigurierte öffentliche
  URL bzw. Host der Absender-Domain), nicht aus dem Host-Header; ohne
  geprüfte Basis nennt der Text das Formular ohne Link.
- **Nur Anzeige:** kein Zähler in Navigation/Startseite, keine Mail, kein
  Cron (der Cron kann nicht entschlüsseln).

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
  Archivs je Portion). Der Dump war zunächst ein einzelner Request (auch in
  der Update-Kette); seit #208 läuft er ebenfalls in Schritten (E-34).
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

## E-34 ❓ Datenbank-Dump als Schrittkette (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #208, 06 §2, ergänzt E-30 und E-31*

Mit Backend `db` liegen die Belege als `file_blob_chunk`-Zeilen im Dump; ein
Dump in einem Request blieb bei einigen GB im Zeitlimit hängen – eine nicht
erklärte dritte Ausnahme von CLAUDE.md §1. Getroffen ohne Rückfrage:

- **Portionen nur für `file_blob_chunk` und `audit_log`, nach
  Primärschlüssel (Keyset), nicht nach `OFFSET`:** jede Portion kostet
  gleich viel, auch tief in `file_blob_chunk`; der Stand ist der letzte
  geschriebene Schlüssel. Alle anderen Tabellen in einem `SELECT` je Teil
  (konsistent je Tabelle wie vorher): Tabellen wie `supplier_key` oder mit
  `uq_allocation_pair` werden per Löschen + Neu-Einfügen gepflegt, über
  mehrere Portionen könnte derselbe Unique-Wert doppelt in den Dump kommen
  und das Backup wäre nicht einspielbar (Hinweis aus dem Review).
- **Budget je Schritt: 32 MiB Dump-Text oder 8 s**, geprüft nach jedem Teil
  (Tabellenkopf oder 100 Zeilen), mindestens ein Teil. Eine Portion
  `file_blob_chunk` ist bis zu ~50 MiB Hex – gelesen ungepuffert und in
  INSERTs zu 512 KiB geschrieben, der Speicher bleibt klein.
- **In den offenen ZIP-Eintrag, kein Pack-Durchgang am Ende:** jeder
  Schritt schreibt seinen Teil (≤ Budget + eine Portion) in eine
  Arbeitsdatei und hängt ihn gleich als Deflate-Strom mit Sync-Flush an den
  offenen Eintrag `dump.sql` an; der CRC läuft als serialisierter
  `HashContext` im Stand mit. Verworfen: erst eine Dump-Datei über viele
  Schritte, dann packen – das Packen von Gigabytes wäre wieder ein langer
  Request, und der Platz würde doppelt belegt. (Die kleine Arbeitsdatei je
  Schritt ließe sich durch direktes Packen sparen – bewusst einfach
  gehalten.)
- **Speicher-Umzug wartet auf das Backup:** `move()`/`cleanUp()` lehnen ab,
  solange eine Erstellung läuft – sonst könnte ein Beleg, der zwischen dem
  Dump von `file_blob` und `file_blob_chunk` umzieht, im Backup fehlen und
  die Kette trotzdem „erstellt“ melden.
- **Format unverändert:** die Teile ergeben byte-gleich dieselbe Datei wie
  `dump()` am Stück (dieselben Anweisungen, Zeilen jetzt nach
  Primärschlüssel sortiert); Restore (#120) und Installer bleiben gleich.
- **Update-Kette nutzt dieselbe Kette** (ohne Dateien von Backend `fs`,
  ohne `config.php`), ein Aufruf beginnt bis 5 s lang Schritte, Antwort
  `weiter`, die Seite ruft den Schritt erneut auf. Eine Erstellung von der
  Admin-Seite blockiert den Update-Schritt (Fehler, wiederholbar) statt
  parallel zu laufen; die Kette des Updates erscheint umgekehrt auf
  `/admin/backups` als laufend und lässt sich dort abbrechen (Ausweg, wenn
  sie dauerhaft scheitert). Ist die Kette fertig, aber die Antwort an die
  Update-Seite verloren gegangen, startet eine Wiederholung ein neues
  Backup (doppelte Arbeit, kein Datenverlust).
- **Härtung des Einspielens (aus dem Review von #206):** Markierung
  „Schritt läuft“ in der Installer-Session; nach hartem Abbruch gelten
  1062/1050 nur bei der ersten Anweisung des nächsten Schritts als schon
  angewendet. Damit hängt ein Restore nach einem Abbruch durch das
  Zeitlimit nicht mehr an der einen doppelt laufenden Anweisung (Rest des
  offenen Punkts aus E-31: geht die Session selbst verloren, bleibt nur ein
  Restore von vorn).

Offen für Mirko:
- **Kein konsistenter Schnappschuss.** Der Dump läuft jetzt über Minuten
  statt Sekunden; wer währenddessen Belege ändert, kann Tabellen in
  unterschiedlichem Stand im Backup haben (z. B. Chunks eines neuen Belegs
  ohne seine `file_blob`-Zeile). Vorher galt das auch, nur in einem kurzen
  Fenster. Abhilfe wäre Wartungsmodus während des Dumps (sperrt den
  Verein aus) oder ein Hinweis „während des Backups nichts ändern“ – nicht
  umgesetzt.
- Budgets (32 MiB / 8 s / 5 s im Update) sind geschätzt, nicht auf dem
  Zielhost gemessen.

## E-35 ✅ Punktebewertung und Auto-Zuordnung: konservativ, ohne gespeicherte Vorschläge (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M10-2 (issue #66), 04 §5 „Punktebewertung“*

- **Automatisch nur, wenn eindeutig – von beiden Seiten:** Score ≥ Schwelle
  (85), Vorsprung ≥ Abstand (20) vor dem Zweitbesten **sowohl** unter den
  Kandidaten des Belegs **als auch** unter denen der Buchung (ein
  Gleichstand ist nie eindeutig, der Abstand ist mindestens 1), und „Betrag vollständig gedeckt“ heißt:
  offener Betrag des Belegs **=** noch nicht zugeordneter Betrag der
  Buchung. Damit sind Sammelzahlung, Rate und Skonto nie automatisch,
  sondern Vorschläge mit dem Teilbetrag `min(offen Beleg, offen Buchung)`.
- **Betragssignale am offenen Betrag:** „exakt“ vergleicht mit dem noch
  offenen Betrag (bei teilbezahlten Belegen dem Rest), „Skonto“ nur nach
  unten (bis 3 % weniger), nie mehr.
- **Kennungen im Zweck** (Rechnungs-, Kundennummer): verglichen nach
  Kleinschreibung, Trennzeichen in der Kennung egal, über Verwendungszweck
  **und** End-to-End-Referenz (Trennzeichen im Zweck bleiben Grenzen);
  mindestens 3 Zeichen mit einer Ziffer, und eine Ziffernkante darf keine
  weitere Ziffer direkt berühren („815“ steckt nicht in „20260815“).
- **Kandidaten:** nur Belege mit Dokumentstatus `geprueft`/
  `festgeschrieben` (KI-Werte sind Vorschläge, ungeprüfte Beträge sollen
  nichts automatisch ausgleichen), Zahlungsweg `konto`/`erstattung`, noch
  nicht ausgeglichen; Buchungen außer `zugeordnet` und außer Ausgaben mit
  „kein Beleg nötig“ (bewusst ohne Beleg), und nur ab dem Fensterbeginn des
  ältesten offenen Belegs – ohne offenen Beleg wird keine Buchung
  entschlüsselt. Beides im Zugriffsbereich von `matching.edit`.
- **Vorschläge werden nicht gespeichert:** Sie werden bei jedem Aufruf der
  Seite in der Session neu berechnet (keine Migration, kein veralteter
  Vorschlag). „Übernehmen“ bewertet das Paar neu, lehnt ab, wenn es unter
  `vorschlag_ab` gefallen ist oder sich der angezeigte Betrag geändert hat,
  und schreibt über `Zuordnung` (`vorschlag_bestaetigt`, Score, `rule_trace`). Angezeigt
  werden je Beleg bis zu drei Paare ab einer Mindestpunktzahl (`vorschlag_ab`,
  Start 40 – nicht in der Spec-Tabelle).
- **Parameter** als ein Setting `abgleich_parameter` (JSON), auf der
  Abgleich-Seite mit `matching.edit` änderbar; fehlende oder ungültige Werte
  fallen auf die Startwerte zurück.
- **Kandidatenindex statt Kreuzprodukt, ein kurzer Request:** bewertet
  wird nur, was mit dem Beleg einen Anker teilt – offener Betrag exakt oder
  in der Skonto-Spanne, Lieferanten-/Einreicher-IBAN, Mandat/Gläubiger-ID,
  Rechnungs- oder Kundennummer im Zweck (Hash-Maps bzw. eine Textsuche je
  Lauf, `App\Service\Bank\Kandidatenindex`). Ein Betrag, den mehr als 25
  Buchungen teilen (Beiträge, Gebühren), ist kein Anker. Für alle nicht
  bewerteten Paare einer Seite steht die **Obergrenze**, die sie höchstens
  erreichen könnten (Name + Datum, bei gekapptem Betrag + Betragspunkte),
  in der Abstandsprüfung – so macht das Weglassen kein Paar eindeutiger,
  als es ist. Folge: Ein Paar ganz ohne Anker (nur Name/Datum) erscheint nie
  als Vorschlag. Jede Zeile wird einmal entschlüsselt, Zuordnungssummen
  gebündelt gelesen, Buchungen nur in den Richtungen offener Belege und
  in deren Währung verglichen. Der Seitenaufruf rechnet nichts; erst
  „Abgleich berechnen“ (`?berechnen=1`). Gemessen ohne DB: 600 Belege ×
  2.500 Buchungen < 0,5 s (Test-Grenze 5 s). Eine Schrittkette ist damit
  nicht nötig.

Offen für Mirko: „Verwerfen“ eines Vorschlags (braucht eine Tabelle, M10-4);
Auto-Abgleich direkt nach dem Kontoauszug-Import statt per Knopf; ob
ungeprüfte Belege wenigstens als Vorschlag erscheinen sollen; Lernen von
IBAN/Mandatsreferenz beim Lieferanten nach Bestätigung (mit Rückfrage);
Einreicher-IBAN für das Erstattungssignal kommt mit M10-3 (#67); ob Paare
nur mit Namensähnlichkeit (ohne Anker) doch als Vorschlag erscheinen sollen.

## E-32 ❓ Tresor-Schlüsselrotation (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
Issue #90, Details in 01 §2 „Schlüsselrotation“. Getroffen ohne Rückfrage:

- **Nur `dek_sealed` neu versiegeln, Daten nicht neu verschlüsseln.** Die
  DEKs bleiben; das hält jeden Schritt kurz und Blobs unberührt.
  Rest-Exposure: Wer alten `VK_priv` **und** alte Daten (DB-Kopie, Backup)
  hat, liest das damals Vorhandene weiter. Ein vollständiges Neu-
  Verschlüsseln wäre ein eigenes Issue.
- **Freigaben ohne Passwort neu versiegeln** (`box_seal` an `U_pub`) – kein
  Konto muss sich für die Rotation anmelden.
- **Neuer Wiederherstellungsschlüssel mit Bestätigung** (letzte Gruppe); der
  alte bleibt für Backups von vor der Rotation nötig und wird beim Eingeben
  als „abgelöst“ erkannt.
- **Audit-Log:** `dek_sealed` bleibt (Hash-Kette), der neue Schlüssel steht in
  `dek_resealed`; mit dem alten Schlüssel bleiben die Audit-Details von vor
  der Rotation lesbar.
- **Neue Generation erst nach Bestätigung des Papierschlüssels in Gebrauch**
  (bis dahin „vorgemerkt“, nichts wird an sie versiegelt, Umschlüsseln
  gesperrt); bis dahin ist **Abbrechen** ohne Folgen möglich, danach geht
  die Kette nur vorwärts.
- **Während der Rotation sind `/app` und `/admin` gesperrt** – außer
  Rotation, eigener Sicherheit und den Auswegen Wiederherstellung, Update,
  Wartung, Backups; die öffentliche Einreichung läuft weiter.
  Wiederherstellung nimmt den Papierschlüssel jeder aktiven Generation an
  (Passwort-Reset mitten in der Rotation). Passwort-Resets werden während der
  Rotation nicht gesperrt (das sperrte Vergessliche ganz aus).
- **Index-Handler-Fehler** an einer Zeile leeren deren Indexe statt die Kette
  anzuhalten.
- **Blind Indexes** werden neu berechnet; `document.content_bi` (müsste alle
  Dateien lesen) und `bank_import.source_bi` (müsste alle Auszüge parsen)
  werden geleert – der Inhaltsindex entsteht per Job neu, der Kontovorschlag
  beim nächsten Auszug fällt einmal weg. Dedup-Schlüssel behalten ihre
  Laufnummer; passt keine, bekommt die Buchung die nächste freie.
- **Unlesbare Zeilen** (DEK öffnet schon vor der Rotation nicht) halten den
  Abschluss nicht auf, werden gezählt und im Audit-Log genannt.
- **Abschluss beendet alle Sitzungen** (auch weitere des rotierenden Admins).

## E-39 ❓ Worker-Grants, Worker-Kontext und Artefakt-Upload (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #52/M7b-2, 07 §3 und §5, 02 „Betrieb“, Migration 041*

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

## E-40 ❓ GiroCode (EPC-QR) für offene Rechnungen (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Backlog (issue #86), 04 §5 „Stand Backlog: GiroCode“*

- **Eigene Seite statt Code in der Prüfansicht:** `/app/offen/rechnungen/{id}/girocode`
  mit `Cache-Control: no-store`, Recht und Scope der Liste „Unbezahlt“
  (`report.view`). Die Prüfansicht und die Liste verlinken nur. So bleibt
  die Prüfansicht wie sie ist, und nur die Seite mit Zahlungsdaten im Bild
  wird vom Cache ausgenommen.
- **Wofür:** nur Ausgaben mit Zahlungsweg `konto`, Status `offen`/
  `teilbezahlt`, Währung EUR, nicht Altbestand (dessen Status sagt nichts
  über die Zahlung). Betrag = offener Rest, Zweck = Rechnungsnummer,
  Empfänger = Lieferantenname, IBAN des Lieferanten mit gültiger Prüfziffer,
  nie ein Konto des Vereins (E-29) – auch nicht, wenn die IBAN vor dem
  Anlegen des Vereinskontos beim Lieferanten gelernt wurde. Nicht für
  Erstattungen (die Einreicher-IBAN kommt aus dem öffentlichen Formular;
  dafür ist die Sammelüberweisung pain.001 im Backlog).
- **Mehrere IBANs:** ein Code je IBAN mit Hinweis, statt eine zu raten.
  BIC nur bei genau einer IBAN (der Lieferant hat nur ein BIC-Feld).
- **EPC069-12 Version 002, UTF-8**, ohne Purpose-Code und ohne
  strukturierte Referenz; überlange Mehrbyte-Texte kürzen erst den Zweck,
  dann den Namen; was falsch überweisen würde (Prüfziffer, Betrag außerhalb
  0,01–999.999.999,99) wird abgelehnt.
- **Kein neuer Encoder:** `App\Support\QrCode` auf Version 1–13 erweitert,
  geprüft Matrix für Matrix gegen den Referenz-Encoder `qrcode` (PyPI) und
  mit zxing-cpp dekodiert (56 Nutzlasten 1–331 Byte, außerhalb des Repos).

Offen für Mirko: Scan mit echten Banking-Apps (Sparkasse, VR, …) noch
nicht gemacht; ob der Code auch für Erstattungen (Einreicher-IBAN) kommen
soll; ob Kassenprüfer/Vorstand (`report.view`) den Code sehen sollen oder
nur, wer zahlt (`bank.book`/`matching.edit`); ob die Rechnungsnummer allein
als Zweck reicht (z. B. Kundennummer dazu); bei mehreren IBANs die IBAN
von der Rechnung (KI/E-Rechnung) vorziehen.

## E-44 ❓ ReportService: Berichtszeilen, Belege vs. Buchungen, Scope (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M11-1 (issue #71), 05 §1 „Stand M11-1“; Grundlage für M11-2…M11-4 (#72–#74), #84 Sphären, #92 Budget, #93 DATEV*

- **Eine Zeile als gemeinsame Einheit** (`App\Service\Report\Posten`):
  Datum, Seite (Einnahme/Ausgabe), Betrag in Cent auf dieser Seite,
  Kategorie, Kostenstelle, Lieferant, Sphäre, Konto, Beleg- und Buchungs-ID.
  Alle Kennzahlen (Summen, Gruppen, Top-N, Drilldown, Vorjahr) rechnet
  `ReportService` rein auf diesen Zeilen – ohne DB, Session und Tresor;
  `Auswertungsdaten` lädt und entschlüsselt getrennt davon. Neue
  Auswertungen (Budget, DATEV) brauchen so nur eine neue Gruppierung oder
  eine eigene Sicht auf dieselben Zeilen.
- **Belegsicht:** nur Belege mit Dokumentstatus `geprueft`/`festgeschrieben`
  (KI-Werte sind bis zur Prüfung nur Vorschläge), Rechnungsdatum im
  Zeitraum, Bruttobetrag; eine **Gutschrift zählt negativ** auf ihrer Seite.
- **Buchungssicht (Default):** eine Buchung wird **nach ihren Zuordnungen
  aufgeteilt** – jeder zugeordnete Teil (an einen geprüften Beleg) trägt
  Kategorie, Kostenstelle, Lieferant und Sphäre des Belegs (Kategorie der
  Buchung nur, wenn der Beleg keine hat), der Rest die Kategorie der
  Buchung ohne Kostenstelle. Ein Beleg der Gegenrichtung (Rückzahlung einer
  Gutschrift) mindert die Seite des Belegs, so dass Gutschriften in beiden
  Sichten gleich wirken; die Teile einer Buchung ergeben im Saldo immer
  genau ihren Betrag. Zuordnungen über den Buchungsbetrag hinaus werden
  gekappt; Zuordnungen an noch ungeprüfte Belege zählen als Rest.
  **Umbuchungen** (`doc_source = umbuchung`, E-38) ergeben keine Zeile;
  Kontostände und -salden enthalten sie.
- **Kostenstellen-Scope:** Eine Zeile ohne Kostenstelle liegt außerhalb
  jedes Kostenstellen-Scopes (wie in den Repositories). In der
  Buchungssicht sieht ein Verantwortlicher daher nur die **zugeordneten
  Teile** von Buchungen an Belege seiner Kostenstellen – SQL lädt nur solche
  Buchungen (`EXISTS` über `allocation`), und schon der Lader gibt nur
  die Zeilen im Bereich heraus (`Zugriffsbereich::erlaubt()`) – nie den
  Rest oder fremde Teile derselben Buchung; der Service prüft noch einmal. Kontostände/-salden gibt es
  für einen Kostenstellen-Scope nicht (Buchungen haben keine Kostenstelle).
- **Zeitraum-Scope (externe Konten):** Der Zeitraum wird auf den
  freigegebenen Zeitraum begrenzt. Der Anfangssaldo eines Kontos zählt die
  Buchungen davor mit; der Lader fasst sie je Konto zu einem Übertrag am
  Vortag zusammen, keine einzelne Buchung davor verlässt ihn.
- **Sphären** nur mit Setting `sphaeren_aktiv = 1`; sonst wirft eine
  Sphären-Auswertung (`DomainException`), statt still leer zu sein.
- **Währung:** Zeilen in anderer Währung als EUR werden nicht mitgezählt,
  sondern wie unlesbare Zeilen gezählt und gemeldet.
- **Kontosalden:** Anfang (Ende des Vortags) + Eröffnungsbestand (wenn das
  Konto im Zeitraum eröffnet wurde) + Zugänge − Abgänge = Ende; Buchungen
  vor dem Stichtag des Kontos zählen nicht (sie stecken im Anfangsbestand).
- **Laufzeit:** Entschlüsseln kostet gemessen etwa 0,04 ms je Zeile (Sealed
  Box + AEAD); ein Vereinsjahr mit einigen Tausend Belegen und Buchungen
  liegt deutlich unter einer Sekunde, mit Vorjahresvergleich und
  Kontosalden über mehrere Jahre bei wenigen Sekunden. Deshalb **keine
  Schrittkette**; den kurzen, verschlüsselten Session-Cache der Ergebnisse
  baut die Seite (M11-2), die ihn nutzt.

Offen für Mirko: ob ungeprüfte Belege (in Prüfung) in der Belegsicht
wahlweise mitzählen sollen; ob ein Verantwortlicher in der Buchungssicht
auch nicht zugeordnete Buchungen „seiner“ Kategorien sehen soll (derzeit
nein); ob externe Kassenprüfer den Anfangssaldo vor ihrem Zeitraum sehen
dürfen (derzeit ja, nur als Summe); ob Fremdwährungen später umgerechnet
werden sollen.

## E-43 ❓ Worker-Routing, Rückfall und Übernahme der Ergebnisse (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #53/M7b-3, 07 §1, §3, §5 und §6; keine Migration*

Getroffen ohne Rückfrage:

- **Grants entstehen beim Routing aus der Session, nicht bei der
  Einreichung.** Alle Worker-fähigen Schritte werden ohnehin aus einer
  angemeldeten Session eingereiht: `render_pages` von `pdf_erzeugen`, `ocr`
  von `extract_text` (wenn die Seitenbilder gebraucht werden), `ai_extract`
  beim Annehmen im Posteingang, „OCR nachholen“ im Admin.
  `WorkerUebergabe::einreihen()` erteilt dort die Grants über
  `WorkerFreigaben::ausSession()` und reiht mit `executor = worker` ein –
  nur für Dokumente, die wirklich zum Worker gehen. Bei der öffentlichen
  Einreichung liegen die DEKs der zuvor hochgeladenen Blobs nur noch
  tresorversiegelt vor; sie dort an `W_pub` zu versiegeln, hätte eine
  Schemaänderung am Upload gebraucht und jedem Beleg einen Grant gegeben,
  auch denen, die nie zum Worker gehen. `beiAnlage()`/`kontextBeiAnlage()`
  bleiben für Wege ohne Session (z. B. E-Mail-Eingang, Backlog) ungenutzt
  stehen. Folge: Der Worker beginnt erst, nachdem eine Session die ersten
  Schritte (`pdf_erzeugen`, `extract_text`) eines Belegs gemacht hat.
- **Routing-Regel** (`WorkerRouting::workerFuer()`): Setting
  `worker_modul_aktiv = 1`, Worker `aktiv`, Protokoll unterstützt,
  Heartbeat < 5 min, Fähigkeit für den Job-Typ gemeldet. Sonst Browser
  (`render_pages`), Session (`ai_extract`) oder gar nicht (`ocr` –
  „entfällt“). Verweigert `ausSession()` (Tresor-Rotation), läuft der
  Schritt ebenfalls über Browser/Session. Ein Worker-`ai_extract` wird erst
  beansprucht, wenn für dasselbe Dokument kein `pdf_erzeugen`,
  `extract_text`, `render_pages`, `ocr` oder `image_cleanup` mehr wartet
  (`WorkerRouting::VORGAENGER`, Bedingung im Claim).
- **Rückfall im Cron bei jedem Lauf** (`WorkerFallbackTask`, nicht nur im
  Aufräum-Intervall): Ist das Modul aus, kein einsatzbereiter Worker da oder
  der letzte Heartbeat älter als `worker_fallback_minuten` (Standard 60,
  5 … 10080), gehen alle **freien** Worker-Jobs (offen oder mit
  abgelaufener Sperre) zurück – per bedingtem UPDATE an Ort und Stelle
  (Executor wechselt, Schritt/Zustand/Versuche zurückgesetzt), sodass ein
  gleichzeitiger Claim gewinnt. `ocr`/`image_cleanup` enden `uebersprungen`
  (`last_error = WorkerAusfall`). Jobs eines Typs, dessen Fähigkeit der
  Worker nicht mehr meldet, gehen sofort zurück. Ein Job, den der Worker
  mit gültiger Sperre hält, bleibt bei ihm. Modul ausschalten und
  Entkoppeln lösen den Rückfall sofort aus. Endgültiges `fail` oder
  erschöpfte Versuche reihen den Browser-/Session-Gegenpart ein. In beiden
  Fällen werden schon festgeschriebene Worker-Artefakte des Jobs verworfen
  (Präfix `!`) – ungeprüft, und der umgeleitete Job behält seine ID.
- **Übernahme der Ergebnisse als Session-Job** `worker_uebernahme`
  (eingereiht von `complete`): Die Worker-Artefakte sind an den Tresor
  versiegelt, prüfen kann sie nur eine Session. Jedes Artefakt des Laufs
  wird entschlüsselt und auf die Form der Session-Artefakte geprüft;
  unbrauchbare bekommen das Kind-Präfix `!`
  (`DocumentArtifactRepository::DEFEKT`, für Leser unsichtbar, im Admin
  gezählt). Danach: `ocr` – genau ein Text je Original (`seq` 0 … n−1),
  keiner leer, alle brauchbar → `ocr_status = fertig`, ältere Textläufe
  weg; unvollständig → ganzer Lauf markiert; vollständig, aber unbrauchbar
  → bleibt nur für die Suche (Review #222); `ai_extract` – ein als gültig
  gemeldeter Versuch wird serverseitig gegen das Schema `extract-v1`
  geprüft (sonst defekt); `extraktion`, `hinweise` und `meldung` bildet
  der Server mit `Auslesung::auswerten()` neu, die Werte des Workers werden
  nie übernommen (Review #222); der letzte wohlgeformte Versuch beendet den Lauf wie
  die Session-Kette (`ausgewertet`/`ki_fehler`, dann Lieferanten-Auflösung),
  keiner → Session-`ai_extract`; `render_pages` – ein Lauf mit nur einer
  defekten Seite wird ganz verworfen und der Browser rendert. Blob-Artefakte
  werden nur auf Vollständigkeit und öffnenden DEK geprüft, nicht ganz
  entschlüsselt (Größe); der secretstream erkennt Beschädigung beim Lesen.
  Worker-KI-Aufrufe erscheinen (noch) nicht im KI-Protokoll
  (`ai_call_log`); Modell, Prompt-Version und Rohantwort stehen
  verschlüsselt im Artefakt.
- **Leser sind robust gegen Worker-Artefakte** (Review #52):
  `ArtefaktJson::lesen()` behandelt ein Worker-Artefakt, das nicht
  entschlüsselt oder kein JSON ist, als fehlend (Texterkennung,
  E-Rechnung, KI-Auszug) – auch schon vor der Übernahme. Artefakte der
  Session schlagen weiter laut fehl (dort ist das ein Fehler, keine Eingabe).
  Der Volltext-Index fängt zusätzlich `\SodiumException`.
- **Suchindex holt spät sichtbare Artefakte nach** (Review #52): Passiert
  der Cursor eine noch reservierte `~`-Zeile, merkt sich der (verschlüsselte)
  Session-Zustand ihre ID (`nachholen`, Format-Version 2 – alte Indizes
  werden einmal neu aufgebaut). Wird sie sichtbar, nimmt der nächste Schritt
  ihr Dokument neu auf; bis dahin bleibt sie aus der Signatur der
  Kostenstellen-Mitglieder heraus, damit ihr Commit allein keinen Neuaufbau
  auslöst. Keine Schemaänderung.
- **KI-Schlüssel nur mit Freigabe** (Review #52): Die Anbieter-Profile
  samt API-Key werden nur versiegelt, wenn das Modul aktiv ist **und** der
  Worker im selben Heartbeat `ai` meldet; sonst eine leere Profilliste, die
  (über den geänderten Hash) frühere Schlüssel auf dem Worker ersetzt. Mit
  ausgeschaltetem Modul gibt `jobs/claim` nichts aus.
- **Einschalten und „OCR nachholen“ nur mit entsperrtem Tresor**: Wer das
  Modul einschaltet, entscheidet, dass der Worker künftig Belege lesen
  kann – das soll nur, wer sie selbst lesen kann. Das Koppeln/Bestätigen
  bleibt bei `admin.settings` (#51); ob dafür zusätzlich
  `admin.vault_grant` nötig sein soll, ist offen.
- **Alarm-Mail** an alle aktiven Konten mit `admin.settings`, wenn der
  Worker bei aktivem Modul > 24 h schweigt und in diesem Ausfall Jobs für
  ihn anstanden (zurückgefallen oder noch frei) – einmal je Ausfall
  (Kennung = Worker + letzter Heartbeat, Setting `worker_alarm_fuer`).
- „OCR nachholen“ gibt es vorerst nur als Sammelaktion im Admin (50 je
  Klick); der Knopf je Beleg im Posteingang folgt mit der Posteingangs-
  Oberfläche des Workers (offen).

## E-46 ❓ Rechtstexte: Vorlagen im Setting, Mini-Markdown, öffentlich ohne Session (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*M13-3 (issue #80), 03 §1 „Rechtstexte“*

- **Drei pflegbare Texte** unter `/admin/rechtstexte` (`admin.settings`):
  Impressum, Datenschutzerklärung, Datenschutz-Hinweis auf `/einreichen`.
  Gespeichert als Klartext-Settings (öffentliche Texte), keine Migration.
- **Mitgelieferte Vorlagen** (`app/data/rechtstexte/*.md`) mit Platzhaltern
  in eckigen Klammern statt echter Angaben; die App trifft keine
  Rechtsaussage (wie E-10). Solange Vorlage/Platzhalter: sichtbarer Hinweis
  auf der öffentlichen Seite. Die unveränderte Vorlage wird nicht
  gespeichert, damit verbesserte Vorlagen mit Updates ankommen.
- **Format:** minimales Markdown-Subset ohne Roh-HTML
  (`App\View\EinfachesMarkdown`) – genug für Überschriften, Absätze,
  Adresszeilen, Listen, Links; kein HTML-Injection-Weg.
- **Aufbewahrungsfristen nur als Platzhalter** („z. B. 8 bzw. 10 Jahre – mit
  dem Steuerberater zu klären“), weil die App selbst (noch) nichts
  automatisch löscht außer nicht abgesendeten Uploads (24 h).
- Die Admin-Seite listet die genutzten KI-Profile (aktiv, mit Key), damit die
  Datenschutzerklärung den richtigen Anbieter nennt (E-08).

Offen für Mirko: Vorlagentexte juristisch prüfen lassen (Impressumspflicht
des Vereins, Rechtsgrundlagen, Fristen); ob abgelehnte Einreichungen
automatisch nach einer Frist gelöscht werden sollen (eigenes Issue); ob die
Einwilligungs-Checkbox auf `/einreichen` bleiben soll, obwohl die
Verarbeitung auf Art. 6 Abs. 1 lit. b/c DSGVO beruht (sie bestätigt jetzt
„Hinweis gelesen“, keine Einwilligung).

## E-48 ❓ Dashboard auf der Startseite, Session-Cache der Auswertungsdaten (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M11-2 (issue #72), 05 §1 „Stand M11-2“; der Cache ist Grundlage für M11-3/M11-4 (#73, #74)*

- **Ort:** Das Dashboard ist die Startseite `/app` (Issue: „Startseite der
  Anwenderseite“), nicht eine Unterseite von `/app/auswertung`; die
  weiteren Ansichten (#73, #74) bekommen dort ihre eigenen Seiten. Die
  Schnelllinks (Posteingang, Belege erfassen) bleiben oben.
- **Jahr:** `?jahr=JJJJ` von 2000 bis zum laufenden Jahr, sonst das
  Standardjahr – das laufende, für ein Konto mit Zeitraum-Scope, der vor
  dem laufenden Jahr endet (externer Kassenprüfer), das Jahr, in dem er
  endet. Umschalten über „‹ Vorjahr / Folgejahr ›“.
- **Summen:** Einnahmen/Ausgaben/Saldo des Jahres in der **Buchungssicht**
  (05 §1 Default, E-44), begrenzt auf den freigegebenen Teil des Jahres;
  kein Vorjahresvergleich auf dem Dashboard (gehört zu #73).
- **Kontostände:** am Ende des heutigen Tages im laufenden Jahr, sonst am
  31.12.; nie nach dem Ende eines Zeitraum-Scopes; außerhalb davon
  „außerhalb des freigegebenen Zeitraums“. Mit Kostenstellen-Scope kein
  Block (Buchungen haben keine Kostenstelle, E-44). Summenzeile nur, wenn
  alle Konten in EUR geführt werden.
- **Zählwerte** gelten **heute**, unabhängig vom gewählten Jahr, und haben
  das Recht und den Scope der Liste, auf die sie verlinken:
  Posteingang offen (`inbox.view`, wie die Ansicht „Offen“);
  **Belege in Prüfung = die Prüf-Warteschlange** `/app/belege/pruefen`
  (alle prüfbaren Status, `document.edit`; mit nur `inbox.view` die Zahl
  ohne Link); unbezahlte Rechnungen und offene Erstattungen (`report.view`,
  wie „Offen“ – Altbestand vor dem Abgleich nicht mitgezählt, sondern als
  Zusatz „dazu N aus der Zeit vor dem Abgleich“); Buchungen ohne Beleg
  (`bank.view`, `fehlt`/`teilweise`, nicht bei Kostenstellen-Scope);
  überfällige wiederkehrende Belege (`document.edit` ohne Scope, E-26).
  Zählwerte sind Strukturfelder (SQL-`COUNT`) und erscheinen auch mit
  gesperrtem Tresor; Beträge nur mit entsperrtem Tresor.
- **Session-Cache** (`App\Service\Report\AuswertungsCache`, gleiche
  Schnittstelle `Auswertungsquelle` wie `Auswertungsdaten`): gecacht wird
  die **Eingabe** (Berichtszeilen, Kontobewegungen), nicht die Ergebnisse.
  Ablage nur verschlüsselt in der PHP-Session – Schlüssel je Session, an
  den Tresor-Public-Key versiegelt (wie der Suchindex, 03 §10). Schlüssel
  eines Eintrags: Grundlage/Art + Zeitraum + Zugriffsbereich
  (Kostenstellen sortiert, von, bis), als Hash; die ID steht zusätzlich im
  Chiffrat. Höchstens 8 Einträge, 512 KiB Chiffrat, 30 Minuten.
- **Invalidierung konservativ über `CHECKSUM TABLE`** auf `document`,
  `invoice`, `allocation`, `bank_transaction`, `bank_account`, `vault`
  (vor dem Laden gebildet): ändert sich bei jedem Insert/Update/Delete
  einer Zeile, auch beim Löschen einer Zuordnung ohne `updated_at` und beim
  Umschlüsseln. Bewusst **kein Generationszähler** (eine neue Schreibstelle
  könnte das Erhöhen vergessen) und keine Migration. Kosten: ein Scan
  dieser Tabellen je Abruf (Millisekunden bei Vereinsmengen). Folge: Jede
  Änderung an Belegen (auch Status im Posteingang) verwirft den Cache –
  lieber neu laden als eine veraltete Zahl zeigen.

Offen für Mirko: ob das Dashboard einen Vorjahresvergleich zeigen soll;
ob „Belege in Prüfung“ nur Status `in_pruefung` (statt der ganzen
Prüf-Warteschlange) meinen soll; ob unbezahlte Rechnungen aus der Zeit
vor dem Abgleich mitgezählt werden sollen; ob inaktive Konten in den
Kontoständen erscheinen sollen (derzeit ja, markiert).

## E-49 ❓ DATEV-Export (Buchungsstapel) für den Steuerberater (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #93 (keine Migration), 05 §4, 01 §4 (Routen), 02 `setting`*

Das Issue war ein Backlog-Eintrag ohne Abnahmekriterien. Angesetzt wurden
diese (konservativ):

1. Export der **Buchungen** aller Konten und Kassen eines Zeitraums als
   DATEV-Format „Buchungsstapel“ (EXTF 700, Kategorie 21,
   **Formatversion 13**, 125 Spalten), Windows-1252, `;`, CRLF.
2. Konto/Gegenkonto über eine pflegbare Zuordnung Kategorie → Sachkonto
   und Bankkonto/Kasse → Sachkonto (Finanzkonto); Berater-/Mandantennummer,
   Sachkontenlänge, Kontenrahmen als Einstellung (`/admin/datev`,
   `admin.settings`).
3. Belegfeld 1 = Belegnummer, Belegdatum = Buchungsdatum (TTMM),
   Buchungstext ohne personenbezogene Daten.
4. Recht `export.csv` (Steuerberater, Kassenprüfer, Vorstand, Finanzen,
   Admin) – und zusätzlich `bank.view`, weil die Datei alle Buchungen
   zeigt (alle mitgelieferten Rollen haben beides; eine selbst angelegte
   Rolle nur mit `export.csv` bekommt 403). Zeitraum-Scope auf das
   Buchungsdatum in SQL.
5. Entschlüsselt nur in der Nutzer-Session; Datei entsteht im Speicher,
   nichts auf der Platte; ein kurzer Request (wie der SEPA-Zahllauf, E-42).
6. Beträge in Integer-Cent, Ausgabe ohne Vorzeichen mit Komma; das
   Vorzeichen steckt im Soll/Haben-Kennzeichen.

Entscheidungen dabei:

- **Quelle sind die Buchungen, nicht die Belege.** Nur Buchungen haben
  ein Geldkonto und ein Datum, das der Steuerberater bucht; Belege ohne
  Zahlung (offene Rechnungen) sind kein Buchungssatz einer
  Einnahmen-Überschuss-Rechnung. Die Belege selbst liefert der ZIP-Export.
- **Soll/Haben aus Sicht des Geldkontos:** Geld rein = „S“ auf dem
  Finanzkonto, Geld raus = „H“; Gegenkonto = Sachkonto der Kategorie.
  Kein BU-Schlüssel, keine Steuer – Sphären sind aus (E-15); der
  Steuerberater kontiert Steuer selbst. **Offen für Mirko:** ob
  Steuerschlüssel/Sphären später mitgeliefert werden sollen.
- **Umbuchungen** zwischen eigenen Konten (E-38): Ohne Geldtransitkonto
  ergeben sie **eine** Zeile – die abgehende Hälfte, Gegenkonto = Sachkonto
  des empfangenden Kontos, im Zeitraum, in dem das Geld abging; die
  eingehende Hälfte fehlt immer (auch wenn sie im nächsten Zeitraum liegt),
  so bucht kein Stapel sie doppelt. Mit dem optionalen **Geldtransitkonto**
  wird jede Hälfte an ihrem Tag gegen dieses Konto gebucht – richtig auch
  über Monats-/Jahresgrenzen. **Offen für Mirko:** welche Variante der
  Steuerberater will.
- **Unvollständiges wird nicht exportiert:** Fehlt einem Konto oder einer
  Kategorie das Sachkonto, oder ist eine Buchung nicht in EUR, zeigt die
  Seite die Lücken und bietet keinen Download an (auch serverseitig
  gesperrt). Ein stiller Lückenstapel wäre beim Steuerberater falsch.
  Buchungen ohne Kategorie gehen nur mit einem optionalen
  **Klärungskonto**. Buchungen über 0,00 € fehlen (DATEV lehnt sie ab).
- **Belegfeld 1** = Rechnungsnummer des ersten zugeordneten Belegs, der
  **geprüft oder festgeschrieben** ist und im Scope liegt – nie ein
  unbestätigter KI-Vorschlag. Zeichen außerhalb `A-Z a-z 0-9 $ & % * + - /`
  fallen weg, höchstens 36 (das Ende bleibt).
- **Buchungstext** = Kategoriename („Umbuchung“, „ohne Kategorie“) –
  bewusst **ohne** Gegenseite und Verwendungszweck, die Mitglieder nennen
  können. **Offen für Mirko:** ob der Steuerberater mehr Text braucht
  (z. B. Lieferantenname bei Firmen).
- **Kopf:** „Exportiert von“ leer (kein Personenname), Festschreibung `0`
  (der Steuerberater prüft vor dem Buchen), WJ-Beginn 1.1. (E-16), ein
  Stapel höchstens ein Kalenderjahr.
- **Kein Export-Lauf / keine „schon exportiert“-Markierung** wie beim
  ZIP-Export (M12-4) – der Stapel ist aus den Buchungen jederzeit gleich
  wiederholbar. Audit `export.datev` (Zeitraum, Anzahl).
- **Keine Migration:** Zuordnung und Nummern stehen als JSON im Setting
  `datev_einstellungen` (Klartext-Konfiguration). Eine gelöschte Kategorie
  verschwindet still aus der Zuordnung.

Geprüft gegen die öffentliche Formatbeschreibung (Feldregeln im Test
`tests/Service/Export/DatevBuchungsstapelTest.php`) und zusätzlich mit
einem unabhängigen Open-Source-Validator, der DATEVs Musterdaten
nachbildet (keine Befunde). **Offen für Mirko:** ein echter Import beim
Steuerberater (DATEV Rechnungswesen / Unternehmen online) steht aus;
Kontenrahmen-Kennung (42/49), Sachkontenlänge und die Sachkonten je
Kategorie mit dem Steuerberater abstimmen.

## E-50 ❓ Worker-Programm: Prozess-Grenze, Eingaben, Beenden (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #54/M7b-4, CLAUDE.md §1/§2, 07 §5 und §8; keine Migration*

Getroffen ohne Rückfrage:

- **Abgrenzung des Verbots von Prozess-Aufrufen** (CLAUDE.md §1): Es gilt
  weiter für alles, was auf dem Webhoster läuft. Der Worker-Code liegt in
  `worker/src/` (Namespace `App\Worker`, eigener Autoloader
  `worker/autoload.php`, nicht in `composer.json`) und damit **nicht** im
  Release-ZIP; `bin/worker.php` ist nur der Einstieg (ohne eigene
  Prozess-Aufrufe, verweigert alles außer CLI) und wird beim Packen
  ebenfalls ausgenommen. Programme startet genau
  **eine** Datei, `worker/src/Prozess.php`: nur `proc_open()` mit
  Argumentliste (keine Shell), stdin `/dev/null`, Umgebung auf `PATH`,
  `HOME`, `TMPDIR`, `LANG` reduziert, Zeitlimit, Abbruch per SIGTERM →
  SIGKILL. `exec`, `shell_exec`, `system`, `passthru`, `popen`, Backticks
  bleiben auch im Worker verboten. Geprüft von `NoShellExecutionTest`
  (scannt jetzt auch `worker/`) und `WorkerProzessGrenzeTest` (kein Web-Code
  nennt `App\Worker`, `worker/` und `bin/worker.php` nicht im ZIP,
  `composer.json` ohne Worker, Argumentliste). Diese Ausnahme steht jetzt
  auch in CLAUDE.md §1 – eine harte Regel, deshalb ausdrücklich zur
  Bestätigung.
  `ProcessingArchitectureTest` prüft `worker/src/` wie `Service/Processing`.
- **Job-Kontext um Rollen der Blobs erweitert:** `GET /jobs/{id}/context`
  liefert zusätzlich `dokument.originale` (IDs in Seitenreihenfolge),
  `verarbeitet` (parallel, nur gewährte) und `pdf`. Ohne das kann der
  Worker `text`/`pdfa` nicht je Original nummerieren. Nur IDs, keine
  Inhalte; Typen erkennt der Worker an den Magic Bytes nach dem
  Entschlüsseln.
- **`ai_extract` auf dem Worker** nutzt `Auslesung` + `KiAbfrage`
  unverändert, sammelt die Eingabe aber selbst: E-Rechnung aus XML/PDF,
  Text aus den eigenen Textebenen der PDFs, sonst – wenn OCR-Werkzeuge da
  sind – eigener Tesseract-Text (die gespeicherten OCR-Ergebnisse sind an
  den Tresor versiegelt und für den Worker unlesbar), sonst Seitenbilder
  (verarbeiteter Scan, Foto, PDF-Seiten per Poppler). Wie in der Session:
  brauchbarer Text **ersetzt** die Bilder. Keine Lieferanten-Vorauswahl
  (braucht den Blind-Index des Tresors). Ohne Standardprofil oder ohne
  Lesbares → endgültiges `fail` (`KiNichtEingerichtet`/`KiNichtLesbar`),
  die Session übernimmt.
- **`ocr`** liest ein Bild aus seinem verarbeiteten Scan, falls vorhanden.
  Ein Original, das OCR nicht lesen kann (XML, HEIC ohne Scan) oder ohne
  erkannten Text, beendet den Job endgültig – der Webhoster übernimmt nur
  vollständige Läufe, OCR entfällt dann für diesen Beleg.
- **`image_cleanup`** wird nirgends eingereiht und ist im Worker nicht
  umgesetzt: ein solcher Job wird endgültig zurückgegeben
  (`JobTypNichtUnterstuetzt`).
- **Beenden (SIGTERM/SIGINT):** laufendes Werkzeug wird beendet, laufende
  Übertragung abgebrochen, der Job mit `fail` ohne `endgueltig`
  (`WorkerBeendet`) zurückgegeben. Das verbraucht einen der drei Versuche –
  eine Rückgabe ohne Versuchszählung gibt die API nicht her (offen).
- **Arbeitsverzeichnis nur im RAM:** Der Worker startet nicht, wenn das
  Arbeitsverzeichnis laut `/proc/self/mounts` nicht auf `tmpfs`/`ramfs`
  liegt – ohne Schalter zum Abschalten. Je Job ein eigenes Verzeichnis
  (0700), im `finally` gelöscht; Reste eines abgestürzten Workers räumt der
  Start weg.
- **http:// nur für Tests auf demselben Rechner:** `WORKER_ERLAUBE_HTTP_
  LOCALHOST=1` erlaubt http:// ausschließlich zu `localhost`, `127.0.0.1`,
  `[::1]`. Ein End-to-End-Test zweier Container (#55) braucht damit TLS
  oder eine Erweiterung dieser Regel (offen).
- **Uhrzeit vom Webhoster:** Der Pi hat keine gepufferte Uhr. Der Worker
  übernimmt den Versatz aus `server_zeit` des Heartbeats und – einmal – aus
  dem `Date`-Header einer 401-Antwort, und signiert mit der Zeit des
  Webhosters.
- **Wiederholungen:** Netzfehler, 429 und 5xx bis zu 4 Versuche mit
  2/4/8 s Pause, jeder mit neuem Nonce und Zeitstempel; die Schleife
  weicht bei Fehlern 5 s … 5 min (±20 %) zurück. `pair` wird nie
  wiederholt (der Code ist verbraucht).

## E-52 ❓ Kassenprüfung: Seite, Fälle, Rechte, CSV (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M11-4 (issue #74), 05 §1 „Stand M11-4“, 01 §4*

- **Ort:** eigene Seite `/app/auswertung/kassenpruefung` mit eigenem
  Navigationspunkt „Kassenprüfung“ (Recht `report.view`); druckbar über
  das Druck-Stylesheet (Formular und Knöpfe werden nicht gedruckt).
- **Zeitraum:** Jahr/Quartal/Monat/frei (`?art=…`). Standard: der
  freigegebene Zeitraum des Kontos (externe Prüfer), sonst das **letzte
  abgeschlossene Kalenderjahr** – das, was eine Kassenprüfung meist ansieht.
- **Inhalt:** Salden je Konto (Anfang, Zugänge, Abgänge, Ende; Umbuchungen
  eingeschlossen, Eröffnung im Zeitraum vermerkt), **alle Buchungen** des
  Zeitraums älteste zuerst mit Belegstatus und zugeordneten Belegen
  (Umbuchungen markiert, von Hand erfasste Buchungen markiert),
  **ohne Beleg** = Status `fehlt`/`teilweise` mit dem noch unbelegten Rest,
  **von Hand entschiedene Fälle** = Zuordnungen mit Methode `manuell`
  sowie Buchungen, die eine Person als „kein Beleg nötig“ markiert hat
  (`doc_source = manuell`). Bestätigte Vorschläge und automatische
  Zuordnungen zählen nicht als manuell, stehen aber mit ihrer Methode in
  der Gesamtliste.
- **Rechte:** Seite `report.view`, CSV `export.csv`; beide brauchen
  zusätzlich `bank.view` (die Seite zeigt Buchungen) – im Controller
  geprüft, ohne das Recht ein Hinweis bzw. 403. Buchungen und Salden im
  Scope von `bank.view` (Zeitraum, SQL); der Anfangsbestand ist bei
  Zeitraum-Scope der **Übertrag** (E-44) und so beschriftet. Der Beleg
  hinter einer Zuordnung wird nur im Scope von `report.view` benannt
  (Belegdatum/Kostenstelle) – sonst „Beleg außerhalb Ihres
  Zugriffsbereichs“ (z. B. eine Dezember-Rechnung, die im Januar bezahlt
  wurde, für einen Prüfer mit Scope ab Januar).
- **CSV:** je Tabelle eine Datei (`buchungen`, `salden`, `ohne-beleg`,
  `manuell`), UTF-8 mit BOM, Semikolon, Beträge „1.234,56“ mit eigener
  Währungsspalte; Textzellen gegen Formeln abgesichert (Apostroph vor
  = + - @ Tab CR). Nur im Speicher gebaut und als Antwort gesendet, kein
  Audit-Eintrag (rein lesend, wie die Seite). Dateiname nur Teil +
  Zeitraum (`kassenpruefung-salden-2025-01-01-2025-12-31.csv`).
- **Datenquelle:** Salden über den Session-Cache (`Auswertungsquelle`,
  E-48); die Buchungsliste mit Gegenseite/Zweck/Zuordnungen direkt aus der
  DB – der Cache enthält keine Buchungstexte, und sie dort abzulegen würde
  ihn für eine selten geöffnete Seite aufblähen. Keine neue Tabelle, keine
  Migration.

Offen für Mirko: ob „manuell zugeordnet“ auch bestätigte KI-/Abgleich-
Vorschläge umfassen soll; ob der Standard-Zeitraum das Vorjahr bleiben
soll; ob CSV-Downloads ins Audit-Log sollen; ob externe Prüfer den Beleg
hinter einer Zuordnung auch außerhalb ihres Zeitraums sehen dürfen.

## E-51 ❓ „Wohin fließt das Geld“: Seiten, Zeitraumfilter, Drilldown (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: M11-3 (issue #73), 05 §1 „Stand M11-3“*

- **Ort:** `/app/auswertung` leitet auf die erste Ansicht
  `/app/auswertung/geldfluss` weiter; der Drilldown ist
  `/app/auswertung/geldfluss/posten`. Navigation „Auswertungen“ zeigt auf
  `/app/auswertung`, eine Reiterzeile (`partials/auswertung-reiter.php`)
  nimmt weitere Ansichten auf. Recht `report.view` mit seinem Scope.
- **Umfang:** Verteilung nach **Kategorie und Kostenstelle** auf einer
  Seite (Balken + Tabelle, Betrag, Anteil, Anzahl Posten), wahlweise
  **Ausgaben** (Standard) oder **Einnahmen**, Grundlage Buchungen (Standard)
  oder Belege. Lieferant Top 20, Sphäre, Monatsverlauf,
  Vorjahresvergleich und CSV sind nicht Teil des Issues (Folge-Issues).
- **Zeitraum:** der gemeinsame Filter aus M11-4 (`ZeitraumAuswahl`,
  `?art=jahr|quartal|monat|frei`, E-52), Standard wie das Dashboard
  (laufendes Jahr bzw. Ende des Zeitraum-Scopes). Ein freier Zeitraum
  umfasst hier höchstens 3 Jahre (ein Request entschlüsselt alles darin,
  CLAUDE.md §1), sonst Standardjahr mit Hinweis. Die Kassenprüfung hat
  diese Grenze nicht – Folge-Idee: sie in `ZeitraumAuswahl` selbst ziehen.
- **Drilldown:** Gruppe → Einzelposten (Datum, Lieferant bzw. Gegenseite,
  Rechnungsnummer/Zweck, andere Dimension, Betrag). URL nur Struktur:
  Filter + `nach` (kategorie|kostenstelle) + `schluessel` (ID oder `ohne`);
  alles andere 404. Der Beleg-Link führt auf die normale Belegseite
  (Prüfansicht mit `document.edit`, sonst Posteingang mit `inbox.view`),
  die Recht und Scope selbst prüft; der Buchungs-Link nur mit `bank.view`.
  Gegenseite und Zweck einer Buchung ohne Beleg nur mit `bank.view` und
  nur innerhalb von dessen Scope, sonst „Buchung ohne Beleg“.
- **Gutschriften** mindern ihre Gruppe; ein negativer Gruppenbetrag hat
  keinen Anteil („–“) und keinen Balken. Balken relativ zur größten Gruppe,
  Anteil relativ zur Summe der nicht negativen Gruppen (gerundet auf
  0,1 %), damit die Anteile nie über 100 % steigen.
- **Balken:** SVG-`<rect>` mit `width`-Attribut (CSP erlaubt keine
  Inline-Styles), Wert immer als Text. Beide Tabellen sind
  `.tabelle-karten`: auf dem Handy eine Karte je Zeile, der Balken unter
  dem Namen.

Offen für Mirko: ob Ausgaben und Einnahmen gleichzeitig (statt
umschaltbar) gezeigt werden sollen; ob der Drilldown eine zweite Ebene
(Kostenstelle → Kategorie → Beleg) braucht; ob Buchungen ohne Beleg im
Drilldown auch ohne `bank.view` Gegenseite/Zweck zeigen sollen.

## E-53 ❓ Worker-Image: Basis, Tags, Ablauf der Veröffentlichung, End-to-End-Test (vorläufig, autonom getroffen – Bestätigung durch Mirko offen)
*Betrifft: issue #55/M7b-5, 07 §7; keine Migration*

Getroffen ohne Rückfrage:

- **Basis-Image `php:8.5.12-cli-trixie` statt `debian:trixie-slim` +
  `php8.x-cli`:** Debian trixie liefert PHP 8.4, das Projekt verlangt
  PHP 8.5 (CLAUDE.md §1, `composer.json`). Das offizielle PHP-Image ist
  selbst Debian trixie; die Werkzeuge kommen weiter aus Debian. Gepinnt auf
  die Patchversion (Tag, kein Digest); Updates des Basis-Images sind eine
  bewusste Änderung im PR.
- **Tags:** `vX.Y.Z` bei jedem Tag-Push, nach bestandenem
  End-to-End-Test; `beta` nur, wenn es das höchste Versions-Tag ist (ein
  nachgeschobener Patch für eine ältere Linie zieht `beta` nicht zurück). `stable` beim Hochstufen eines Pre-Release zum
  vollwertigen Release (`release: released`), aber nur, wenn es laut
  GitHub das neueste Release ist – ein nachträglich veröffentlichter
  älterer Patch zieht `stable` nicht zurück. `stable` wird per
  `imagetools create` auf das vorhandene `vX.Y.Z` gesetzt, nicht neu
  gebaut. Kein `latest`-Tag (die Vorlage nutzt `stable`).
- **Sichtbarkeit des Pakets:** Ein neues GHCR-Paket ist zunächst privat.
  Damit ein Pi ohne Anmeldung `docker compose pull` kann, muss es einmalig
  in den Paket-Einstellungen auf „public“ gestellt werden (offen, Mirko).
- **`pcntl` hart geprüft:** `bin/worker.php run` beendet sich ohne `pcntl`
  mit Fehlermeldung (bisher still ohne Signal-Handler); `pair`/`status`
  gehen weiter ohne. Der Image-Build prüft zusätzlich alle nötigen
  Erweiterungen und Werkzeuge inkl. der Tesseract-Sprachen `deu`, `eng`,
  `osd`.
- **Container-Härtung in der Vorlage:** Benutzer `worker` (UID 10001),
  `read_only`, `cap_drop: ALL`, `no-new-privileges`, `tini` als PID 1,
  `stop_grace_period: 30s`, `mem_limit: 2g` bei tmpfs 768 MB und
  `WORKER_SPEICHER=512M` (tmpfs zählt zum Speicherlimit; für einen Pi mit
  2 GB ggf. kleiner wählen – Anleitung #56).
- **End-to-End-Test mit TLS statt Ausnahme für http://:** Die in E-50
  offene Frage ist so gelöst: `run.sh` erzeugt je Lauf eine Test-CA und ein
  Zertifikat für `webhoster`; der Worker vertraut nur dieser CA
  (`curl.cainfo` im Test-Overlay), die Prüfung bleibt an. Die
  Webhoster-Seite (Tresor anlegen, bestätigen, einreichen, Übernahme wie
  in der Session) spielt ein CLI-Treiber im Webhoster-Container, nicht die
  Browser-Oberfläche – die Admin-/Session-Seiten haben eigene Tests.
  Der Scan wird direkt als Einreichung in den Posteingang gelegt und das
  `ocr` so eingereiht, wie es `extract_text` tut.
- **Wo der Test läuft:** in jedem PR (CI-Job `worker-e2e`, amd64) und vor
  jedem Image-Push. Der Multi-Arch-Build (arm64 unter QEMU, langsam) läuft
  in PRs nur, wenn Worker, Composer-Dateien oder der Workflow geändert
  sind.
