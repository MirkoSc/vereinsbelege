# 04 – Konten, Kontoauszug-Import, Abgleich

## 1. Konten

- Mehrere Konten: `bank` (Girokonten bei **Sparkasse** und **VR Bank**,
  ggf. Sparbuch), `kasse` (Barkasse, Vereinsheim-Kasse – Buchungen manuell).
  Kein PayPal.
- Je Konto: Name, IBAN (Tresor), Anfangssaldo zu einem Stichtag.
- Kassenbuchungen werden manuell erfasst (Datum, Betrag, Zweck, Beleg
  direkt verknüpfbar) – ein Barbeleg ist damit sofort „zugeordnet".
- **Manuelle Buchung ohne Beleg** ist auf jedem Konto möglich (v. a.
  Einnahmen: Bareinnahmen Getränkeverkauf, Spenden in bar, Eintritt) –
  Pflichtfelder Datum, Betrag, Richtung, Kategorie, Zweck.
- **Einnahmen** (E-17): Einnahme-Buchungen sind per Default „kein Beleg
  nötig" (Setting), können aber jederzeit mit einem Einnahme-Beleg
  (Sponsoring-, Werbe-, Vermietungsrechnung) verknüpft werden. Kasse-
  Kassensturz: Ist-Bestand eingeben → Differenz wird als Buchung mit Grund
  „Kassendifferenz" vorgeschlagen.

