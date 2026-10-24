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
  `doc_status`. Ob Einnahmen standardmäßig einen Beleg brauchen, stellt
  das Setting `buchung_einnahme_beleg_noetig` ein (M9-6, §5 „Stand M9-6“);
  „nach Richtung“ nennt den aktuellen Wert.
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

## 2a. CAMT.053-Import

**Umsetzung (issue #91):** eigener Parser `Service/Bank/Camt053Parser`
(reines PHP auf ext-dom, keine Bibliothek) liefert dieselben Werteobjekte
wie MT940 (`Kontoauszug`, `Kontoangabe`, `Saldo`, `Umsatz`,
`Umsatzdetails`) plus die Zahl übersprungener vorgemerkter Einträge
(`Camt053Ergebnis`). Damit laufen Vorschau, Saldenprüfung, Dedup-Schlüssel,
Schrittkette und Regeln unverändert durch die Import-Pipeline (§4).
Nichts wird gespeichert oder geloggt; `Camt053Exception` nennt nur die
Position (Datei im ZIP, Auszug, Buchung, Element), nie den Inhalt.
Festgelegte Regeln:

- **Versionen:** camt.053.001.02 bis .08 (und spätere gleicher Struktur).
  Elemente werden über ihren lokalen Namen gefunden, der Namensraum wird
  nicht ausgewertet (auch ohne oder mit Präfix). Versionsunterschiede:
  `<Sts>BOOK</Sts>` bzw. `<Sts><Cd>BOOK</Cd></Sts>`, `Cdtr/Nm` bzw.
  `Cdtr/Pty/Nm`, `BIC` bzw. `BICFI`.
- **Datei:** eine XML-Datei mit einem oder mehreren `Stmt`, oder ein
  **ZIP** mit mehreren solchen Dateien (Tagesauszüge eines Zeitraums). Aus
  dem ZIP zählen nur `.xml`-Einträge (ohne `__MACOSX/` und Punktdateien),
  höchstens 5 MB je Datei und 10 MB entpackt insgesamt – gelesen mit
  `App\Service\Archiv\ZipLeser` im Speicher, nichts landet in `var/tmp`.
  Jeder Schritt der Kette liest die Datei neu (wie bei MT940/CSV); ein Jahr
  Tagesauszüge mit 7.300 Buchungen (4,5 MB XML) dauert rund 0,5 s bei
  35 MB Speicher. Derselbe Auszug (`Stmt/Id`) zweimal wird einmal gelesen
  (auch seine vorgemerkten Einträge zählen einmal). Die Auszüge werden nach
  Anfangs-, dann Schlusssaldo-Datum, dann Auszugsnummer sortiert (sonst
  stabil), damit Reihenfolge und laufende Dedup-Nummer nicht von der
  Dateireihenfolge im ZIP abhängen. Ein `DOCTYPE` wird abgelehnt – vor dem
  Parsen per Bytesuche und danach am Dokument (UTF-16) –, keine Entitäten,
  kein Netz. Fehlermeldungen zählen „XML-Datei n im ZIP“ und „Auszug n“ je
  Datei.
- **Konto:** `Acct/Id/IBAN` (als Kontozeile und für die Zuordnung über
  `iban_bi`), sonst `Acct/Id/Othr/Id` nur als Rohwert („Merken“ wie bei
  MT940). Auszüge mehrerer Konten in einer Datei/einem ZIP → abgelehnt.
- **Salden:** Anfang = `OPBD`, sonst `PRCD` (so liefern es die meisten
  deutschen Banken, datiert auf den Vortag), sonst das erste `ITBD`;
  Schluss = `CLBD`, sonst das letzte `ITBD` (dann als Zwischensaldo wie
  `:62M:`). Andere Saldenarten (`CLAV`, `FWAV` …) werden ignoriert. Fehlt
  Anfang oder Schluss, ist das ein Fehler.
- **Einträge:** nur Status `BOOK`; `PDNG`/`INFO` werden übersprungen und als
  „vorgemerkt“ gezählt (wie bei CSV). Je `Ntry` ein `Umsatz`: Betrag als
  Dezimalzahl mit Punkt (mehr als zwei Nachkommastellen nur als Nullen),
  Vorzeichen aus `CdtDbtInd`, `RvslInd` setzt `storno` (das Vorzeichen
  bleibt die Wirkung auf den Saldo, wie `RC`/`RD`). Buchungsdatum
  `BookgDt`, Valuta `ValDt` (fehlt eins, gilt das andere); `DtTm` zählt als
  Kalendertag in Europe/Berlin.
- **Bankcode:** `BkTxCd/Prtry/Cd` im DK-Format „NTRF+166+931“ →
  Buchungsschlüssel, GVC, Textschlüsselergänzung; ohne ihn der
  `SubFmlyCd` als Buchungsschlüssel. `AddtlNtryInf` (sonst `AddtlTxInf`)
  ist der Buchungstext.
- **Details** (`NtryDtls/TxDtls`): Gegenseite bei einer Gutschrift der
  Zahler (`Dbtr`, `DbtrAcct`, `DbtrAgt`), bei einer Lastschrift/Überweisung
  der Empfänger (`Cdtr` …), bei einem Storno die Gegenseite der
  ursprünglichen Richtung. `EndToEndId` (ohne `NOTPROVIDED`) → `EREF` und
  Kundenreferenz, `MndtId` → `MREF`, Gläubiger-ID (`Cdtr/…/PrvtId/Othr/Id`)
  → `CRED`, `UltmtDbtr`/`UltmtCdtr` → `ABWA`/`ABWE`. Verwendungszweck =
  die `Ustrd`-Elemente verbunden (ein volles 140-Zeichen-Element wird
  direkt fortgesetzt, sonst mit Leerzeichen), ohne `Ustrd` die strukturierte
  Referenz (`Strd/CdtrRefInf/Ref`), ohne beides der Buchungstext.
- **Sammelbuchung** (mehrere `TxDtls` in einem `Ntry`): bleibt **eine**
  Buchung (so stimmt der Saldo und so zeigt sie auch MT940); die
  Verwendungszwecke aller Posten werden mit „ / “ verbunden, Gegenseite und
  Einzelreferenzen bleiben leer.
- Keine Migration: `bank_import.format` ist `camt053`; der Blob trägt den
  Typ `application/xml` bzw. `application/zip`.
- **Dedup MT940 ↔ CAMT:** Dieselben Buchungen ergeben bei den deutschen
  Banken in beiden Formaten denselben Schlüssel (Zweck = `SVWZ+` bzw.
  `Ustrd`, Gegen-IBAN, Datum, Betrag) – der Test mit den Sparkasse-Fixtures
  belegt das; garantiert ist es nur, wenn die Bank denselben Zweck liefert.
  Für Sammelbuchungen (und Entgelte), deren Zweck sich zwischen den
  Formaten unterscheidet, greift der **Ersatzabgleich** (§4).
- **Fremdwährung:** Ein `Ntry`, dessen `Amt/@Ccy` von der Währung des
  Schlusssaldos abweicht, macht die Datei unlesbar (Meldung mit Auszug und
  Buchung). UTF-16-Dateien (mit BOM) werden erkannt und gelesen.

**Pflicht-Tests (issue #91):** `Camt053ParserTest` (Versionen .02/.04/.08
und beliebiger, fehlender oder präfixierter Namensraum; ZIP mit mehreren
Tagesauszügen, sortiert, fremde Dateien ignoriert, doppelter Auszug einmal;
PRCD/OPBD/CLBD/ITBD; nur `BOOK`, vorgemerkt gezählt; Storno beider
Richtungen; Sammelbuchung; Gegenseite je Richtung; SEPA-Schlüssel;
`Ustrd`-Fügung, `Strd`; `DtTm` in Europe/Berlin; dieselben Buchungen wie
die MT940-Fixture; Ablehnungen ohne Inhalt inkl. DOCTYPE, camt.052/.054,
zu großes ZIP), `KontoauszugLeserTest` (Erkennung XML/ZIP, ZIP mit MT940
kein MT940, mehrere Konten), `SaldenpruefungTest` (CAMT ok/abweichend,
Tageskette im ZIP), `DedupschluesselTest` (MT940 → CAMT gleiche Schlüssel,
ZIP ↔ einzelner Tag), `KontoauszugImportFlowTest` (VR-Bank .08
verschlüsselt mit Vorschau, vorgemerkt, Gegenseite und Audit; Wechsel
MT940 → CAMT ohne neue Buchungen; ZIP mit Anschluss; Ablehnungen ohne
Speichern).

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
  und Schlusssaldo → Saldenprüfung möglich), gleichwertig **CAMT.053**
  (XML, §2a – Nachfolger von MT940, ebenfalls mit Salden); CSV-CAMT als gleichwertige
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
  CSV-Assistent; ein CAMT-ZIP mit seiner gepackten Größe), nicht über die Chunk-Komponente: Kontoauszüge sind klein,
  und die Komponente nimmt nur Bilder/PDF unter `document.submit_internal`
  an. Die Datei wird einmal probegelesen und dann unverändert als
  verschlüsselter Blob gespeichert (Originalname nur in `meta_enc`). Was
  sich nicht importieren lässt, wird abgelehnt, **ohne** etwas zu speichern:
  unlesbar, leer, keine Buchung, CSV ohne passendes Format (mit Link auf
  „CSV-Formate“), MT940/CAMT.053 mit Auszügen **mehrerer Konten** (ein
  Import gehört zu einem Konto). Meldungen nennen höchstens Zeile und Feld.
