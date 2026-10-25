# Betrieb – Handbuch für den Verein

Alles, was der Verein für den laufenden Betrieb der Belegverwaltung wissen
muss: Installation, Cron, Mail, Backups, Wiederherstellung, Rollen, Updates
und Prüfungen. Die Anleitung beschreibt **was zu tun ist**; das **Warum**
und die technischen Einzelheiten stehen in den Spezifikationen, auf die
jeweils verwiesen wird (`docs/spec/…`). Bei Widerspruch gilt die Spec.

Weitere Dokumente für den Verein:

- [`docs/verfahrensdokumentation.md`](verfahrensdokumentation.md) – Vorlage
  für die Verfahrensdokumentation (Ablauf, Aufbewahrung, Datensicherung als
  Regelung des Vereins, Kontrollen).
- [`docs/worker-einrichtung.md`](worker-einrichtung.md) – optionaler Worker
  auf einem Raspberry Pi.

Inhalt:

1. [Überblick: was wo liegt](#1-überblick-was-wo-liegt)
2. [Installation](#2-installation)
3. [Cron einrichten](#3-cron-einrichten)
4. [Mail: SMTP, SPF und DKIM](#4-mail-smtp-spf-und-dkim)
5. [Wiederherstellungsschlüssel](#5-wiederherstellungsschlüssel)
6. [Backups](#6-backups)
7. [Wiederherstellung (Restore)](#7-wiederherstellung-restore)
8. [Rollen und Rechte einrichten](#8-rollen-und-rechte-einrichten)
9. [Updates und Rollback](#9-updates-und-rollback)
10. [Systemcheck und Code-Integritätscheck](#10-systemcheck-und-code-integritätscheck)
11. [Zusatzfunktionen: Worker, Mail-Eingang, Archiv-OCR](#11-zusatzfunktionen-worker-mail-eingang-archiv-ocr)
12. [Regelmäßige Aufgaben (Checkliste)](#12-regelmäßige-aufgaben-checkliste)
13. [Für Maintainer](#13-für-maintainer)

---

## 1. Überblick: was wo liegt

Die Anwendung läuft auf einfachem Webspace (getestet bei all-inkl): kein
SSH, keine Kommandozeile. Alles wird per Browser und FTP erledigt.

```
<Domain-Ordner beim Hoster>/
├── web/        ← hierhin zeigt die (Sub-)Domain: nur index.php, .htaccess, .user.ini
├── current/    ← aktives Release (wird beim Update ausgetauscht)
├── releases/   ← vorheriges Release für den Rollback (_prev)
└── shared/     ← bleibt bei Updates erhalten
    ├── config.php      Datenbank-Zugang, Cron-Token, Server-Schlüssel
    └── var/
        ├── blobs/      verschlüsselte Belegdateien (Speicherort „Dateisystem“)
        ├── backups/    die letzten 10 Backups
        ├── log/app.log Fehlerprotokoll (ohne Belegdaten)
        └── tmp/        Zwischendateien, werden aufgeräumt
```

Wichtig:

- Die Domain muss im Kundenmenü des Hosters auf den Ordner **`web/`**
  zeigen. Nur so sind `shared/` (mit `config.php`), `releases/` und
  `current/` aus dem Internet nicht erreichbar. Zusätzlich sperrt je eine
  `.htaccess` diese Ordner, falls die Domain doch einmal eine Ebene höher
  zeigt. Ob beides greift, zeigt der [Systemcheck](#10-systemcheck-und-code-integritätscheck).
- **Zwei Arten von Schlüsseln** schützen die Daten
  ([01 §2](spec/01-sicherheit.md#2-schlüsselhierarchie-libsodium-ext-sodium)):
  - der **Server-Schlüssel** in `shared/config.php` – für Betriebsdaten
    (Mail-Adressen der Benutzer, Anmelde-Index, SMTP-Passwort,
    KI-Zugangsdaten);
  - der **Tresor** – für alle fachlichen Daten (Belege, Beträge, IBANs,
    Buchungen). Lesbar nur nach Anmeldung; als letzte Rückfallebene gibt es
    den [Wiederherstellungsschlüssel](#5-wiederherstellungsschlüssel) auf
    Papier.

  Daraus folgt: **`config.php` und Wiederherstellungsschlüssel sind die
  beiden Dinge, die der Verein getrennt vom Hoster aufbewahren muss.**

---

## 2. Installation

Technischer Ablauf: [06 §1](spec/06-betrieb.md#1-installer--updater--release).

**Vorab (optional, empfohlen bei neuem Hoster):** Mit
`tools/hosting-check.php` lässt sich prüfen, ob der Webspace alle
Voraussetzungen erfüllt (PHP 8.5, sodium, Datenbank, Cron-Intervall …).
Das Skript ist nicht Teil des Releases; Aufruf und Messungen beschreibt
[06 §5](spec/06-betrieb.md#5-hosting-check-meilenstein-m0), die Ergebnisse
für all-inkl stehen in [`docs/hosting-befunde.md`](hosting-befunde.md).

**Schritte:**

1. Im Kundenmenü des Hosters eine **MySQL/MariaDB-Datenbank** anlegen und
   Zugangsdaten notieren.
2. Im Domain-Ordner einen Unterordner **`web`** anlegen und die (Sub-)Domain
   darauf zeigen lassen. **HTTPS** (z. B. Let's Encrypt) für die Domain
   einschalten.
3. Von der Release-Seite des Projekts auf GitHub die Datei **`setup.php`**
   herunterladen und per FTP nach `web/` hochladen.
4. `https://<ihre-domain>/setup.php` im Browser öffnen. Die Seite prüft die
   Umgebung, zeigt, wo die Verzeichnisse angelegt werden, und fragt den
   **Release-Kanal** ab (`stable` für den Verein, `beta` nur für eine
   Testinstanz). Ist der Ordner über `web/` nicht leer, warnt sie – meist
   liegt `setup.php` dann direkt im Domain-Ordner statt in `web/`.
5. „Installation starten“: `setup.php` lädt das Release-ZIP von GitHub,
   prüft die Prüfsumme, entpackt es, legt `current/`, `releases/`,
   `shared/` und die drei Dateien in `web/` an und leitet zu `/install`
   weiter.
6. Im Installer **„Frische Installation“** wählen, Datenbank-Zugang und den
   **ersten Admin** (E-Mail, Name, Passwort mit mindestens 12 Zeichen)
   eintragen.
7. Der **Wiederherstellungsschlüssel** wird **genau einmal** angezeigt:
   ausdrucken („Zum Aufbewahren drucken“) oder abschreiben, dann die
   **letzte Gruppe** eintippen. Erst danach schreibt der Installer
   `shared/config.php` und ist abgeschlossen. Siehe
   [Abschnitt 5](#5-wiederherstellungsschlüssel).
8. Der Installer löscht `setup.php`. Gelingt das nicht (Dateirechte beim
   FTP), sagt die Abschlussseite Bescheid – dann `web/setup.php` per FTP
   löschen.
9. Anmelden. Beim ersten Login wird der zweite Faktor (Authenticator-App
   oder E-Mail-Code) eingerichtet.
10. Unter „Admin“ nacheinander einrichten:
    - **Systemcheck** (`/admin/systemcheck`) – alles grün?
    - **Mail** (`/admin/mail`), siehe [Abschnitt 4](#4-mail-smtp-spf-und-dkim)
    - **Cron** beim Hoster, siehe [Abschnitt 3](#3-cron-einrichten)
    - **`config.php` sichern**: per FTP `shared/config.php` herunterladen
      und zum Wiederherstellungsschlüssel legen ([Abschnitt 6](#6-backups))
    - KI-Anbieter, Kategorien, Kostenstellen, Rechtstexte (Impressum,
      Datenschutz), öffentliche Einreichung
    - Benutzer und Rollen, siehe [Abschnitt 8](#8-rollen-und-rechte-einrichten)

Wird der Browser vor der Bestätigung des Wiederherstellungsschlüssels
geschlossen, bietet `/install` „Neu beginnen“ an – der Schlüssel wird aus
Sicherheitsgründen kein zweites Mal angezeigt.

---

## 3. Cron einrichten

Technischer Ablauf: [06 §4](spec/06-betrieb.md#4-jobs-und-session-worker)
(„Cron“, „Stand M1-5“).

Der Cron erledigt nur Hintergrundarbeit, die **keine** Belegdaten braucht:
Mails aus der Warteschlange versenden (mit Wiederholungen), Aufräumen
(abgebrochene Uploads, abgelaufene Codes und Tokens, alte Jobs, Suchindexe,
nicht bestätigte Kontoauszug-Vorschauen) und – falls der Worker genutzt
wird – den Rückfall auf den Browser, wenn der Worker zu lange schweigt.
Er entschlüsselt nie etwas. KI-Auslesen, Abgleich und Export laufen
dagegen im Browser eines angemeldeten Benutzers.

**Einrichten:**

1. Per FTP `shared/config.php` öffnen und den Wert von **`cron_token`**
   ablesen (48 Zeichen). Die Datei nicht verändern.
2. Im Kundenmenü des Hosters einen Cronjob als **URL-Aufruf** anlegen:

   ```
   https://<ihre-domain>/cron?token=<cron_token>
   ```

3. Intervall: **jede Minute** (all-inkl erlaubt das). Seltener geht auch,
   dann gehen Mails entsprechend später raus. Überlappende Läufe sind
   abgesichert; das Aufräumen läuft ohnehin nur einmal pro Stunde.

Die Antwort ist ein kurzes JSON (`"status": "ok"`). Ein falsches Token gibt
403, während eines Updates (Wartungsmodus) gibt es 503 – der nächste Lauf
holt nach.

> **Achtung nach einer Wiederherstellung:** Der Installer erzeugt beim
> Restore ein **neues** `cron_token`. Danach den Cronjob mit dem neuen Wert
> aus `shared/config.php` aktualisieren, sonst gehen keine Mails mehr raus.

Das Token ist ein Geheimnis: nicht weitergeben, nicht in Tickets
kopieren. Wer es kennt, kann nur den Cron auslösen, aber keine Daten lesen.

---

## 4. Mail: SMTP, SPF und DKIM

Technischer Ablauf: [06 §3](spec/06-betrieb.md#3-mail).

Die Anwendung verschickt Mails für Einladungen, Anmelde-Codes,
Passwort-Reset, Sicherheitshinweise und Hinweise an Admins. **Mails
enthalten nie Belegdaten** (keine Beträge, Lieferanten, IBANs) – nur
Hinweise mit Link.

**Einrichten unter `/admin/mail`:**

| Feld | Empfehlung |
|---|---|
| Versandweg | **SMTP** (PHP `mail()` nur als Notlösung) |
| SMTP-Server, Port | vom Hoster; Port **465** (SSL/TLS) oder **587** (STARTTLS) |
| Benutzername, Passwort | eines eigenen Postfachs, z. B. `belege@verein.de`; das Passwort wird mit dem Server-Schlüssel verschlüsselt gespeichert |
| Absenderadresse | dieselbe Adresse wie das SMTP-Postfach (gleiche Domain) |
| Antwort-an, Vereinsname | optional |
| Adresse dieser Installation | `https://<ihre-domain>` – wird für Links in Mails gebraucht. Ohne diese Angabe gehen Mails mit Link nur raus, wenn die aufgerufene Domain zur Absenderdomain gehört |

Danach eine **Testmail** schicken. Die Warteschlange unten auf der Seite
zeigt, was noch aussteht oder gescheitert ist (Empfänger maskiert). Eine
Mail, die sofort nicht rausgeht, versucht der [Cron](#3-cron-einrichten)
noch bis zu viermal (Abstände 1 min, 5 min, 15 min, 1 h); danach steht sie
auf „fehler“.

**SPF und DKIM für die Absenderdomain** – sonst landen Einladungen und
Anmelde-Codes beim Empfänger im Spam:

- **SPF:** Im DNS der Absenderdomain muss ein TXT-Eintrag stehen, der den
  Mailserver des Hosters erlaubt (`v=spf1 … ~all`). Den genauen Eintrag
  nennt die Hilfe des Hosters; bei Domains, die der Hoster selbst
  verwaltet, ist er meist schon gesetzt. Pro Domain darf es nur **einen**
  SPF-Eintrag geben – kommen Mails auch über andere Dienste (z. B. ein
  Newsletter-Anbieter), werden deren `include:` in denselben Eintrag
  geschrieben.
- **DKIM:** Im Kundenmenü des Hosters DKIM für die Absenderdomain
  einschalten. Liegt das DNS woanders, den angezeigten öffentlichen
  Schlüssel dort als TXT-Eintrag eintragen.
- **DMARC** (optional, empfohlen): TXT-Eintrag `_dmarc.<domain>` mit
  zunächst `v=DMARC1; p=none; rua=mailto:<adresse>`, später verschärfen.
- **Prüfen:** Eine Testmail an ein Postfach bei einem großen Anbieter
  schicken und in den Kopfzeilen (`Authentication-Results`) nachsehen, ob
  `spf=pass` und `dkim=pass` stehen.

---

## 5. Wiederherstellungsschlüssel

Hintergrund: [01 §2](spec/01-sicherheit.md#2-schlüsselhierarchie-libsodium-ext-sodium)
(„Benutzer-Lebenszyklus und Schlüssel“, „Schlüsselrotation“).

Der Wiederherstellungsschlüssel besteht aus **7 Gruppen zu 8 Zeichen** und
wird bei der Installation **einmal** angezeigt. Er enthält den
Tresor-Schlüssel selbst: **wer ihn hat, kann alle Belegdaten lesen.**

**Aufbewahrung:**

- Ausgedruckt im **Vereinstresor** bzw. Bankschließfach, eine zweite Kopie
  versiegelt bei einem zweiten Vorstandsmitglied.
- Zusammen mit (aber getrennt vom Hoster) einer Kopie von
  `shared/config.php` ([Abschnitt 6](#6-backups)).
- Nie als Foto im Handy, nie per Mail, nie in einer Cloud-Notiz.
- Wo er liegt und wer Zugriff hat, regelt der Verein in der
  [Verfahrensdokumentation §8.1](verfahrensdokumentation.md#81-was-gesichert-werden-muss).

**Wann man ihn braucht:**

| Situation | Was tun |
|---|---|
| Der **letzte Admin** hat sein Passwort vergessen | Passwort per „Passwort vergessen“ zurücksetzen, anmelden, dann unter `/admin/wiederherstellen` den Schlüssel eingeben. Das Konto bekommt die Tresor-Freigabe zurück. |
| **Restore ohne `config.php`** | Im Installer mit Schlüssel + bisherigem Admin-Passwort das Konto neu verknüpfen ([Abschnitt 7](#7-wiederherstellung-restore)). |
| Ein normaler Benutzer hat sein Passwort vergessen | **Nicht** nötig: nach dem Reset gibt ein Admin unter `/admin/tresor` die Freigabe neu. |

Jede Verwendung unter `/admin/wiederherstellen` steht im Audit-Log, und
alle Admins mit Freigabe-Recht bekommen eine Sicherheitsmail.

**Schlüsselwechsel (Rotation):** Gerät der Schlüssel in falsche Hände oder
verlässt jemand den Vorstand, der Zugriff auf den Tresor hatte, lässt sich
unter `/admin/tresor/rotation` ein neuer Tresor-Schlüssel erzeugen (mit
neuem Wiederherstellungsschlüssel). Während der Umstellung sind Anwender-
und Adminseiten gesperrt. **Den alten Wiederherstellungsschlüssel trotzdem
aufheben**, solange Backups von vor dem Wechsel existieren – nur er öffnet
diese.

---

## 6. Backups

Technischer Ablauf: [06 §2](spec/06-betrieb.md#2-backup--restore); die
Regelung des Vereins (Rhythmus, Ablageort, Verantwortliche) gehört in die
[Verfahrensdokumentation §8](verfahrensdokumentation.md#8-datensicherung-und-wiederherstellung).

**Was ein Backup enthält:** die Datenbank, die Belegdateien (beim
Speicherort „Dateisystem“; beim Speicherort „Datenbank“ stecken sie ohnehin
im Dump) und eine `manifest.json` mit Versionen und Zahlen. **Alle
fachlichen Daten sind darin verschlüsselt** – das ZIP darf deshalb
heruntergeladen und außerhalb des Hosters aufbewahrt werden.
`config.php` ist standardmäßig **nicht** enthalten.

**Backup erstellen** (`/admin/backups`, Recht `admin.system`):

1. „Backup erstellen“ – die Seite arbeitet in vielen kurzen Schritten; den
   Tab offen lassen, bis „fertig“ erscheint. Bleibt ein Vorgang hängen
   (Tab geschlossen), lässt er sich abbrechen und neu starten.
2. Die Checkbox „`config.php` mitsichern“ **aus** lassen (Standard).
   Mit Haken sind Mail-Adressen und Zugangsdaten im Backup lesbar; der
   Download eines solchen Backups verlangt dann das eigene Passwort.
3. Das fertige Backup herunterladen und **extern** ablegen.

**Wichtig:**

- **Backups entstehen nur auf Knopfdruck.** Es gibt keinen Zeitplan. Der
  Verein legt einen Rhythmus fest – Empfehlung: **monatlich, vor jeder
  Kassenprüfung und vor größeren Änderungen** – und lädt jedes Backup
  herunter.
- Beim Hoster bleiben die **letzten 10 Backups**; ältere löscht die
  Anwendung automatisch. Diese Kopien liegen beim selben Anbieter wie die
  Installation und ersetzen **keine** externe Sicherung.
- Mit Belegdateien ist jedes Backup so groß wie der ganze Belegbestand –
  zehn Backups brauchen zehnmal so viel Platz. Speicherplatz beim Hoster
  im Blick behalten.
- Jedes **Update** legt automatisch ein Backup an – aber nur der Datenbank,
  ohne `config.php` und ohne die Belegdateien im Dateisystem. Das schützt
  vor einem missglückten Update, ersetzt aber kein vollständiges Backup.

**`shared/config.php` getrennt sichern:**

- Nach der Installation und nach jedem Restore per FTP herunterladen.
- Zum Wiederherstellungsschlüssel legen (verschlüsselter USB-Stick oder
  Ausdruck im Vereinstresor) – **nicht** neben die Backups in dieselbe
  Cloud.
- Ohne sie ist ein Restore zwar möglich, aber mit Aufwand
  ([Abschnitt 7](#7-wiederherstellung-restore)).

Belege werden **nie automatisch gelöscht** – auch nicht nach Ablauf der
Aufbewahrungsfrist; die Software kennt keine Löschfunktion für Belege.
Siehe [Verfahrensdokumentation §7](verfahrensdokumentation.md#7-aufbewahrung).

---

## 7. Wiederherstellung (Restore)

Technischer Ablauf: [06 §2](spec/06-betrieb.md#2-backup--restore)
(„Einspielen“, „Restore ohne Server-Schlüssel“).

Ein Restore läuft immer über den **Installer** einer leeren Installation:

1. Wie bei der [Installation](#2-installation) Schritte 1–5: neue (leere)
   Datenbank, `setup.php` nach `web/`, aufrufen.
2. Im Installer **„Backup einspielen“** wählen, Datenbank-Zugang eintragen
   und das Backup-ZIP hochladen. Das Einspielen läuft in vielen kurzen
   Schritten mit Fortschrittsanzeige; den Tab offen lassen.
   Das ZIP wird in einem Stück hochgeladen – es darf nicht größer sein als
   `upload_max_filesize`/`post_max_size` des Hosters (bei all-inkl
   384 MiB).
3. Danach je nach Backup:
   - **Backup enthält `config.php`:** fertig. Alle Konten melden sich wie
     bisher an.
   - **Backup ohne `config.php`** (der Normalfall): Der Installer zeigt den
     **Zugangsschritt** mit zwei Wegen:
     1. **Alten Server-Schlüssel nachreichen** – die getrennt gesicherte
        `config.php` hochladen (oder den Wert `server_key` eintragen).
        Alles ist wie vorher; nichts muss nachgearbeitet werden. **Das ist
        der bevorzugte Weg.**
     2. **Admin-Konto neu verknüpfen** – mit Wiederherstellungsschlüssel,
        E-Mail-Adresse, Name und dem **bisherigen Passwort** eines
        Admin-Kontos. Danach gilt ein neuer Server-Schlüssel. Folgen, die
        die Abschlussseite auch nennt:
        - Die Authenticator-App dieses Kontos wird beim nächsten Login neu
          eingerichtet.
        - Die Mail-Adressen der **übrigen** Konten sind nicht mehr lesbar;
          ein Admin trägt sie unter `/admin/benutzer/<id>` neu ein, bis
          dahin können sich diese Konten nicht anmelden.
        - SMTP-Passwort und API-Schlüssel der KI-Anbieter neu eintragen;
          nicht versendete Mails sind verloren.
4. Nach dem Restore:
   - [Cron-URL](#3-cron-einrichten) mit dem **neuen `cron_token`**
     aktualisieren.
   - Neue `shared/config.php` per FTP herunterladen und sicher ablegen.
   - **Worker-Kopplungen prüfen** (`/admin/worker`): Ein älteres Backup
     bringt auch Worker zurück, die inzwischen entkoppelt waren – solche
     Kopplungen dort entkoppeln. Siehe
     [`docs/worker-einrichtung.md` §9](worker-einrichtung.md#9-anhalten-neu-starten-entkoppeln).
   - Stichproben (Anmeldung, einige Belege öffnen) und im Audit-Log
     „Integrität prüfen“ – wie in der
     [Verfahrensdokumentation §8.2](verfahrensdokumentation.md#82-wiederherstellung)
     beschrieben.

**Backup von vor einem Tresor-Schlüsselwechsel:** Es lässt sich nur mit
dem **alten** Wiederherstellungsschlüssel bzw. den damaligen Passwörtern
öffnen; der neue Schlüssel wird als „abgelöst“ abgelehnt.

**Abbruch mitten im Einspielen:** In der Regel macht der nächste Schritt
dort weiter. Meldet der Installer einen doppelten Eintrag (SQLSTATE
23000), das Einspielen von vorn beginnen („Neu beginnen“) – jede Tabelle
wird dabei neu angelegt.

**Probe-Restore:** Einmal im Jahr ein Backup auf einer Testinstallation
(eigene Subdomain, eigene Datenbank, Kanal `beta` oder `stable`) einspielen.
Nur so ist sicher, dass Backup, `config.php` und Wiederherstellungsschlüssel
zusammenpassen.

---

## 8. Rollen und Rechte einrichten

Technischer Ablauf: [01 §4](spec/01-sicherheit.md#4-rollen-und-rechte),
Freigabe des Tresors: [01 §2](spec/01-sicherheit.md#benutzer-lebenszyklus-und-schlüssel).

**Mitgelieferte Rollen** (Rechte unter `/admin/rollen` anpassbar, außer
Admin – der hat immer alle Rechte):

| Rolle | Wofür |
|---|---|
| Admin | Einrichtung, Benutzer, Backups, Updates – und alles andere |
| Vorstand | sieht alles, Auswertungen, Export, Audit-Log; lädt intern hoch |
| Finanzen | Kassenwart: Belege prüfen und festschreiben, Konten, Abgleich |
| Kassenprüfer | extern, nur lesend, befristet |
| Steuerberater | extern, nur lesend, befristet |
| Vereinsverantwortlicher | z. B. Abteilungsleiter: nur eigene Kostenstellen, lädt intern hoch |

Ein Konto kann mehrere Rollen haben (Rechte addieren sich); externe Rollen
lassen sich nicht mit internen kombinieren. Die genaue Rechte-Tabelle steht
in 01 §4.

**Benutzer anlegen** (`/admin/benutzer` → „Neu“, Recht `admin.users`):

1. Name, E-Mail, Rolle(n) wählen; bei „Vereinsverantwortlicher“ die
   Kostenstellen ankreuzen.
2. „Einladung senden“ – die Person bekommt einen Link (72 h gültig), setzt
   ihr Passwort und richtet beim ersten Login den zweiten Faktor ein.
3. **Tresor freigeben:** Danach steht bei allen Admins ein Banner
   „Freigaben ausstehend“. Ein Admin öffnet `/admin/tresor` und gibt das
   Konto frei. Erst dann kann die Person Belegdaten sehen.

**Externe Kassenprüfer (und Steuerberater):**

1. Konto mit Rolle **Kassenprüfer** anlegen.
2. **„Zeitraum der Daten“** setzen, z. B. 01.01.2026–31.12.2026: das Konto
   sieht dann nur Belege und Buchungen dieses Zeitraums.
3. **„Zugang bis“** setzen (Pflicht bei externen Konten; ohne Angabe heute
   + 60 Tage). Danach ist keine Anmeldung mehr möglich; verlängern über
   dasselbe Formular.
4. Zweiter Faktor ist für externe Konten immer Pflicht.
5. Einladung annehmen lassen, dann Tresor freigeben (wie oben).
6. Nach der Prüfung: Zugang auslaufen lassen oder das Konto sperren.

Der Kassenprüfer findet die Prüfungsübersicht unter
`/app/auswertung/kassenpruefung`, außerdem Export und Audit-Log.

**Sperren und Entziehen:** „Sperren“ beendet alle Sitzungen des Kontos
sofort und entfernt die Tresor-Freigabe. Wer einem ehemaligen Mitglied mit
Tresor-Zugang nicht mehr traut, wechselt zusätzlich den Tresor-Schlüssel
([Abschnitt 5](#5-wiederherstellungsschlüssel)). Das letzte Admin-Konto
lässt sich nicht sperren.

**Empfehlung:** mindestens **zwei** Admins mit Tresor-Freigabe – dann
braucht ein vergessenes Passwort keinen Wiederherstellungsschlüssel.

---

## 9. Updates und Rollback

Technischer Ablauf: [06 §1](spec/06-betrieb.md#1-installer--updater--release)
(„Update-Schrittkette“, „Rollback und Tresor-Schlüsselrotation“).

**Update einspielen** (`/admin/update`, Recht `admin.system`):

1. Vorher ein vollständiges [Backup](#6-backups) erstellen und
   herunterladen (das automatische Update-Backup enthält keine
   Belegdateien).
2. „Nach Updates suchen“, dann „Update starten“. Die Schritte laufen
   nacheinander im Browser: prüfen → herunterladen → entpacken →
   Datenbank sichern → umschalten → Datenbank aktualisieren → abschließen.
   Den Tab offen lassen.
3. Während des Umschaltens ist die Anwendung im **Wartungsmodus**: nur
   `/admin` ist erreichbar, alle anderen sehen eine Wartungsseite.
4. Danach den [Code-Integritätscheck](#10-systemcheck-und-code-integritätscheck)
   laufen lassen.

**Release-Kanal:** `stable` für den Verein; `beta` nur auf einer
Testinstallation.

**Wenn ein Schritt scheitert:** Die Seite zeigt den Fehler und zwei Knöpfe:

- **„Schritt wiederholen“** – jeder Schritt ist wiederholbar.
- **„Rollback auf vorheriges Release“** – schaltet auf das vorherige
  Release zurück (es wird genau eines in `releases/_prev` aufbewahrt).

Der Rollback tauscht nur den **Code**; die Datenbank bleibt auf dem neuen
Stand (Datenbank-Änderungen sind so gebaut, dass die vorherige Version
damit weiterläuft). Ist die Datenbank selbst beschädigt, hilft das
Backup, das der Update-Schritt „Datenbank sichern“ angelegt hat
([Restore](#7-wiederherstellung-restore)). Während eines
Tresor-Schlüsselwechsels ist der Rollback gesperrt („Rotation erst
abschließen oder abbrechen“).

**Hängt der Wartungsmodus** (z. B. Browser während des Updates
geschlossen): `/admin/update` öffnen und das Update fortsetzen, „Status
zurücksetzen“ oder „Wartungsmodus aufheben“ wählen.

---

## 10. Systemcheck und Code-Integritätscheck

**Systemcheck** (`/admin/systemcheck`, Recht `admin.system`) –
[06 §5 „Laufzeit-Gegenstück“](spec/06-betrieb.md#laufzeit-gegenstück-systemcheck-m3-10):
prüft die Einstellungen, die der Hoster im Betrieb ändern könnte
(PHP-Grenzen, Erweiterungen, Datenbank-Timeout, Schreibrechte) und ob
`shared/config.php` aus dem Internet abrufbar ist. Ein rotes Ergebnis bei
„config.php abrufbar“ heißt: **sofort** die Domain-Einstellung beim Hoster
prüfen (muss auf `web/` zeigen). Das Ergebnis lässt sich als JSON kopieren,
z. B. für ein GitHub-Issue – es enthält keine Geheimnisse.

Wann ausführen: nach der Installation, nach jedem Update, nach
Änderungen beim Hoster (PHP-Version, Tarif) und einmal im Quartal.

**Code-Integritätscheck** (`/admin/integritaet`, Recht `admin.system`) –
[01 §8](spec/01-sicherheit.md#8-integritätscheck-code): vergleicht jede
Datei in `current/` mit der Prüfsummenliste des installierten Releases und
meldet geänderte, fehlende und zusätzliche Dateien. Erkennt plumpe
Manipulationen (z. B. eingeschleuste Dateien nach einem Einbruch beim
Hoster), ist aber kein Schutz gegen einen versierten Angreifer. Jede
Prüfung steht im Audit-Log.

- **Nach einem Rollback oder einem vor dem Umschalten abgebrochenen
  Update** meldet er viele abweichende Dateien: die gespeicherte
  Prüfsummenliste gehört dann zur neueren Version. Die Seite warnt in
  diesem Fall. Nach dem nächsten vollständigen Update passt es wieder.
- Ohne diesen Grund sind Abweichungen ernst zu nehmen: Admins informieren,
  Release neu einspielen (Update bzw. Neuinstallation mit Restore),
  Zugangsdaten beim Hoster ändern.

---

## 11. Zusatzfunktionen: Worker, Mail-Eingang, Archiv-OCR

**Optionaler Worker** – Einrichtung, Betrieb, Updates und Entkoppeln:
[`docs/worker-einrichtung.md`](worker-einrichtung.md); Technik:
[`docs/spec/07-worker.md`](spec/07-worker.md). Ohne Worker läuft alles
im Browser.

Zwei Funktionen gibt es **nur mit Worker**; beide werden unter
`/admin/worker` eingeschaltet:

- **Mail-Eingang per IMAP** ([07 §9](spec/07-worker.md#9-e-mail-eingang-imap)):
  Der Worker liest ein eigenes Postfach des Vereins (z. B.
  `rechnungen@verein.de`, nur verschlüsselte Verbindung) und legt jede
  neue Mail mit Rechnungsanhang als Beleg in den Posteingang – wie die
  öffentliche Einreichung. Eingereicht wird nur, was nach dem Einschalten
  ankommt. Für dieses Postfach am besten ein eigenes Konto mit eigenem
  Passwort anlegen, nicht das SMTP-Postfach mitbenutzen.
- **Archiv nachträglich durchsuchbar machen (Archiv-OCR)**
  ([07 §6 „Stand #88“](spec/07-worker.md#6-routing-auf-dem-webhoster)):
  Belege aus dem Archiv-Import (`/app/archiv-import`, Altbestand als ZIP)
  bekommen beim Import keine Texterkennung. Auf der Worker-Seite lässt sich
  das für einen Import-Lauf oder alle Archiv-Belege nachholen; der Vorgang
  läuft, solange jemand mit Recht `admin.settings` die Anwendung geöffnet
  hat, setzt nach einer Pause dort wieder an und lässt sich abbrechen.

---

## 12. Regelmäßige Aufgaben (Checkliste)

| Wann | Was |
|---|---|
| monatlich und vor jeder Kassenprüfung | Backup erstellen und **extern** ablegen ([6](#6-backups)) |
| vor jedem Update | Backup erstellen und herunterladen ([9](#9-updates-und-rollback)) |
| nach jedem Update | Systemcheck und Code-Integritätscheck ([10](#10-systemcheck-und-code-integritätscheck)) |
| vierteljährlich | Systemcheck; Mail-Warteschlange auf Fehler prüfen; Benutzerliste durchsehen (ausgelaufene externe Konten, ausgeschiedene Mitglieder) |
| jährlich | Probe-Restore ([7](#7-wiederherstellung-restore)); prüfen, ob Wiederherstellungsschlüssel und `config.php`-Kopie auffindbar sind |
| bei Vorstandswechsel | Konten anpassen, ggf. Tresor-Schlüssel wechseln ([5](#5-wiederherstellungsschlüssel)), Verwahrorte in der Verfahrensdokumentation aktualisieren |
| nach einem Restore | Cron-Token, `config.php`-Kopie, Worker-Kopplungen ([7](#7-wiederherstellung-restore)) |

Die vereinsinternen Kontrollen (Audit-Log-Prüfung, Festschreibungen) stehen
in der [Verfahrensdokumentation §10](verfahrensdokumentation.md#10-regelmäßige-kontrollen).

---

## 13. Für Maintainer

Dieser Abschnitt richtet sich an den Betreuer des Repositorys, nicht an
den Verein.

- Releases entstehen über den Release-Workflow (Tag `vX.Y.Z`); Assets sind
  das Release-ZIP, `setup.php` und `checksums.txt` (inkl. Prüfsummen je
  Datei für den Integritätscheck).
- **Worker-Image:** Das GHCR-Paket `vereinsbelege-worker` muss nach dem
  ersten Tag **einmalig auf „public“** gestellt werden, sonst kann der
  Verein es nicht herunterladen. Einzelheiten:
  [`docs/worker-einrichtung.md` §11](worker-einrichtung.md#11-für-maintainer).