**Umsetzung (M9-1, issue #59):** `/app/konten`, Tabellen `bank_account` und
`cash_count` (Migration 021) – Regeln, Rechte und Pflicht-Tests in
02-datenmodell.md „Konten“. Kurz: Bankkonto und Kasse sind ein Modell
(`kind`), IBAN/Name/Anfangssaldo im Tresor, IBAN eindeutig über
`iban_bi`; Lesen `bank.view`, Pflegen und Kassensturz `bank.book`. Der
Kassensturz zeigt Soll/Ist/Differenz vor dem Speichern und hält beides
append-only fest.

**Umsetzung (M9-5, issue #63):** `/app/buchungen` (`App\App\
BuchungController`, Regeln und Verschlüsselung in `App\Service\Bank\
Buchungen`, Filter `App\Service\Bank\BuchungFilter`), keine Migration –
`bank_transaction` (M9-4) nimmt manuelle Buchungen schon auf. Rechte wie
bei den Konten: **Liste und Einzelansicht `bank.view`**, **Erfassen,
Ändern, Löschen `bank.book`**; CSRF auf jedem POST; alles braucht den
entsperrten Tresor (ohne: nur ein Hinweis). Festgelegte Regeln:

- **Buchungsliste:** alle Buchungen (Import und manuell), neueste zuerst,
  als Karten unter 48 rem. Filter Konto, Von/Bis, Richtung, Beleg-Status,
  Herkunft, Kategorie (auch „ohne Kategorie“) in SQL, die Suche in Zweck,
  Gegenseite und Buchungstext nach dem Entschlüsseln in PHP. **Ohne
  Datumsangabe gilt das laufende Jahr** (Geschäftsjahr, E-16) – jede
  angezeigte Zeile wird entschlüsselt; beide Datumsfelder leeren zeigt
  alle Jahre. Darüber die Summen Einnahmen/Ausgaben/Saldo der angezeigten
  Zeilen. Der Zeitraum-Scope externer Rollen filtert `booking_date` per
  `Zugriffsbereich::sqlBedingung()`; eine Buchung außerhalb ist auch
  einzeln nicht abrufbar (404). Eine Buchung hat keine Kostenstelle – ein
  Kostenstellen-Scope sieht keine.
- **Kennzeichnung:** Marke je Beleg-Status („Beleg fehlt“ warnend, „kein
  Beleg nötig“ neutral, „Beleg zugeordnet“ ok) und „manuell“ für von Hand
  erfasste Buchungen. Eine Einnahme ohne Beleg ist damit sichtbar, aber
  kein Mangel.
- **Manuelle Buchung** (auf jedem aktiven Konto, Bank wie Kasse):
  Pflicht Konto, Datum, Betrag, Richtung, Kategorie, Zweck (≤ 300
  Zeichen); optional „Von wem / an wen“ (≤ 100 Zeichen, landet in
  `counterparty_name`). Der Betrag wird **positiv** eingegeben, die
  Richtung gibt das Vorzeichen (`amount` in Cent mit Vorzeichen wie beim
  Import). Datum nicht in der Zukunft und **nicht vor dem Stichtag** des
  Kontos – der Bestand zählt Buchungen ab dem Stichtag. Die Kategorie muss
  zur Richtung passen (oder `beide`); deaktivierte Kategorien und Konten
  werden nicht angeboten, eine bestehende Buchung behält ihre.
- **Beleg nötig?** Auswahl „nach Richtung“ (Default, E-17: Ausgabe ja,
  Einnahme nein), „Beleg nötig“ oder „kein Beleg nötig“ → `doc_required`/
  `doc_status`. Das Setting und Regeln dafür kommen mit M9-6.
- **Ändern/Löschen** nur für `source = manuell` (das Repository schränkt
  `UPDATE`/`DELETE` zusätzlich auf `manuell` ein); eine importierte Buchung
  ist schreibgeschützt und zeigt alle Bankfelder. Ein zugeordneter Beleg
  (M10) bleibt beim Ändern zugeordnet und verhindert das Löschen.
  Bestehende Kassenstürze bleiben unverändert (sie halten Soll und Ist
  ihres Zeitpunkts fest).
- **Kassenbuchungen im Soll-Bestand:** `Kassensturz::sollBestand()` =
  Anfangsbestand + Summe der Buchungen vom Stichtag bis einschließlich
  Datum.
- **Kassendifferenz:** Weicht ein gespeicherter Kassensturz von den
  Buchungen ab, führt „Kassensturz speichern“ zu
  `/app/buchungen/neu?kassensturz=<id>`: vorausgefüllt mit dem Datum des
  Kassensturzes, der **offenen** Differenz (gezählt − Soll laut aktueller
  Buchungen) als Betrag, Fehlbetrag = Ausgabe / Überschuss = Einnahme,
  Zweck „Kassendifferenz“, „kein Beleg nötig“ (der Kassensturz ist der
  Nachweis) und – falls vorhanden und aktiv – Kategorie „Sonstiges“ bzw.
  „Sonstige Einnahmen“. Nichts wird ohne Bestätigung gebucht. Solange der
  letzte Kassensturz abweicht, zeigt die Kassenseite den Hinweis „Als
  Kassendifferenz buchen“; die Differenz wird jedes Mal neu berechnet, eine
  Verknüpfung Kassensturz ↔ Buchung wird nicht gespeichert.
- **Audit** (Entität `bank_transaction`): `buchung.angelegt` (Konto-ID,
  Richtung, Beleg-Status), `buchung.geaendert` (nur Namen der geänderten
  Felder; ohne Änderung weder Zeile noch Schreibzugriff),
  `buchung.geloescht` (Konto-ID). Nie Betrag, Zweck oder Gegenseite.
- Ein **Beleg direkt verknüpfen** (Barbeleg sofort „zugeordnet“) kommt mit
  dem Allocation-Modell (M10-1).

**Pflicht-Tests M9-5:** `BuchungFlowTest` (Einnahme ohne Beleg:
verschlüsselt, Cent mit Vorzeichen, `nicht_noetig`, markiert; Ausgabe-
Default `fehlt` und Auswahl in beide Richtungen; Validierung mit markiertem
Feld inkl. Stichtag/Zukunft/Richtung der Kategorie/deaktiviert; Ändern mit
Feldnamen im Audit, Löschen; Import schreibgeschützt; alle Filter und
Jahres-Default; Zeitraum-Scope Liste und Einzelansicht; Soll-Bestand mit
Kassenbuchungen; Kassendifferenz als Fehlbetrag und Überschuss, nach dem
Buchen verschwunden; Verwendung von Konto und Kategorie; Rechte, ohne
Tresor, CSRF, 404), `BuchungFilterTest`, `RoutePermissionMatrixTest`
(Lesen `bank.view`, Schreiben `bank.book`).

## 2. MT940-Import

Eigener Parser `Service/Bank/Mt940Parser` (reines PHP, keine Bibliothek
nötig – Format ist überschaubar und so vollständig testbar):

- Encoding: Default Windows-1252/ISO-8859-1, UTF-8 erkennen; nach UTF-8
  konvertieren.
- Mehrere Auszüge je Datei; Felder über Zeilenumbrüche fortgesetzt.
- `:25:` Konto (BLZ/Kontonr. oder IBAN) → Konto-Zuordnung (Blind Index),
  sonst Nachfrage „Welches Konto?" mit Merken.
- `:60F:/:60M:` Anfangssaldo, `:62F:/:62M:` Schlusssaldo →
  **Saldenprüfung**: Anfang + Summe(:61:) = Schluss, sonst Import-Warnung.
- `:61:` Valuta, Buchungsdatum (Jahreswechsel beachten!), `C`/`D`/`RC`/`RD`,
  Betrag mit Komma, Buchungsschlüssel, Referenz.
- `:86:` deutsches Strukturformat: GVC (3 Ziffern), `?00` Buchungstext,
  `?20–?29` + `?60–?63` Verwendungszweck (zusammensetzen), SEPA-Schlüssel
  `EREF+`, `KREF+`, `MREF+`, `CRED+`, `SVWZ+`, `ABWA+`, `ABWE+` herauslösen,
  `?30` BIC, `?31` IBAN, `?32/?33` Name Gegenseite. Unstrukturiertes `:86:`
  als reiner Text tolerieren.

Umsetzung (M9-2): `Mt940Parser::parse()` liefert je Auszug ein
`Kontoauszug` (mit `Kontoangabe`, `Saldo`, `Umsatz`, `Umsatzdetails`) –
reine Werteobjekte, nichts wird gespeichert oder geloggt; die Fehlermeldung
(`Mt940Exception`) nennt nur Zeile und Feld. Festgelegte Regeln:

- Buchungsdatum (`MMTT` ohne Jahr): das Jahr, das am nächsten an der Valuta
  liegt. Tage hinter dem Monatsende (Zinsvaluta „30.02.") → letzter Tag.
- Vorzeichen: `C`/`RD` positiv, `D`/`RC` negativ; `RC`/`RD` setzt `storno`.
- Verwendungszweck-/Namensteilfelder (27 Zeichen) werden direkt
  aneinandergehängt, wenn das vorige Teilfeld voll war, sonst mit
  Leerzeichen. SEPA-Schlüssel zählen nur am Anfang eines Teilfelds;
  `verwendungszweck` = `SVWZ+`, ohne Schlüssel der ganze Text.
- `:25:` als IBAN (geprüft, ggf. mit Währung) oder `BLZ/Kontonummer`
  (BLZ auch als BIC); sonst nur Rohwert. SWIFT-Kopfblöcke, `:NS:` und nicht
  benötigte Felder (`:21:`, `:64:` …) werden übersprungen, ebenso ein
  `:86:` ohne vorangehendes `:61:`.
- Saldenprüfung je Auszug (`saldoStimmt()`, `saldoDifferenzCent()`) –
  Abweichung ist eine Warnung, kein Abbruch; Fehlen von `:25:`, `:60x:`
  oder `:62x:` ist ein Fehler.

## 3. CSV-Import

- **Profile** (`csv_profile`): Trennzeichen, Encoding, Datumsformat,
  Dezimaltrenner, Spalten-Mapping (Buchungstag, Valuta, Betrag bzw.
  Soll/Haben getrennt, Gegenseite Name/IBAN/BIC, Verwendungszweck, EREF,
  MREF, Gläubiger-ID, Buchungstext).
- Automatische Erkennung über die Kopfzeilen-Signatur. Mitgelieferte
  Profile (per Seed, mit echten Beispieldateien testen – anonymisiert unter
  `tests/fixtures/bank/`): **Sparkasse CSV-CAMT** und **VR Bank CSV-CAMT**
  (Varianten V2/V8 der Kopfzeilen berücksichtigen).
- **Empfehlung für den Verein: MT940** als Standard-Import (enthält Anfangs-
  und Schlusssaldo → Saldenprüfung möglich); CSV-CAMT als gleichwertige
  Alternative, dort ohne Saldenprüfung, stattdessen Abgleich gegen den
  zuletzt bekannten Kontostand, falls die Datei Salden-Spalten hat. Weitere per Assistent: Datei hochladen → Spalten
  zuordnen mit Live-Vorschau der ersten 10 Zeilen → als Profil speichern.

Umsetzung (M9-3): `Service/Bank/Csv/` – `CsvParser::parse()` liefert ein
`CsvErgebnis` mit `CsvBuchung`en (je ein `Umsatz` wie beim MT940-Parser, dazu
Währung und ggf. Saldo nach Buchung), `CsvZeilenfehler`n und der Zahl
übersprungener vorgemerkter Zeilen. Reine Werteobjekte, nichts wird
gespeichert oder geloggt. Festgelegte Regeln:

- Spalten werden über ihren **Namen** zugeordnet, nie über die Position;
  ein Feld darf mehrere Namen haben (Aliasse für Kopfzeilen-Varianten,
  bei Textfeldern wie Verwendungszweck werden alle vorhandenen verbunden).
  Namen werden normalisiert verglichen (Groß/klein, Umlaute ↔ ae/oe/ue,
  Leerraum).
- Pflicht: Buchungstag und entweder eine Betragsspalte mit Vorzeichen oder
  Soll **und** Haben. Soll zählt negativ, Haben positiv – egal welches
  Vorzeichen die Bank schreibt; genau eine der beiden muss gefüllt sein.
- Kopfzeile = erste Zeile (in den ersten 30), die alle Pflichtspalten des
  Profils trägt; ein Vorspann davor wird übersprungen.
- Datum `TT.MM.JJJJ`/`TT/MM/JJJJ` auch mit zweistelligem Jahr, sonst ISO;
  Beträge als Integer-Cent mit dem gewählten Dezimaltrenner (der andere ist
  Tausendertrenner), höchstens zwei Nachkommastellen. Leere Valuta =
  Buchungstag, leere Währung = EUR.
- Zeichensatz „Automatisch“: gültiges UTF-8 (oder BOM) ist UTF-8, sonst
  Windows-1252 – so nutzen es die mitgelieferten Profile.
- Status-Spalte mit „vorgemerkt“ → Zeile wird übersprungen und gezählt
  (noch nicht gebucht, kommt mit dem nächsten Export wieder).
- Nichts wird geraten: eine Zelle, die nicht zum Profil passt, macht die
  Zeile zum Zeilenfehler. Meldungen nennen Zeile und Spaltenname, nie den
  Inhalt (`CsvException` für nicht lesbare Dateien ebenso).
- Profil-Erkennung (`CsvProfilErkennung`): exakte Kopfzeilen-Signatur
  (SHA-256 der normalisierten Spaltennamen) zuerst, sonst das Profil mit
  allen Pflichtspalten und den meisten gefundenen Feldern; bei Gleichstand
  das mitgelieferte.
- Formaterkennung für unbekannte Dateien (`CsvFormatErkennung`):
  Zeichensatz, Trennzeichen (`;` `,` Tab `|` – die häufigste gleiche
  Spaltenzahl), Kopfzeile, Datums- und Zahlenformat sowie ein
  Zuordnungsvorschlag aus den Spaltennamen.
- Mitgeliefert (`CsvStandardprofile`, Seed in Migration 022): „Sparkasse
  CSV-CAMT“ (Kopfzeile V2, V8 über die Namenszuordnung) und „VR Bank
  CSV-CAMT“ (mit „Saldo nach Buchung“). Sie sind schreibgeschützt.
- Assistent unter `/app/konten/csv-formate` (Recht `bank.import`): Jede
  Änderung schickt das Formular samt Datei per htmx an die Vorschau; die
  Datei wird nur im Speicher gelesen – kein Blob, keine eigene Temp-Datei,
  nichts in der Session. Gespeichert wird nur das Profil.

## 4. Import-Ablauf

1. Datei hochladen (Upload-Komponente, als Blob verschlüsselt gespeichert –
   Original bleibt nachvollziehbar).
2. Parsen → Vorschau: Zeitraum, Anzahl, davon neu/Duplikat, Saldenprüfung.
3. Bestätigen → Buchungen schreiben (Schrittkette bei großen Dateien).
4. Danach automatisch: Kategorisierungsregeln, `doc_required`-Regeln,
   Abgleich (Abschnitt 5) als Jobs.

**Duplikate** bei überlappenden Exporten: `dedup_bi = HMAC(Konto,
Buchungsdatum, Betrag, normalisierter Zweck, Gegenseite-IBAN,
laufender Index innerhalb identischer Schlüssel am selben Tag)`. Derselbe
Export zweimal importiert → 0 neue Buchungen.

**Umsetzung (M9-4, issue #62):** `/app/konten/import`, Recht `bank.import`
auf jeder Route (CSRF auf jedem POST), Tabellen `bank_import` und
`bank_transaction` (Migration 025, Spalten in 02 „Fachdaten“). Fachlogik in
`App\Service\Bank\Import\` (`KontoauszugLeser`, `Dedupschluessel`,
`Saldenpruefung` rein und ohne Repository; `KontoauszugImport` als Service).
Alles braucht den entsperrten Tresor, nur „Verwerfen“ nicht. Festgelegte
Regeln:

- **Upload** über ein normales Formular (höchstens 2 MB, wie der
  CSV-Assistent), nicht über die Chunk-Komponente: Kontoauszüge sind klein,
  und die Komponente nimmt nur Bilder/PDF unter `document.submit_internal`
  an. Die Datei wird einmal probegelesen und dann unverändert als
  verschlüsselter Blob gespeichert (Originalname nur in `meta_enc`). Was
  sich nicht importieren lässt, wird abgelehnt, **ohne** etwas zu speichern:
  unlesbar, leer, keine Buchung, CSV ohne passendes Format (mit Link auf
  „CSV-Formate“), MT940 mit Auszügen **mehrerer Konten** (ein Import gehört
  zu einem Konto). Meldungen nennen höchstens Zeile und Feld.
- **Format:** „Automatisch“ erkennt MT940 an `:20:`/`{1:` in den ersten
  Zeilen, sonst CSV über die Profil-Erkennung (§3); wählbar sind auch MT940
  oder ein bestimmtes CSV-Format. Gespeichert als `mt940` bzw.
  `csv:<profil-id>` – jeder Schritt liest die Datei genau so wie die
  Vorschau.
- **Konto:** (1) die IBAN aus `:25:` bzw. BLZ/Kontonummer als deutsche IBAN
  (`App\Domain\Iban::ausBlzUndKonto()`) über `iban_bi`; (2) sonst das Konto
  des letzten fertigen Imports mit derselben Kontozeile (`source_bi`,
  „Merken“); (3) sonst die Auswahl im Formular; (4) sonst fragt die Vorschau
  „Welches Konto?“. CSV-Dateien nennen kein Konto. Eine Auswahl, die der
  Datei widerspricht, wird abgelehnt (mit Name des richtigen Kontos). Nur
  aktive Bankkonten nehmen Importe an – keine Kasse, kein deaktiviertes.
- **Vorschau** (nichts geschrieben außer `bank_import` + Blob, bei jedem
  Aufruf neu aus der Datei berechnet): Datei, Konto, Zeitraum, Anzahl, davon
  neu / schon vorhanden (Dedup-Schlüssel gegen das Konto), vor dem Stichtag,
  fehlerhafte CSV-Zeilen (Zeile + Meldung, nicht übernommen), vorgemerkte
  Umsätze (übersprungen), Saldenprüfung und alle Buchungen (aufklappbar).
- **Stichtag:** Buchungen vor dem Stichtag des Anfangssaldos werden
  **mitübernommen** und in der Vorschau gezählt; zum Kontostand ab Stichtag
  gehören sie nicht.
- **Warnungen statt Abbruch:** Saldenabweichung und Zeilenfehler halten den
  Import nicht auf; der Knopf heißt dann „Trotz Warnungen übernehmen“. Eine
  korrigierte Datei bringt später genau die fehlenden Buchungen nach.
- **Dedup-Schlüssel** (`App\Service\Bank\Import\Dedupschluessel`, Zweck
  `bank_transaction.dedup`): Wert `[Konto-ID, Buchungsdatum, Cent, Zweck,
  Gegen-IBAN]` + laufende Nummer unter gleichen Werten in Dateireihenfolge.
  Der Zweck (`verwendungszweck`, bei MT940 der `SVWZ+`-Teil) zählt klein
  geschrieben und nur mit Buchstaben/Ziffern – 27-Zeichen-Fügungen und
  Leerraum spielen keine Rolle. Ein Wechsel zwischen MT940 und CSV für
  denselben Zeitraum wird nur erkannt, wenn die Bank in beiden Formaten
  denselben Zweck liefert – nicht garantiert.
- **Saldenprüfung** (`Saldenpruefung`, Ergebnis `ok`/`abweichung`/`n.v.` in
  `balance_check`, Differenzen nur in der Vorschau): MT940 je Auszug
  Anfang + Umsätze = Schluss und lückenlos von Auszug zu Auszug; CSV mit
  „Saldo nach Buchung“ als Kette Zeile für Zeile; CSV ohne Salden `n.v.`.
  Dazu der **Anschluss** („zuletzt bekannter Kontostand“, §3): Anfangsstand
  der Datei vor ihrem ersten Buchungstag gegen Anfangssaldo + gespeicherte
  Buchungen ab Stichtag bis zum Vortag – nicht prüfbar, wenn die Datei vor
  dem Stichtag beginnt.
- **Reihenfolge:** Eine CSV-Datei, die neueste zuerst schreibt, wird
  umgedreht (an den Daten erkannt, bei einem einzigen Tag an der
  Saldenkette); geschrieben wird älteste zuerst.
- **Schrittkette** (seitengesteuert, nicht über `/api/jobs/step`, 06 §4):
  „Übernehmen“ setzt `laeuft`, `public/js/kontoauszug.js` ruft
  `POST /app/konten/import/{id}/schritt` bis `fertig`. Ein Schritt schreibt
  höchstens 100 Buchungen in **einer** kurzen Transaktion und rückt den
  Cursor nur von genau dem Wert weiter, den er gelesen hat (zweiter Tab oder
  wiederholter Request schreibt nichts doppelt); `UNIQUE(account_id,
  dedup_bi)` ist das Netz darunter. Ein Schritt ohne Fortschritt beendet die
  Kette mit „Fortsetzen“. Am Ende zählt `neu` die Zeilen des Imports,
  `duplikat` den Rest.
- **Neue Buchungen:** `direction` aus dem Vorzeichen (0 gilt als Einnahme),
  Ausgabe `doc_required = 1`/`fehlt`, Einnahme `0`/`nicht_noetig` (E-17; das
  Setting und Regeln kommen mit M9-6), `source = import`, `counterparty_bi`
  aus der IBAN der Gegenseite.
- **Verwerfen** löscht eine Vorschau samt Datei; ein bestätigter Import
  bleibt. Liegen gebliebene Vorschauen räumt der Cron nach 7 Tagen ab
  (`BankImportCleanupTask`, 06 §4).
- **Audit:** `kontoauszug.importiert` (Entität `bank_import`) beim
  Abschluss, Details nur Format, Zähler und Saldenprüfung – nie Beträge,
  IBANs, Namen oder der Dateiname.
- Was danach automatisch laufen soll (Punkt 4 oben: Regeln, `doc_required`,
  Abgleich), hängen M9-6 und M10 an den Abschluss eines Imports.

**Pflicht-Tests M9-4:** `KontoauszugLeserTest`, `DedupschluesselTest`
(gleiche Datei, laufende Nummer, Normalisierung, Teil-/Gesamtexport MT940
und CSV), `SaldenpruefungTest` (MT940 ok/abweichend/Lücke/Währung,
CSV-Kette auf-/absteigend, n.v., Anschluss), `IbanTest` (BLZ → IBAN),
`KontoauszugImportFlowTest` (Sparkasse- und VR-Bank-Fixtures für MT940 und
CSV-CAMT; verschlüsselt, Blind Indexes nachgerechnet; doppelter und
überlappender Import; Anschluss und Lücke; Warnungen; Stichtag; vorgemerkt;
Zeilenfehler und Nachimport; Konto gemerkt, Konflikt, Kasse/inaktiv;
Ablehnung ohne Speichern; Verwerfen; wiederholter/paralleler Schritt; ohne
Tresor, CSRF, 404; Verwendungszählung Konto/Kategorie; Audit),
`BankImportCleanupTaskTest`, `RoutePermissionMatrixTest` (nur
`bank.import`), `tests/js/kontoauszug.test.js`.

## 5. Abgleich Beleg ↔ Buchung

### Modell
- `allocation` verbindet Beleg und Buchung mit Teilbetrag → deckt ab:
  1:1, eine Überweisung für mehrere Rechnungen (Sammelzahlung), mehrere
  Raten für eine Rechnung, Skonto-Differenz (als Differenz mit Grund
  „Skonto" abschließbar), Gutschrift gegen Rechnung.
- Beleg-Zahlungsstatus und Buchungs-Belegstatus werden aus den Allocations
  **abgeleitet** (Summen), nicht separat gepflegt.

### Punktebewertung (Parameter als Settings, Startwerte)
| Signal | Punkte |
|---|---|
| Betrag exakt (Brutto, Vorzeichen passend) | +45 |
| Betrag innerhalb Skonto-Toleranz (≤ 3 %) | +20 |
| Rechnungsnummer im Verwendungszweck (normalisiert) | +40 |
| Gegenseite-IBAN = Lieferanten-IBAN | +30 |
| Mandatsreferenz/Gläubiger-ID bekannt beim Lieferanten | +30 |
| Gegenseite-Name ähnlich Lieferantenname (≥ 0.8) | +15 |
| Kundennummer im Zweck | +15 |
| Buchungsdatum im Fenster [Rechnungsdatum − 5 T, Fälligkeit + 45 T] | +10 |
| außerhalb des Fensters | −20 |
| Erstattung: Empfänger-IBAN = Einreicher-IBAN und Betrag = Belegsumme | +60 |

- **Automatisch zugeordnet**, wenn Score ≥ 85 **und** Abstand zum
  zweitbesten Kandidaten ≥ 20 **und** Betrag vollständig gedeckt. Sonst
  **Vorschlag** mit Begründung (`rule_trace`: welche Signale zogen).
- Erstattungen: mehrere Belege desselben Einreichers können in **einer**
  Überweisung erstattet sein → Kombinationssuche (Teilmengensumme, max. 6
  Belege, begrenzt auf offene Erstattungen dieser IBAN).
- Nach einer bestätigten Zuordnung lernt der Lieferant IBAN/Mandatsreferenz
  (mit Rückfrage).

### Oberfläche „Abgleich"
- Zwei Listen nebeneinander: offene Belege | Buchungen ohne Beleg, Filter
  Konto/Zeitraum/Betrag; Vorschläge oben mit „Übernehmen" / „Verwerfen".
- Manuell: Beleg anklicken → passende Buchungen nach Score sortiert, frei
  durchsuchbar; Teilbetrag eingebbar.
- Buchung als **„kein Beleg nötig"** markieren (z. B. Zinsen, Umbuchungen
  zwischen eigenen Konten; Einnahmen sind es per Default bereits) – optional „Regel daraus
  machen" (Gegenseite/Stichwort → immer „kein Beleg nötig" + Kategorie).
- Umbuchungen zwischen eigenen Konten (IBAN der Gegenseite = eigenes Konto)
  werden automatisch erkannt und paarweise verknüpft.

### Übersichten
- **Unbezahlte Rechnungen** (offen, teilbezahlt, fällig/überfällig)
- **Offene Erstattungen** je Einreicher (mit Summe; Backlog: SEPA-
  Sammelüberweisung pain.001 erzeugen)
- **Buchungen ohne Beleg** (doc_required, nicht zugeordnet) – mit Aktion
  „Beleg anfordern" (Mail-Vorlage) oder „Beleg hochladen"
- **Erwartete, aber fehlende Belege** aus wiederkehrenden Serien

## Pflicht-Tests

MT940: mehrere Auszüge, Fortsetzungszeilen, Jahreswechsel im `:61:`,
RC/RD-Storno, strukturierte/unstrukturierte `:86:`, SEPA-Schlüssel,
Encoding, Saldenprüfung ok/abweichend – mit anonymisierten echten
Beispieldateien; CSV-Profile inkl. Auto-Erkennung, Soll/Haben-Spalten,
Dezimalkomma, Tausenderpunkt; Duplikaterkennung bei überlappendem und
doppeltem Import (Idempotenz); Abgleich: jede Signal-Regel einzeln,
Auto-Schwelle + Abstandsregel, Sammelzahlung, Ratenzahlung, Skonto,
Erstattungs-Kombinationssuche, Umbuchung eigene Konten, abgeleitete Status;
Rechte (Import nur `bank.import`); Einnahme-Default „kein Beleg nötig";
manuelle Buchung ohne Beleg; Kassensturz-Differenz; Sparkasse- und
VR-Bank-Fixtures für MT940 und CSV-CAMT.
