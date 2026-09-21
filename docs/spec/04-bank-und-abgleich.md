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
