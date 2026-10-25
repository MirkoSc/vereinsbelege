# Verfahrensdokumentation Belegverwaltung – Vorlage

> **So benutzt ihr diese Vorlage**
>
> - Die Vorlage beschreibt, wie die Software **Vereinsbelege** Belege
>   verarbeitet und aufbewahrt. Sie ist für Kassenprüfung, Finanzamt und
>   Steuerberatung gedacht.
> - Kopiert die Datei und füllt alle Platzhalter in `[[DOPPELTEN ECKIGEN
>   KLAMMERN]]` aus. Abschnitte, die bei euch nicht zutreffen (z. B. KI oder
>   Nextcloud), streicht ihr oder markiert sie als „nicht genutzt“.
> - Kursiv gesetzte *Hinweise* richten sich an euch beim Ausfüllen. Sie können
>   in der fertigen Fassung entfallen.
> - Die Software und diese Vorlage treffen **keine Rechtsaussage** (E-10).
>   Aufbewahrungsfristen, den Umgang mit Papierbelegen und die Anforderungen
>   an ersetzendes Scannen klärt der Verein mit seiner Steuerberatung.
> - Prüft die Dokumentation mindestens einmal im Jahr und nach jedem größeren
>   Update. Haltet Änderungen in der Änderungshistorie (Abschnitt 12) fest.
> - Tragt **keine Zugangsdaten, Passwörter oder Wiederherstellungsschlüssel**
>   in dieses Dokument ein. Es nennt nur, *wo* diese verwahrt werden.

---

## 1. Allgemeines

| Angabe | Wert |
|---|---|
| Verein | [[NAME DES VEREINS]] |
| Anschrift | [[ANSCHRIFT]] |
| Vereinsregister | [[AMTSGERICHT, VR-NUMMER]] |
| Steuernummer | [[STEUERNUMMER]] |
| Verantwortlich für diese Dokumentation | [[NAME, FUNKTION (z. B. Kassenwart)]] |
| Gültig ab | [[DATUM]] |
| Stand / Version der Dokumentation | [[VERSION, DATUM]] |
| Geschäftsjahr | Kalenderjahr |

**Zweck.** Diese Dokumentation beschreibt, wie der Verein Eingangs- und
Ausgangsbelege (Rechnungen, Quittungen, Kassenbelege, Erstattungsanträge)
erfasst, prüft, festschreibt, archiviert und sichert. Sie soll einem
sachverständigen Dritten (Kassenprüfung, Finanzamt) ermöglichen, die
Verarbeitung in angemessener Zeit nachzuvollziehen.

**Geltungsbereich.** [[z. B. „alle Belege des Gesamtvereins ab dem
01.01.2027“ oder „Belege der Abteilungen X und Y“]]. Belege, die *nicht* über
die Software laufen: [[z. B. „Lohnunterlagen (beim Lohnbüro)“, sonst
„keine“]].

---

## 2. Systemumgebung