- **Format:** „Automatisch“ erkennt CAMT.053 an XML (`<` am Anfang) oder
  einem ZIP – vor MT940, weil ein unkomprimiertes ZIP den Text seiner
  Dateien enthält –, dann MT940 an `:20:`/`{1:` in den ersten Zeilen, sonst
  CSV über die Profil-Erkennung (§3); wählbar sind auch MT940, CAMT.053
  oder ein bestimmtes CSV-Format. Gespeichert als `mt940`, `camt053` bzw.
  `csv:<profil-id>` – jeder Schritt liest die Datei genau so wie die
  Vorschau. Ein camt.052-Bericht oder eine camt.054-Datei wird mit Hinweis
  auf CAMT.053 abgelehnt.
- **Konto:** (1) die IBAN aus `:25:` (CAMT: `Acct/Id/IBAN`) bzw. BLZ/Kontonummer als deutsche IBAN
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
- **Ersatzabgleich** (issue #91, ohne Migration): Eine Buchung der Datei
  **ohne Gegen-IBAN** (Sammelbuchung, Entgelt), deren Schlüssel neu ist,
  gilt trotzdem als Duplikat, wenn das Konto eine **importierte** Buchung
  ohne Gegenseite mit gleichem Buchungstag und Betrag hat, die nicht aus
  dieser Datei stammt (ihr Schlüssel ist keiner der Datei). Eins zu eins in
  Dateireihenfolge gezählt – zwei solche Buchungen eines Tages brauchen zwei
  gespeicherte. So bleibt eine Sammelbuchung beim Wechsel MT940 ↔ CAMT.053
  eine Buchung, obwohl MT940 und CAMT ihr verschiedene Zwecke geben. Jeder
  Schritt der Kette rechnet das gleich (die selbst geschriebenen Zeilen
  tragen Schlüssel der Datei); das Netz `UNIQUE(account_id, dedup_bi)`
  bleibt unberührt. Manuelle Buchungen zählen nicht mit.
- **Saldenprüfung** (`Saldenpruefung`, Ergebnis `ok`/`abweichung`/`n.v.` in
  `balance_check`, Differenzen nur in der Vorschau): MT940 je Auszug
  Anfang + Umsätze = Schluss und lückenlos von Auszug zu Auszug (ebenso
  CAMT.053, auch über die Tagesauszüge eines ZIP); CSV mit
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
  Ausgabe `doc_required = 1`/`fehlt`, Einnahme `0`/`nicht_noetig` (E-17; Einnahme
  `1`/`fehlt`, wenn das Setting `buchung_einnahme_beleg_noetig` es verlangt),
  darüber die älteste passende aktive Regel (M9-6, §5 „Stand M9-6“),
  `source = import`, `counterparty_bi` aus der IBAN der Gegenseite.
- **Verwerfen** löscht eine Vorschau samt Datei; ein bestätigter Import
  bleibt. Liegen gebliebene Vorschauen räumt der Cron nach 7 Tagen ab
  (`BankImportCleanupTask`, 06 §4).
- **Audit:** `kontoauszug.importiert` (Entität `bank_import`) beim
  Abschluss, Details nur Format, Zähler und Saldenprüfung – nie Beträge,
  IBANs, Namen oder der Dateiname.
- Was danach automatisch laufen soll (Punkt 4 oben): Regeln und
  `doc_required` wendet der Import-Schritt selbst an (M9-6) – der Klartext
  liegt dort ohnehin entschlüsselt vor, ein eigener Job müsste ihn nur noch
  einmal lesen. Den Abgleich hängt M10 an den Abschluss eines Imports.

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

#### Stand M10-1: Allocation-Modell + abgeleitete Status (issue #65, Migration 037, E-27)

- **Zuordnung** (`allocation`, `App\Service\Bank\Zuordnung`): ein Beleg
  (`invoice`) ↔ eine Buchung (`bank_transaction`) mit **Teilbetrag** in
  Integer-Cent, immer positiv – der Teil des Belegbruttos, den diese Buchung
  ausgleicht. Je Paar höchstens eine Zeile (`UNIQUE`); eine Korrektur löst
  und legt neu an. Der Betrag liegt im Tresor (eigener DEK), die Struktur
  (IDs, `method`, `score`, `rule_trace`) ist Klartext. Beide Fremdschlüssel
  `RESTRICT`.
- **Regeln beim Anlegen** (alle im Service, unter Zeilensperre beider
  Seiten): Betrag > 0; ≤ offener Betrag des Belegs (Brutto − zugeordnet;
  0 bei abgeschlossener Differenz) **und** ≤ noch nicht zugeordneter Betrag
  der Buchung (|Betrag| − zugeordnet); Richtung der Buchung = Richtung des
  Belegs – bei einer **Gutschrift** die Gegenrichtung (Geld fließt zurück);
  der Beleg muss eine Buchung erwarten (Zahlungsweg `konto` oder
  `erstattung`); sein Dokument darf nicht **abgelehnt** sein. Alles andere
  ist eine `BankRuleViolation` mit Feld. Sperr-Reihenfolge überall:
  Dokument → Beleg → Buchung.
- **Gegenseiten, die Zuordnungen respektieren** (jeweils unter Zeilensperre
  neu geprüft): eine Handbuchung mit Zuordnung lässt sich weder löschen
  noch in Betrag/Richtung ändern; ein Beleg mit Zuordnungen lässt sich nicht
  als Duplikat verwerfen; Regeln und Einordnung überschreiben `zugeordnet`/
  `teilweise` nie.
- **Zahlungsweg** (`invoice.payment_kind`, `App\Domain\Zahlungsweg`, Feld
  der Prüfansicht): `konto` – über Bank oder Kasse gebucht (Default);
  `erstattung` – Einreicher hat verauslagt, Verein erstattet (M10-3); `bar` –
  bar bezahlt **ohne** Kassenbuchung, der Beleg ist der Zahlungsnachweis;
  `keine` – kein Zahlungsbezug (verrechnete Gutschrift, Information).
  Vorbelegt aus der Einreichung (`ueberweisung` → `erstattung`, `bar` →
  `bar`, `keine` → `konto`) und aus dem Archiv-Ordner `Kasse` → `bar`
  (05 §3). Nur `konto` und `erstattung` nehmen Zuordnungen an.
- **Abgeleitete Status** – reine Funktionen, von `Zuordnung` nach jeder
  Änderung in derselben Transaktion geschrieben, nie von Hand:
  - Beleg (`App\Domain\PaymentStatus::ableiten(weg, brutto, zugeordnet,
    differenz)`): `keine` → `kein_zahlungsbezug`; `bar` → `bezahlt`;
    sonst zugeordnet = 0 → `offen` (`erstattung`: `erstattung_offen`),
    zugeordnet ≥ brutto **oder** Differenz abgeschlossen → `bezahlt`
    (`erstattung`: `erstattet`), dazwischen `teilbezahlt`.
    `App\Domain\Zahlungsstand` liefert dazu `offen()` (was noch
    zuzuordnen ist) und `differenzbetrag()`.
  - Buchung (`App\Domain\BankTransactionDocStatus::ableiten(belegNoetig,
    betrag, zugeordnet)`): ohne Zuordnung `fehlt`/`nicht_noetig` nach
    `doc_required`; zugeordnet ≥ |Betrag| → `zugeordnet`, sonst
    `teilweise` (Sammelüberweisung, ein Beleg fehlt noch). `hatBeleg()`
    (= `teilweise` oder `zugeordnet`) schützt die Buchung vor Löschen, und
    weder Regel noch „Einordnung“ ändern diesen Status – nur die
    Zuordnungen (`App\Domain\Belegstand`).
- **Differenz abschließen** (`difference_reason`: `skonto`, `gutschrift`,
  `sonstiges`): nur wenn ein Teil zugeordnet ist und ein Rest fehlt; der
  Beleg gilt dann als ausgeglichen, der Rest wird berechnet, nicht
  gespeichert. „Wieder öffnen“ nimmt es zurück; eine gelöste Zuordnung,
  die den Rest verschwinden lässt oder nichts Zugeordnetes übrig lässt,
  räumt den Grund automatisch. **Gutschrift gegen Rechnung** = Rechnung mit
  Grund `gutschrift` abschließen, Gutschrift selbst `keine`.
- **Prüfansicht und Zuordnungen:** Solange ein Beleg Zuordnungen hat,
  lehnt die Prüfansicht einen Zahlungsweg ohne Buchungserwartung, einen
  Wechsel der Richtung oder von/zu Gutschrift und ein Brutto unter der
  zugeordneten Summe ab (Feld markiert). Unabhängig davon verlangt ein
  Zahlungsweg mit Buchungserwartung ein Brutto **> 0** (Gutschriften
  positiv mit Belegart „Gutschrift“, 0-€-Belege mit „kein
  Zahlungsbezug“) – sonst bliebe der Beleg für immer offen. Ändert sich Zahlungsweg oder
  Brutto, wird der Status neu abgeleitet. Ein **festgeschriebener** Beleg
  nimmt Zuordnungen an – die Festschreibung schützt den Inhalt, nicht den
  Zahlungsverlauf.
- **Export** (05 §2): „bezahlt am“ (Datum der letzten ausgleichenden
  Buchung), „Konto“ (Namen der Konten), „Referenz“ (Verwendungszwecke) für
  **ausgeglichene** Belege; `{kasse}` bei Zahlungsweg `bar` oder wenn alle
  ausgleichenden Buchungen auf Kassen liegen
  (`Zuordnung::zahlungsangaben()`). Die Buchungsangaben sind eigene Daten:
  nur mit `bank.view` und nur aus Buchungen in dessen Zugriffsbereich
  (eine Buchung hat keine Kostenstelle – ein Kostenstellen-Scope sieht
  keine); ohne das Recht bleiben die drei Spalten leer.
- **Audit:** `zuordnung.angelegt` (invoice_id, transaction_id, method,
  score), `zuordnung.geloest` – Entität `allocation`;
  `beleg.differenz_abgeschlossen`/`beleg.differenz_geoeffnet` (grund) –
  Entität `document`. Nie ein Betrag.
- **Keine Oberfläche** in M10-1: Zuordnungen entstehen erst mit M10-2/M10-4;
  M10-1 liefert Modell, Service, Zahlungsweg-Feld und Export-Spalten.

**Pflicht-Tests M10-1:** `PaymentStatusTest`, `BankTransactionDocStatusTest`
(Ableitung je Fall), `ZuordnungFlowTest` (1:1, Sammelzahlung mit
`teilweise`, Ratenzahlung, Skonto-Differenz mit Öffnen und Grenzen,
Gutschrift Gegenrichtung/verrechnet, Erstattungs-Vokabular, `bar`/`keine`
ohne Zuordnung, Richtung, Betrag > 0, Paar nur einmal, Lösen leitet beide
Status neu ab und räumt den Differenzgrund, zugeordnete Buchung gegen
Löschen/Regel/Einordnung, festgeschriebener Beleg, Prüfansicht schützt
Zuordnungen, Betrag nur als Chiffrat, Audit ohne Betrag, Export-Spalten und
`{kasse}`), `PruefungFlowTest` (Zahlungsweg-Feld, Vorbelegung aus Archiv
und Einreichung).

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

#### Stand M10-2: Punktebewertung, Auto-Zuordnung, Vorschläge (issue #66, E-35)

- **Bewertung** (`App\Service\Bank\Punktebewertung`, reine Funktionen):
  nur Paare, die zugeordnet werden könnten (Richtung passt – Gutschrift
  Gegenrichtung –, beide Seiten haben noch etwas offen). Signale wie in der
  Tabelle, Beträge gegen den **offenen** Betrag; „Skonto“ nur nach unten;
  Rechnungs-/Kundennummer normalisiert (Trennzeichen egal) in
  Verwendungszweck und End-to-End-Referenz (≥ 3 Zeichen mit Ziffer,
  Ziffernkante berührt keine Ziffer);
  IBAN gegen die IBANs des Lieferanten, Mandatsreferenz bzw. Gläubiger-ID
  gegen dessen Mandate/Gläubiger-ID; Name ≥ 80 % (`LieferantenAehnlichkeit`)
  gegen Name und Aliasse; Fenster ohne Fälligkeit ab Rechnungsdatum.
  Erstattungssignal vorbereitet, die Einreicher-IBAN liefert M10-3.
- **Automatisch** (`Punktebewertung::planen`): Score ≥ `auto_schwelle`,
  Vorsprung ≥ `auto_abstand` (≥ 1, Gleichstand nie) vor dem Zweitbesten
  des Belegs **und** der Buchung, offene Beträge beider Seiten gleich. Methode `auto`, `score`,
  `rule_trace` = `{"v":1,"signale":{"<signal>":<punkte>,…}}` – nie ein Wert.
- **Vorschläge:** alle übrigen Paare ab `vorschlag_ab` Punkten, je Beleg die
  drei besten, mit Begründung je Signal und Teilbetrag
  `min(offen Beleg, offen Buchung)`. Nicht gespeichert – bei jedem Aufruf in
  der Session neu berechnet; „Übernehmen“ bewertet neu (abgelehnt unter
  `vorschlag_ab` oder bei geändertem Betrag) und ordnet als
  `vorschlag_bestaetigt` zu. Jede Zuordnung bleibt über `Zuordnung::loesen`
  lösbar.
- **Kandidaten:** Belege mit Dokument `geprueft`/`festgeschrieben`,
  Zahlungsweg `konto`/`erstattung`, Status offen/teilbezahlt/
  erstattung_offen; Buchungen mit Status ≠ `zugeordnet`, ohne Ausgaben mit
  „kein Beleg nötig“, ab Fensterbeginn des ältesten offenen Belegs (ohne
  offenen Beleg keine); beides im
  Zugriffsbereich von `matching.edit` (Kostenstellen-Scope sieht keine
  Buchung).
- **Seite** `/app/abgleich` (`matching.edit`, CSRF auf allen POST, ohne
  entsperrten Tresor nichts): Knopf „N eindeutige Zuordnungen anlegen“,
  Vorschlagsliste, Formular „Punktebewertung“. Parameter als Setting
  `abgleich_parameter` (JSON, `App\Service\Bank\Abgleichsparameter`,
  ungültig → Startwert).
- **Audit:** je Zuordnung `zuordnung.angelegt` (wie M10-1),
  `abgleich.ausgefuehrt` (Zahl angelegt/übersprungen),
  `einstellung.abgleich` (Feldnamen).

**Pflicht-Tests M10-2:** `PunktebewertungTest` (jedes Signal einzeln,
Auto-Schwelle, Abstandsregel von Beleg- und Buchungsseite, Sammelzahlung,
Ratenzahlung, Skonto, Mindestpunkte, Parameter/Formular),
`AbgleichFlowTest` (Auto mit Score/Trace ohne Werte, Lösen und erneut,
mehrdeutig → Vorschlag, Übernehmen bewertet neu, Sammelzahlung über zwei
Vorschläge, Kandidaten und Zugriffsbereich, Gutschrift, Settings, Audit),
`RoutePermissionMatrixTest` (`matching.edit`).

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

### Stand M9-6: Regeln „kein Beleg nötig“ / Kategorie (issue #64)

- **Regel** (`assignment_rule`, `/app/buchungen/regeln`): Name, Bedingung,
  Wirkung. Bedingung: *Stichwort* (enthalten in Verwendungszweck **oder**
  Buchungstext) und/oder *Gegenseite* (gültige IBAN → genau gleich der
  Gegenseiten-IBAN, sonst enthalten im Gegenseiten-Namen); beides angegeben →
  beides muss passen; Groß-/Kleinschreibung und Leerraum egal; je ≥ 3, ≤ 100
  Zeichen; optional nur Einnahmen/nur Ausgaben. Wirkung: „kein Beleg nötig“
  und/oder Kategorie (mindestens eins); die Kategorie muss zur Richtung
  passen, ohne Richtung nur `beide`. Name und Muster liegen im Tresor; der
  Vergleich läuft in PHP (`App\Service\Bank\Regelabgleich`).
- **Nur importierte Buchungen**, und je Feld nur, was noch Standard ist:
  Beleg-Bedarf mit `doc_source = standard`, Kategorie, solange keine
  gesetzt ist. Ein zugeordneter Beleg (M10) bleibt `zugeordnet`. Je Buchung
  höchstens eine Regel (`rule_id`); treffen mehrere zu, gilt die **älteste**
  (kleinste ID) – die Liste zeigt sie in dieser Reihenfolge.
- **Wann:** bei jedem Import im Schritt (§4). Auf bestehende Buchungen nur
  auf Wunsch: Die Regelseite nennt „passt auf N Buchungen“ und wendet sie per
  Klick an – auf importierte Buchungen ohne Regel, im Zeitraum-Scope dessen,
  der klickt. Eine neue Regel ändert von selbst keine bestehende Buchung.
- **Ändern** nimmt die bisherige Wirkung zurück und wendet die geänderte
  Regel auf dieselben Buchungen wieder an, soweit sie noch passt.
  **Deaktivieren/Löschen** nimmt die Wirkung zurück (ohne Tresor, reines
  SQL): Beleg-Bedarf zurück auf den Standard der Richtung laut Setting
  (`zugeordnet` bleibt), von der Regel gesetzte Kategorie entfällt,
  `rule_id` NULL. Andere Regeln werden dabei nicht nachgezogen. Aktivieren
  wendet nicht von selbst an.
- **An der Buchung** (importiert, `bank.book`): Formular „Einordnung“ mit
  Kategorie (passend zur Richtung, „keine“ möglich) und Beleg („nach
  Richtung“ / „Beleg nötig“ / „kein Beleg nötig“). Nur ein **geänderter**
  Wert gilt als von Hand gesetzt (`manuell`); keine Regel fasst ihn danach
  an. „Nach Richtung“ setzt `doc_source` zurück auf `standard`. `rule_id`
  bleibt, solange die Regel noch eines der beiden Felder verantwortet.
  „Regel daraus machen“ öffnet `/app/buchungen/regeln/neu?buchung=<id>`,
  vorbelegt mit IBAN (sonst Name) der Gegenseite, Richtung, Kategorie und
  „kein Beleg nötig“ – in der URL steht nur die ID. Manuelle Buchungen
  ändert weiterhin nur ihr Formular (§1).
- **Nachvollziehbar:** Die Buchung zeigt je Feld „(durch Regel „Name“)“ mit
  Link, „(von Hand gesetzt)“ oder „(Standard für Ausgaben/Einnahmen)“; die
  Liste markiert „Regel“; die Regelliste nennt je Regel die Zahl der
  Buchungen, verlinkt auf `/app/buchungen?regel=<id>`.
- **Belege** (M7-7, issue #49, 03 §8 „Stand M7-7“): Die Kategorie einer
  Regel wird auch Belegen in der Prüfansicht vorgeschlagen (Stichwort im
  Zweck, Gegenseite gegen Name/Aliasse/IBANs des Partners) – nur als
  Vorschlag vor dem ersten Speichern, nichts wird an Belegen geschrieben.
  Die Regelseite sagt das unter „Kategorie“.
- **Setting** `buchung_einnahme_beleg_noetig` (Schalter auf der Regelseite,
  `bank.book`): gilt für neue Buchungen (Import, manuell „nach Richtung“) und
  als Ziel beim Zurücknehmen; bestehende Buchungen bleiben.
- **Rechte:** Regeln lesen `bank.view` (Vorstand, Kassenprüfer und
  Steuerberater sehen, was Regeln tun), alles Schreibende – Regeln, Setting,
  Einordnung – `bank.book`. CSRF auf allen POST; ohne entsperrten Tresor
  zeigen die Seiten nichts und schreiben nichts.
- **Audit:** `regel.angelegt` (Richtung, kein Beleg, Kategorie-ID),
  `regel.geaendert` (Feldnamen, Zahl zurückgenommen/erneut),
  `regel.angewendet` (Zahl), `regel.aktiviert`, `regel.deaktiviert` und
  `regel.geloescht` (Zahl zurückgenommen) – Entität `assignment_rule`;
  `buchung.eingeordnet` (Feldnamen); `einstellung.beleg_standard`. Nie
  Name, Stichwort oder Gegenseite.

**Pflicht-Tests M9-6:** `RegelabgleichTest` (Stichwort in Zweck/
Buchungstext, Groß-/Kleinschreibung und Leerraum, Name vs. IBAN, beides,
Richtung, ohne Bedingung nichts, älteste gewinnt, Wirkung auf neue Buchung,
Setting), `BuchungsregelFlowTest` (verschlüsselt, Audit ohne Muster;
Validierung mit markiertem Feld; Anwenden schont Manuelles, manuelle
Buchungen und `zugeordnet`, zweimal ändert nichts; Filter `regel`, Marke,
Herkunft an der Buchung; Deaktivieren/Aktivieren/Löschen nehmen zurück;
Zurücknehmen folgt dem Setting; Ändern = zurücknehmen + erneut; Vorbelegung
aus einer Buchung; Einordnung von Hand, unverändert bleibt Regel, „nach
Richtung“, falsche Richtung; manuelle Buchung nicht; Setting; Verwendung der
Kategorie; Leserechte; Zeitraum-Scope; ohne Tresor, CSRF, 404),
`KontoauszugImportFlowTest::testTheImportAppliesTheOldestMatchingRule`,
`RoutePermissionMatrixTest` (Lesen `bank.view`, Schreiben `bank.book`).

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