| Angabe | Wert |
|---|---|
| Software | Vereinsbelege (Open Source, `github.com/MirkoSc/vereinsbelege`) |
| Installierte Version | [[VERSION, abzulesen unter Admin → Update]] |
| Betrieb | [[HOSTER, Tarif]] – Webhosting, Zugriff über den Browser |
| Adresse der Anwendung | [[https://belege.example.org]] |
| Datenbank | MySQL/MariaDB beim Hoster |
| Speicherort der Belegdateien | [[„Datenbank“ oder „Dateisystem“, abzulesen unter Admin → Speicher]] |
| KI-Anbieter für das Auslesen | [[z. B. „OpenAI“, „Anthropic“, „eigener Server“ oder „nicht genutzt“]] |
| Verarbeitungs-Worker | [[„nicht genutzt“ oder Standort, z. B. „Raspberry Pi beim Kassenwart“]] |
| Nextcloud-Ablage | [[„nicht genutzt“ oder Adresse/Ordner der Vereins-Nextcloud]] |

**Verschlüsselung.** Alle fachlichen Daten – Belegdateien, erkannte Texte,
Beträge, Lieferanten, IBANs, Buchungen, Angaben der Einreichenden – speichert
die Software verschlüsselt („Tresor“). Lesen kann sie nur eine angemeldete
Person, deren Konto für den Tresor freigegeben ist. Datenbank, Backups und
Dateien beim Hoster enthalten diese Daten nur verschlüsselt. Strukturdaten
(Status, Datumsangaben, Kategorie, interne Nummern) liegen unverschlüsselt,
damit die Anwendung danach filtern und sortieren kann.

**Updates.** Die Software wird über die Adminseite aktualisiert. Vor jedem
Update legt sie automatisch eine Datensicherung der Datenbank an (ohne die
Belegdateien bei Speicherort „Dateisystem“, siehe Abschnitt 8). Updates
spielt ein: [[NAME/FUNKTION]].

---

## 3. Rollen und Zuständigkeiten

Rechte werden je Aktion auf dem Server geprüft, nicht nur in der Oberfläche
ausgeblendet. Die Software bringt folgende Rollen mit (anpassbar unter
Admin → Rollen):

| Rolle | Aufgabe in der Software | Person(en) im Verein |
|---|---|---|
| Admin | Benutzer, Tresor-Freigaben, Einstellungen, Backup, Update | [[NAME]] |
| Finanzen | Belege prüfen, festschreiben, Lieferanten, Bank, Abgleich, Export, Archiv-Import | [[NAME(N)]] |
| Vorstand | lesen, Auswertungen, Export, Audit-Log | [[NAME(N)]] |
| Kassenprüfer | nur lesen, Auswertungen, Export, Audit-Log; zeitlich befristetes Konto | [[NAME(N)]] |
| Steuerberater | nur lesen, Auswertungen, Export; zeitlich befristetes Konto | [[KANZLEI]] |
| Vereinsverantwortlicher | Belege intern hochladen und sehen, beschränkt auf eigene Kostenstellen | [[z. B. Abteilungsleitungen]] |

- **Anmeldung:** persönliches Konto je Person, Passwort und zweiter Faktor
  (Authenticator-App oder E-Mail-Code). Für Kassenprüfer und Steuerberater ist
  der zweite Faktor immer Pflicht, ihr Konto läuft nach einer festen Frist ab
  (Standard 60 Tage, verlängerbar) und kann auf einen Zeitraum (z. B. ein
  Geschäftsjahr) beschränkt werden. Weitere Regeln im Verein:
  [[z. B. „2FA für alle Pflicht“]].
- **Kein Vier-Augen-Prinzip in der Software (E-09):** Wer prüfen darf, darf
  auch festschreiben und eine Festschreibung mit Begründung aufheben – auch
  bei selbst geprüften Belegen. Die Kontrolle liegt im Audit-Log
  (Abschnitt 6) und in der Kassenprüfung. *Hinweis: Wenn der Verein
  organisatorisch ein Vier-Augen-Prinzip will, beschreibt es hier, z. B.
  „Belege über [[BETRAG]] € zeichnet zusätzlich [[FUNKTION]] ab“.*
- **Vertretung:** [[Wer übernimmt, wenn die zuständige Person ausfällt?]]

---

## 4. Ablauf der Belegverarbeitung

Überblick:

```
Erfassung ──► Posteingang ──► (Auslesen) ──► Prüfung ──► Festschreibung ──► Archivierung
                 │                                 │
                 └──► Ablehnen / Wiedervorlage     └──► Ablehnen
```

### 4.1 Erfassung

Belege gelangen auf vier Wegen in die Software:

1. **Öffentliche Einreichung** unter `[[ADRESSE]]/einreichen` – ohne Anmeldung,
   z. B. für Mitglieder, die eine Auslage erstattet haben möchten: Foto mit
   der Handykamera, Bild- oder PDF-Datei oder E-Rechnung, dazu Name,
   Erstattungsziel und Freitext. Diese Seite kann nur einreichen, nichts
   lesen. Die Daten werden schon beim Eingang verschlüsselt.
2. **Interne Erfassung** durch angemeldete Personen (Upload oder Kamera in der
   Anwendung).
3. **E-Rechnungen** (ZUGFeRD/Factur-X-PDF oder XRechnung-XML) werden erkannt
   und ihre Angaben ohne KI übernommen.
4. **Archiv-Import** von Altbeständen (ZIP mit früher manuell abgelegten
   Belegen), nur mit dem Recht `archive.import`.

Bei jeder Erfassung gilt:

- **Das Original bleibt erhalten.** Die hochgeladene Datei (Foto, PDF,
  XML) wird unverändert gespeichert. Daneben erzeugt die Software eine
  aufbereitete Fassung (zugeschnitten, entzerrt, ggf. schwarz-weiß) und daraus
  eine PDF-Arbeitskopie. Die Aufbereitung ersetzt das Original nie.
- **Duplikaterkennung:** Die Software meldet einen Verdacht („Duplikat?“), wenn
  dieselbe Datei erneut eingereicht wird oder später Lieferant und
  Rechnungsnummer übereinstimmen. Eine Person entscheidet, ob der Beleg als
  Duplikat verworfen oder bewusst behalten wird; beides steht im Audit-Log.
- Jeder Eingang landet im **Posteingang** mit dem Status „eingegangen“.

*Hinweis: Beschreibt hier, wer Papierbelege einsammelt und erfasst, z. B.
„Quittungen aus der Barkasse fotografiert der Kassenwart wöchentlich“.*
[[EIGENE REGELUNG]]

### 4.2 Sichtung im Posteingang

Eine Person mit dem Recht `document.edit` sichtet jeden Eingang:

- **Annehmen** – der Beleg geht in die Auswertung.
- **Ablehnen** – mit Pflicht-Begründung (z. B. Spam, nicht zuständig). Der
  Beleg wird **nicht gelöscht**, sondern bleibt mit Grund erhalten.
- **Wiedervorlage** – mit Datum, z. B. wenn Angaben fehlen. Der Beleg erscheint
  ab diesem Datum wieder unter „Offen“.

### 4.3 Auslesen (optional, mit KI)

Ist ein KI-Anbieter eingerichtet, liest die Software angenommene Belege aus
(Datum, Beträge, Lieferant, Rechnungsnummer, Vorschlag für Kategorie). Dafür
wird die Belegdatei an den Anbieter übertragen
([[ANBIETER]]; Auftragsverarbeitungsvertrag: [[ja, abgeschlossen am DATUM /
nicht erforderlich, eigener Server]]).

- Ergebnisse der KI sind **nur Vorschläge**. Sie werden erst durch die Prüfung
  (4.4) übernommen.
- Nachvollziehbar gespeichert werden Modell, Version der Anweisung (Prompt)
  und die Rohantwort (verschlüsselt).
- Passt die Antwort nicht zum erwarteten Format, folgt ein Reparaturversuch,
  dann der Status „KI fehlgeschlagen“. Die Software rät nicht. Der Beleg wird
  dann von Hand erfasst.
- Ohne KI erfasst die prüfende Person alle Angaben von Hand.

### 4.4 Prüfung

In der Prüfansicht vergleicht eine Person mit `document.edit` die Angaben mit
dem Original (Bild/PDF steht daneben) und ergänzt oder korrigiert sie:
Datum, Nummer, Beträge, Lieferant bzw. Zahler, Kategorie, Kostenstelle,
Richtung (Ausgabe/Einnahme), Belegart, Zahlungsweg.

- Mit „Geprüft“ hält die Software fest, **wer wann** geprüft hat, und setzt
  den Status „geprüft“.
- Ein Beleg, der sachlich nicht anzuerkennen ist, wird mit Begründung
  abgelehnt und bleibt erhalten.
- Prüfmaßstab des Vereins: [[z. B. „rechnerische Richtigkeit, Bezug zum
  Verein, Genehmigung durch Abteilungsleitung bei Beträgen über X €“]].

### 4.5 Festschreibung

Ein geprüfter Beleg wird **festgeschrieben** (Button „Festschreiben“ in der
Prüfansicht). Die Software speichert, wer wann festgeschrieben hat.

- Danach ist der **ganze Beleg unveränderlich**: alle Angaben der Prüfansicht
  und alle Dateien (Original, Aufbereitung, PDF). Das erzwingt der Server an
  zwei Stellen – die Fachlogik lehnt jede Änderung ab, und die Datenbankzugriffe
  ändern festgeschriebene Belege grundsätzlich nicht.
- Zahlungen können einem festgeschriebenen Beleg weiterhin zugeordnet werden
  (4.6). Die Festschreibung schützt den Inhalt, nicht den Zahlungsverlauf.
- **Korrektur nur über „Festschreibung aufheben“** mit Pflicht-Begründung.
  Der Beleg geht zurück in die Prüfung, wird korrigiert, erneut geprüft und
  festgeschrieben. Die Aufhebung samt Grund steht im Audit-Log. Einen
  stillen Weg, einen festgeschriebenen Beleg zu ändern, gibt es nicht.
- Regel des Vereins für den Zeitpunkt: [[z. B. „Festschreibung spätestens
  zum Monatsende nach der Prüfung“, „vor der Kassenprüfung sind alle Belege
  des Geschäftsjahres festgeschrieben“]].

### 4.6 Zahlungsabgleich

Kontoauszüge (MT940/CSV) werden importiert, Barzahlungen im Kassenbuch
erfasst. Buchungen werden Belegen zugeordnet; daraus ergibt sich, ob ein
Beleg offen oder ausgeglichen ist. Konten und Kassen des Vereins:
[[LISTE, z. B. „Girokonto Sparkasse, Barkasse Geschäftsstelle“]].
Import-Rhythmus: [[z. B. „monatlich“]].

### 4.7 Archivierung (Export)

Der **ZIP-Export** (`/app/export`, Recht `export.zip`) erzeugt ein Archiv
eines Zeitraums:

- Ordnerstruktur nach einem einstellbaren Muster, Standard
  `Lieferant/Jahr/Monat/Lieferant Datum.pdf`. Eingestelltes Muster beim
  Verein: [[MUSTER, abzulesen unter Admin → Export]].
- `index.csv` als Verzeichnis aller Belege (Datum, Richtung, Lieferant,
  Rechnungsnummer, Brutto, Kategorie, Kostenstelle, Status, bezahlt am, Konto,
  Referenz, Dateipfad).
- Auswahl: „geprüft + festgeschrieben“ (Standard), „nur festgeschrieben“
  oder „alle außer abgelehnte“ (nicht geprüfte dann unter `_Wiedervorlage/`).
  Abgelehnte Belege werden nie exportiert.
- Optional zusätzlich alle **Originale** unter `_Originale/`.
- Fehlt eine Datei im Speicher, steht „(Datei fehlt)“ in `index.csv`, und die
  Vorschau warnt vorher.
- Jeder Export ist ein **Export-Lauf**. Nach erfolgreichem Speichern bestätigt
  man ihn („als exportiert markieren“); so findet „nur noch nicht
  exportierte“ beim nächsten Mal nur neue Belege. Erstellen, Herunterladen,
  Bestätigen und Zurücksetzen stehen im Audit-Log.
- Optional legt die Software einen Export-Lauf direkt in der
  **Vereins-Nextcloud** ab – Datei für Datei, ohne etwas zu überschreiben.

Archivierungsregel des Vereins:

- Rhythmus: [[z. B. „nach Abschluss des Geschäftsjahres, Option ‚nur
  festgeschrieben‘ und ‚Originale zusätzlich‘“]]
- Ablageort(e) des Export-ZIPs: [[z. B. „Vereins-Nextcloud, Ordner
  Buchhaltung/Archiv; zweite Kopie auf USB-Stick im Vereinstresor“]]
- Verantwortlich: [[NAME/FUNKTION]]

*Hinweis: Das Export-ZIP ist **nicht** verschlüsselt – es enthält die Belege
im Klartext. Bewahrt es entsprechend geschützt auf.*

---

## 5. Statusübersicht eines Belegs

| Status | Bedeutung |
|---|---|
| eingegangen | neu im Posteingang, noch nicht gesichtet |
| bereit zur Auswertung | angenommen, wartet auf Auslesen oder manuelle Erfassung |
| ausgewertet | KI-Vorschlag liegt vor |
| KI fehlgeschlagen | Auslesen gescheitert, manuelle Erfassung nötig |
| in Prüfung | wird erfasst bzw. korrigiert |
| geprüft | Angaben bestätigt (mit Person und Zeitpunkt) |
| festgeschrieben | unveränderlich; Änderung nur nach begründeter Aufhebung |
| Wiedervorlage | zurückgestellt bis zu einem Datum |
| abgelehnt | mit Grund aussortiert, bleibt gespeichert (Endzustand) |

Erlaubte Wechsel prüft der Server. Ein Wechsel gilt nur, wenn der Beleg noch
im erwarteten Status ist – zwei gleichzeitige Entscheidungen können nicht
beide gewinnen.

---

## 6. Nachvollziehbarkeit: Audit-Log

Die Software führt ein **Audit-Log**, in das nur angefügt wird. Es gibt keine
Funktion, Einträge zu ändern oder zu löschen. Protokolliert werden u. a.:

- Anmeldungen (auch Fehlversuche), Abmeldungen, Änderungen an Passwort und
  zweitem Faktor;
- Benutzer, Rollen, Tresor-Freigaben;
- Einreichung, Annehmen, Ablehnen, Wiedervorlage, Bearbeiten, Prüfen,
  Festschreiben und Aufheben der Festschreibung (mit Grund), Duplikat-
  Entscheidungen;
- Lieferanten, Kontoauszug-Importe, Buchungen, Kassenstürze, Zuordnungen von Zahlungen;
- Exporte, Archiv-Importe, Backups (Erstellen, Herunterladen), Updates und
  Einstellungsänderungen.

Jeder Eintrag nennt Zeitpunkt, handelnde Person, Aktion und betroffenen
Datensatz. Einzelheiten (z. B. Begründungen) sind verschlüsselt und nur in
einer angemeldeten Sitzung lesbar. Passwörter, Codes, Mail-Adressen und
Beträge stehen nie im Audit-Log.

**Hash-Kette.** Jeder Eintrag enthält eine Prüfsumme (SHA-256) über seinen
Inhalt und die Prüfsumme des vorherigen Eintrags. Die Funktion **„Integrität
prüfen“** unter `/app/audit` (Recht `audit.view`) rechnet die ganze Kette nach
und erkennt geänderte, gelöschte, umsortierte oder eingeschobene Einträge.

**Kontrollwert.** Allein erkennt die Kette nicht, wenn Einträge am Ende
entfernt oder die Kette ab einem Punkt vollständig neu berechnet wurde. Darum
zeigt die Prüfung am Ende den Prüfsummen-Wert des letzten Eintrags als
Kontrollwert. **Der Verein notiert ihn außerhalb der Software**, z. B. im
Kassenprüfungsbericht. Bei der nächsten Prüfung muss der notierte Eintrag
mit unverändertem Wert in der Kette stehen.

| Datum der Prüfung | Geprüft von | Ergebnis | Kontrollwert (letzter Eintrag) |
|---|---|---|---|
| [[DATUM]] | [[NAME]] | [[„Kette vollständig“]] | [[WERT]] |

---

## 7. Aufbewahrung

- **Originale:** Zu jedem Beleg bleibt die unverändert hochgeladene Datei
  gespeichert, daneben die aufbereitete Fassung und die PDF-Arbeitskopie.
- **Keine Löschung von Belegen:** Die Software bietet keine Funktion, einen
  Beleg zu löschen. Auch abgelehnte und als Duplikat verworfene Belege bleiben
  mit Begründung erhalten. Automatisch gelöscht werden nur Zwischenstände,
  die nie zu einem Beleg wurden (abgebrochene Uploads, alte Hilfsdateien).
- **Lesbarkeit über die Zeit:** Die Belege sind innerhalb der Software
  jederzeit abrufbar, solange der Tresor geöffnet werden kann (Abschnitt 8).
  Für eine Prüfung unabhängig von der Software dient der ZIP-Export (4.7):
  PDFs/Bilder in Ordnern plus `index.csv`, lesbar mit Standardprogrammen.
- **Aufbewahrungsfristen:** [[mit der Steuerberatung festgelegt, z. B. „Belege
  8 bzw. 10 Jahre ab Ende des Kalenderjahres“]]. Die Software setzt keine
  Fristen durch und löscht nach Fristablauf nichts von selbst.
- **Papierbelege:** [[z. B. „Papieroriginale werden zusätzlich nach
  Geschäftsjahr abgelegt und bis zum Fristende aufbewahrt“ oder „werden nach
  Festschreibung und Abstimmung mit der Steuerberatung vernichtet“]].
  *Hinweis: Ob Papierbelege nach dem Scannen vernichtet werden dürfen
  (ersetzendes Scannen), entscheidet der Verein mit seiner Steuerberatung.
  Die Software liefert dafür Original-Aufbewahrung, Festschreibung und
  Audit-Log, keine Rechtsaussage.*
- **Elektronisch empfangene Belege** (PDF, E-Rechnung) bleiben in der
  empfangenen Form als Original gespeichert.

---

## 8. Datensicherung und Wiederherstellung

### 8.1 Was gesichert werden muss

| Was | Wo | Warum |
|---|---|---|
| Backup-ZIP | Admin → Backups (`/admin/backups`), Download | Datenbank und – je nach Wahl – Belegdateien |
| `config.php` (Server-Schlüssel) | beim Hoster unter `shared/config.php` | ohne sie sind Anmelde-Index, Mail-Adressen und Zugangsdaten zu KI/Mail nicht lesbar |
| Wiederherstellungsschlüssel | Papier | öffnet den Tresor, wenn kein Konto mehr zugreifen kann |

**Backup-ZIP.** Enthält die Datenbank und die Belegdateien. Fachliche Daten
liegen darin nur verschlüsselt; das ZIP darf deshalb außerhalb des Hosters
aufbewahrt werden. Die Software hält die letzten 10 Backups beim Hoster vor
und entfernt ältere. Diese Kopien liegen beim selben Anbieter – der Verein
lädt Backups deshalb herunter und lagert sie extern. Backups werden **nicht
automatisch** nach Zeitplan erstellt; der Admin startet sie auf der
Backup-Seite. Zusätzlich sichert jedes Update automatisch die Datenbank (ohne
`config.php` und ohne Belegdateien beim Speicherort „Dateisystem“) – das
ersetzt kein vollständiges Backup.

**`config.php`.** Wird standardmäßig *nicht* ins Backup aufgenommen. Wer sie
mitsichert, macht Betriebsdaten im Backup lesbar; der Download eines solchen
Backups verlangt das Passwort. Empfehlung: `config.php` getrennt vom Backup
und zusammen mit dem Wiederherstellungsschlüssel verwahren.

**Wiederherstellungsschlüssel.** Wird beim Einrichten einmal angezeigt und auf
Papier verwahrt. Er öffnet alle fachlichen Daten – wer ihn hat, kann alles
lesen. Nach einem Schlüsselwechsel des Tresors bleibt der alte Schlüssel
aufbewahrt, solange Backups von davor existieren: diese öffnet nur der alte
Schlüssel.

Regelung des Vereins:

- Backup-Rhythmus: [[z. B. „monatlich und vor jeder Kassenprüfung“]]
- Externe Ablage der Backups: [[ORT, z. B. „verschlüsselter Ordner in der
  Vereins-Nextcloud + USB-Stick bei der zweiten Vorsitzenden“]]
- Wie lange Backups aufbewahrt werden: [[DAUER]]
- Verwahrort Wiederherstellungsschlüssel (Original): [[z. B.
  „Bankschließfach“]]
- Verwahrort Kopie: [[z. B. „versiegelter Umschlag bei Vorstandsmitglied X“]]
- Verwahrort `config.php`: [[ORT]]
- Verantwortlich: [[NAME/FUNKTION]]

### 8.2 Wiederherstellung

Wiederhergestellt wird über den Installer der Software auf einer leeren
Installation:

1. Software neu installieren (Release-ZIP bzw. `setup.php`).
2. Im Installer „Backup einspielen“ wählen und das Backup-ZIP hochladen. Das
   Einspielen läuft in vielen kurzen Schritten; ein Abbruch lässt sich
   fortsetzen oder neu beginnen.
3. **Mit `config.php`** (im Backup oder getrennt hochgeladen): alle Konten
   melden sich wie bisher an.
4. **Ohne `config.php`:** ein Admin verknüpft sein Konto mit
   Wiederherstellungsschlüssel, E-Mail-Adresse und bisherigem Passwort neu.
   Mail-Adressen der übrigen Konten trägt der Admin danach neu ein;
   Zugangsdaten zu KI-Anbieter und Mail-Server werden neu eingegeben.
5. Anschließend: Anmeldung prüfen, Stichprobe von Belegen öffnen,
   „Integrität prüfen“ im Audit-Log ausführen und den Kontrollwert mit dem
   zuletzt notierten Wert vergleichen.

**Probe-Wiederherstellung.** [[z. B. „einmal jährlich auf einer
Testinstallation durch NAME; Ergebnis im Protokoll unten“]]

| Datum | Durchgeführt von | Backup vom | Ergebnis |
|---|---|---|---|
| [[DATUM]] | [[NAME]] | [[DATUM]] | [[ERGEBNIS]] |

---

## 9. Zugriffsschutz und Datenschutz

- Zugriff nur über persönliche Konten mit Passwort und zweitem Faktor;
  Rechte je Rolle (Abschnitt 3), auf dem Server je Aktion geprüft.
- Fachliche Daten verschlüsselt gespeichert (Abschnitt 2). Ein neues Konto
  sieht sie erst nach ausdrücklicher Tresor-Freigabe durch einen Admin.
- Protokolldateien und Mails enthalten keine Belegdaten.
- Externe Konten (Kassenprüfung, Steuerberatung) laufen automatisch ab.
- Hinweise zur Datenverarbeitung für Einreichende: [[Verweis auf die
  Datenschutzerklärung des Vereins]].
- Auftragsverarbeitung: Hoster [[ja/nein, Datum]], KI-Anbieter [[ja/nein,
  Datum]], Nextcloud-Anbieter [[ja/nein/eigener Server]].

Überprüfung der Benutzerkonten und Freigaben: [[z. B. „jährlich zur
Mitgliederversammlung; ausgeschiedene Funktionsträger werden sofort
gesperrt“]].

---

## 10. Regelmäßige Kontrollen

| Kontrolle | Rhythmus | Verantwortlich |
|---|---|---|
| Posteingang leeren (keine Belege älter als [[X]] Tage offen) | [[wöchentlich]] | [[NAME]] |
| Geprüfte Belege festschreiben | [[monatlich]] | [[NAME]] |
| Kontoauszüge importieren und abgleichen | [[monatlich]] | [[NAME]] |
| Backup erstellen und extern ablegen | [[monatlich]] | [[NAME]] |
| Audit-Log „Integrität prüfen“, Kontrollwert notieren | [[zur Kassenprüfung]] | [[Kassenprüfer]] |
| Archiv-Export des Geschäftsjahres | [[jährlich]] | [[NAME]] |
| Benutzer und Freigaben durchsehen | [[jährlich]] | [[Admin]] |
| Probe-Wiederherstellung | [[jährlich]] | [[NAME]] |
| Diese Dokumentation prüfen | [[jährlich]] | [[NAME]] |

---

## 11. Grenzen der Software

- Die Software ersetzt keine Buchhaltung und keine steuerliche Beratung. Sie
  verwaltet Belege, ihren Zahlungsabgleich und Auswertungen.
- Die Hash-Kette des Audit-Logs erkennt Manipulationen innerhalb der Kette.
  Wer die Datenbank vollständig ersetzt oder die Kette neu berechnet, fällt
  nur über den extern notierten Kontrollwert auf (Abschnitt 6).
- Prüfung und Festschreibung durch dieselbe Person sind möglich (kein
  Vier-Augen-Prinzip in der Software, Abschnitt 3).
- Die Software löscht Belege nicht, auch nicht nach Ablauf der
  Aufbewahrungsfristen.
- Ohne Wiederherstellungsschlüssel und ohne ein Konto mit Tresor-Freigabe
  sind die Belegdaten nicht mehr lesbar – auch nicht für den Hoster oder die
  Entwickler der Software.

---

## 12. Änderungshistorie und Freigabe

| Version | Datum | Änderung | Bearbeitet von |
|---|---|---|---|
| 1.0 | [[DATUM]] | Ersterstellung aus der Vorlage | [[NAME]] |

Freigegeben durch den Vorstand:

[[ORT]], den [[DATUM]]

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
[[NAME, FUNKTION]]
