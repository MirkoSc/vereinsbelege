# Worker auf dem Raspberry Pi einrichten

Anleitung für Vereine, die das optionale **Worker-Modul** nutzen wollen:
ein kleiner Rechner (z. B. Raspberry Pi 4/5 oder ein VPS), der
Texterkennung (OCR), PDF/A-Erzeugung, PDF-Seiten und KI-Auslesen rund um
die Uhr übernimmt. Technischer Hintergrund: `docs/spec/07-worker.md`
(§4 Kopplung, §7 Betrieb, §8 Worker-Programm).

**Ohne Worker läuft alles wie bisher im Browser.** Der Worker ist ein
Zusatz – fällt er aus, übernimmt nach einer einstellbaren Frist wieder der
Browser (Admin → „Worker“, Standard 60 Minuten).

So arbeitet der Worker:

```
Raspberry Pi (Worker) ──fragt regelmäßig per https nach Arbeit──▶ Webhoster
```

- Der Worker **fragt selbst** beim Webhoster nach. Er braucht **keinen
  offenen Port**, keine Portfreigabe im Router und keine öffentliche
  Adresse.
- Er bekommt **nie den Tresor-Schlüssel**, nur für jedes einzelne Dokument
  einen eigenen Schlüssel, der nach der Arbeit gelöscht wird.
- Klartext eines Belegs liegt nur im Arbeitsspeicher (ein `tmpfs` unter
  `/arbeit`), nie auf der SD-Karte. Der Worker startet gar nicht erst,
  wenn `/arbeit` nicht im Arbeitsspeicher liegt.
- Dauerhaft gespeichert wird nur eine kleine Identitätsdatei (Worker-ID,
  Zugangsschlüssel, Schlüsselpaar des Workers) im Docker-Volume `daten`.

Inhalt:

1. [Was Sie brauchen](#1-was-sie-brauchen)
2. [Betriebssystem vorbereiten](#2-betriebssystem-vorbereiten) (Uhrzeit, Swap, Speicherbegrenzung)
3. [Docker installieren](#3-docker-installieren)
4. [Compose-Vorlage holen und anpassen](#4-compose-vorlage-holen-und-anpassen)
5. [Koppeln und Fingerabdruck vergleichen](#5-koppeln-und-fingerabdruck-vergleichen)
6. [Worker starten und Modul einschalten](#6-worker-starten-und-modul-einschalten)
   (mit [6.1 Mail-Eingang](#61-mail-eingang-optional))
7. [Updates](#7-updates)
8. [Logs ansehen](#8-logs-ansehen)
9. [Anhalten, neu starten, entkoppeln](#9-anhalten-neu-starten-entkoppeln)
10. [Fehlersuche](#10-fehlersuche)
11. [Für Maintainer](#11-für-maintainer)

Befehle in grauen Kästen werden auf dem Pi in einem Terminal eingegeben
(direkt am Pi oder per SSH). Zeilen mit `sudo` fragen nach dem Passwort
des Pi-Benutzers.

---

## 1. Was Sie brauchen

- **Raspberry Pi 4 oder 5 mit mindestens 2 GB RAM** – empfohlen sind
  **4 GB**. Ein VPS oder ein alter PC mit 64-Bit-Linux geht genauso.
- **64-Bit-Betriebssystem:** Raspberry Pi OS (64-bit) oder Debian. Das
  Worker-Image gibt es nur für `arm64` (Raspberry Pi mit 64-Bit-System) und
  `amd64` (normale PCs/Server). Ein 32-Bit-Raspberry-Pi-OS funktioniert
  **nicht**. Prüfen:

  ```bash
  uname -m
  ```

  Ausgabe muss `aarch64` (Pi) oder `x86_64` (PC/VPS) sein.
- **Internetzugang** für den Pi (ausgehend reicht).
- Eine gute SD-Karte oder besser eine **SSD per USB** – etwa 3 GB frei für
  das Image.
- Zugang zur Vereinsbelege-Installation mit einem Konto, das die
  **Einstellungen im Admin** ändern darf (Recht `admin.settings`).

## 2. Betriebssystem vorbereiten

Raspberry Pi OS (64-bit) wie gewohnt mit dem *Raspberry Pi Imager*
installieren, danach einmal alles aktualisieren:

```bash
sudo apt update && sudo apt full-upgrade -y
sudo reboot
```

### 2.1 Uhrzeit muss stimmen (NTP)

Jede Anfrage des Workers ist signiert und nur **±5 Minuten** gültig. Der
Raspberry Pi hat (bis auf den Pi 5 mit Pufferbatterie) keine Uhr, die
ohne Strom weiterläuft – nach einem Stromausfall startet er mit falscher
Zeit, bis er sie aus dem Internet holt. Mit stark falscher Uhr scheitert
außerdem schon die https-Verbindung (Zertifikat „noch nicht gültig“).

Prüfen:

```bash
timedatectl
```

Es muss `System clock synchronized: yes` und `NTP service: active` dort
stehen. Wenn nicht:

```bash
sudo timedatectl set-ntp true
```

Steht danach immer noch `NTP service: inactive` (auf manchen
Debian-Installationen fehlt der Dienst), einmal installieren:

```bash
sudo apt install -y systemd-timesyncd
sudo timedatectl set-ntp true
```

Der Worker gleicht kleine Abweichungen selbst aus (er übernimmt die
Serverzeit des Webhosters), verlassen Sie sich aber nicht darauf.

### 2.2 Swap auf der SD-Karte abschalten (wichtig)

Das Arbeitsverzeichnis `/arbeit` liegt im Arbeitsspeicher, damit kein
Klartext eines Belegs auf die SD-Karte geschrieben wird. **Wird der Speicher
knapp, lagert Linux aber Speicherseiten in den „Swap“ aus** – liegt der
Swap als Datei oder Partition auf der SD-Karte/SSD, landen Belegdaten
doch dort. Deshalb: **kein Swap auf der Karte**. Erlaubt ist nur
**zram** (komprimierter Swap im Arbeitsspeicher, nichts geht auf die
Karte).

Prüfen:

```bash
swapon --show
```

- **Keine Ausgabe** → kein Swap, alles gut.
- Nur Zeilen mit `/dev/zram…` → zram, alles gut.
- Eine Zeile mit `TYPE` = `file` oder `partition` (z. B. `/var/swap`) →
  abschalten, siehe unten.

**Swap-Datei abschalten.** Auf Raspberry Pi OS legt der Dienst
`dphys-swapfile` die Datei `/var/swap` an. Dauerhaft abschalten:

```bash
sudo swapoff -a
sudo systemctl disable --now dphys-swapfile 2>/dev/null || true
sudo apt purge -y dphys-swapfile 2>/dev/null || true
sudo rm -f /var/swap
```

Steht der Swap in `/etc/fstab` (Zeile mit `swap`), diese Zeile mit einem
`#` am Anfang auskommentieren:

```bash
sudo nano /etc/fstab
```

Danach neu starten und `swapon --show` erneut prüfen – es darf keine Zeile
mit `file` oder `partition` mehr erscheinen. Taucht trotzdem wieder eine
auf, legt ein anderer Dienst den Swap an; dann vor dem Start des Workers
klären, welcher (`systemctl list-units | grep -i swap`).

**zram einrichten (empfohlen, vor allem bei 2 GB RAM).** Ein Swap im
Arbeitsspeicher puffert Lastspitzen ab, ohne etwas auf die Karte zu
schreiben:

```bash
sudo apt install -y zram-tools
sudo systemctl enable --now zramswap
swapon --show
```

Jetzt sollte eine Zeile mit `/dev/zram0` erscheinen. (Raspberry-Pi-OS-
Versionen, die zram schon mitbringen, zeigen diese Zeile bereits ohne
diesen Schritt.)

### 2.3 Speicherbegrenzung für Container erlauben (nur Raspberry Pi OS)

Die Compose-Vorlage begrenzt den Speicher des Workers (`mem_limit`). Auf
manchen Raspberry-Pi-OS-Versionen ist die dafür nötige Kernel-Funktion
abgeschaltet – Docker ignoriert die Grenze dann. Das prüfen Sie nach der
Docker-Installation (Abschnitt 3, letzter Schritt). Falls `docker info`
die Warnung `No memory limit support` zeigt:

```bash
sudo nano /boot/firmware/cmdline.txt
```

Die Datei besteht aus **einer einzigen Zeile**. Ans Ende dieser Zeile – mit
einem Leerzeichen davor, **ohne** neue Zeile – anhängen:

```
cgroup_enable=memory cgroup_memory=1
```

Speichern, `sudo reboot`, `docker info` erneut prüfen. (Bei älteren
Systemen liegt die Datei unter `/boot/cmdline.txt`.)

## 3. Docker installieren

Docker aus dem offiziellen Docker-Paketarchiv installieren (das ist die
Anleitung von docker.com für Debian; sie gilt auch für Raspberry Pi OS
64-bit):

```bash
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
```

Damit Sie nicht vor jeden Befehl `sudo` schreiben müssen, den eigenen
Benutzer in die Gruppe `docker` aufnehmen und **einmal ab- und wieder
anmelden**:

```bash
sudo usermod -aG docker "$USER"
```

> Wer in der Gruppe `docker` ist, hat praktisch Administratorrechte auf
> dem Pi. Auf einem Pi, der nur für den Worker da ist, ist das in Ordnung.
> Andernfalls die Gruppe weglassen und allen `docker`-Befehlen ein `sudo`
> voranstellen.

Prüfen:

```bash
docker --version
docker compose version
docker info | grep -i -E 'warning|cgroup'
```

Die ersten beiden Befehle zeigen Versionsnummern. Erscheint beim dritten
`WARNING: No memory limit support` → Abschnitt 2.3.

Docker startet sich nach einem Neustart des Pi automatisch; der Worker
ebenfalls (`restart: unless-stopped` in der Vorlage).

## 4. Compose-Vorlage holen und anpassen

Einen Ordner für den Worker anlegen und die Vorlage hineinladen:

```bash
mkdir -p ~/vereinsbelege-worker
cd ~/vereinsbelege-worker
curl -fsSLO https://raw.githubusercontent.com/MirkoSc/vereinsbelege/main/worker/docker-compose.yml
```

Alle weiteren `docker compose`-Befehle **immer in diesem Ordner**
ausführen (`cd ~/vereinsbelege-worker`) – Docker sucht dort die Datei
`docker-compose.yml`.

### 4.1 Was die Vorlage tut

| Einstellung | Bedeutung |
|---|---|
| `image: ghcr.io/mirkosc/vereinsbelege-worker:stable` | Das fertige Worker-Image; `stable` = die freigegebenen Versionen (Abschnitt 7). |
| `restart: unless-stopped` | Startet nach Absturz oder Neustart des Pi wieder – außer Sie haben ihn selbst angehalten. |
| `WORKER_NAME` | Name, unter dem der Worker im Admin erscheint. |
| `WORKER_SPEICHER` | Speichergrenze des Worker-Programms selbst (PHP). |
| `tmpfs: /arbeit:size=768m` | Arbeitsverzeichnis **im Arbeitsspeicher**, höchstens 768 MB. Hier und nur hier liegen Belege kurz im Klartext. |
| `volumes: daten:/daten` | Die Identitätsdatei des Workers (nur lesbar für den Worker). |
| `stop_grace_period: 30s` | Beim Anhalten bekommt der Worker 30 Sekunden, um eine laufende Aufgabe sauber zurückzugeben (Abschnitt 9). |
| `mem_limit: 2g` | Der Container darf höchstens 2 GB Arbeitsspeicher belegen – **einschließlich** dessen, was in `/arbeit` liegt. |
| `read_only`, `cap_drop: ALL`, `no-new-privileges` | Der Container kann sein eigenes Dateisystem nicht verändern und hat keine Sonderrechte. Nicht entfernen. |
| `logging: max-size 10m, max-file 3` | Logs werden rotiert, höchstens ca. 30 MB. |

Nur `WORKER_NAME` und die Speicherwerte (4.2) sollten Sie anpassen.
`tmpfs`, `read_only`, `cap_drop` und `security_opt` bitte **nicht**
ändern oder entfernen.

Optional gibt es noch `WORKER_FAEHIGKEITEN` (z. B. `"ocr,render"`): Damit
meldet der Worker nur diese Fähigkeiten – mögliche Werte `ocr`
(Texterkennung + PDF/A), `render` (PDF-Seiten als Bilder) und `ai`
(KI-Auslesen). Ohne die Variable macht er alles. Eine weitere Variable,
`WORKER_ERLAUBE_HTTP_LOCALHOST`, ist **nur für automatische Tests** und
hat im Betrieb nichts zu suchen.

### 4.2 Speicher passend zum Pi einstellen

Die drei Werte hängen zusammen: Alles, was in `/arbeit` liegt, zählt zum
`mem_limit` des Containers. Dazu kommen das Worker-Programm
(`WORKER_SPEICHER`) und die Texterkennung (OCRmyPDF/Tesseract nutzen alle
Prozessorkerne gleichzeitig, jeder Kern braucht dabei Speicher). Das
Betriebssystem selbst braucht auf dem Pi etwa 300–500 MB.

Die Vorlage (`mem_limit: 2g`) ist für einen **Pi mit 4 GB** gedacht. Auf
einem 2-GB-Pi wäre die Grenze so groß wie der ganze Speicher – sie würde
nichts begrenzen, und bei einer großen Datei könnte der Pi ins Stocken
geraten. Deshalb:

| RAM des Pi | `mem_limit` | `tmpfs` (`size=`) | `WORKER_SPEICHER` | Hinweis |
|---|---|---|---|---|
| **2 GB** | `1536m` | `512m` | `"512M"` | zram einrichten (2.2). Normale Belege (Fotos, PDFs mit wenigen Seiten) passen; sehr große PDFs aus dem Archiv können mit Fehler abbrechen. |
| **4 GB** | `2g` | `768m` | `"512M"` | Vorlage unverändert lassen. Empfohlene Ausstattung. |
| **8 GB** | `4g` | `1536m` | `"1024M"` | Optional mehr Luft für große Archiv-PDFs; die Vorlagewerte gehen auch. |

So sieht die Anpassung für einen **2-GB-Pi** aus (`nano docker-compose.yml`):

```yaml
    environment:
      WORKER_NAME: "Pi im Vereinsheim"
      WORKER_SPEICHER: "512M"
    tmpfs:
      - /arbeit:size=512m,mode=1777
    # …
    mem_limit: 1536m
```

Regel für eigene Werte: `tmpfs` + `WORKER_SPEICHER` + etwa 500 MB für die
Texterkennung ≤ `mem_limit` ≤ RAM des Pi minus etwa 500 MB.

Was passiert, wenn es doch zu knapp wird: Ist `/arbeit` voll, bricht nur
die eine Aufgabe mit einem Fehler ab. Reißt der Container das `mem_limit`,
beendet Docker ihn hart und startet ihn neu; die gerade laufende Aufgabe
bietet der Webhoster nach Ablauf ihrer Sperre (30 Minuten) wieder an. In
beiden Fällen gehen keine Daten verloren und nichts landet auf der Karte –
es dauert nur länger.

## 5. Koppeln und Fingerabdruck vergleichen

Beim Koppeln lernen sich Worker und Webhoster kennen. Das geht nur einmal
und braucht einen Kopplungscode, den nur der Admin erzeugen kann.

1. **Image herunterladen** (auf dem Pi, im Ordner `~/vereinsbelege-worker`;
   dauert beim ersten Mal einige Minuten):

   ```bash
   docker compose pull
   ```

2. **Kopplungscode erzeugen:** Im Browser in Vereinsbelege anmelden →
   Admin → **„Worker“** → **„Kopplungscode erzeugen“**. Es erscheint ein
   Code der Form `ABCD-EFGH` und darunter der fertige Befehl. Der Code ist
   **15 Minuten** gültig, nur **einmal** verwendbar und wird nur jetzt
   angezeigt. (Den Knopf gibt es nur, solange kein Worker gekoppelt ist.)

3. **Koppeln:** Den angezeigten Befehl auf dem Pi ausführen, z. B.:

   ```bash
   docker compose run --rm worker pair https://belege.mein-verein.de ABCD-EFGH
   ```

   Die Adresse muss mit `https://` beginnen. Bei Erfolg erscheint:

   ```
   Gekoppelt als Worker 1 mit https://belege.mein-verein.de.
   Fingerabdruck: 1A2B 3C4D 5E6F 7A8B 9C0D 1E2F 3A4B 5C6D
   Bitte im Admin unter „Worker“ vergleichen und bestätigen.
   ```

   (Das ist ein Beispiel – Ihr Fingerabdruck sieht anders aus.)

4. **Fingerabdruck vergleichen:** Die Admin-Seite „Worker“ **neu laden**.
   Dort steht jetzt der Status „gekoppelt“ und ein Fingerabdruck. Beide
   Anzeigen **Zeichen für Zeichen** vergleichen (8 Gruppen à 4 Zeichen).

   - **Stimmen sie überein:** Haken bei „Der Fingerabdruck stimmt mit der
     Anzeige auf dem Worker überein“ setzen → **„Bestätigen“**. Erst jetzt
     holt der Worker Aufgaben ab.
   - **Weichen sie ab:** **Nicht bestätigen**, sondern **„Worker
     entkoppeln“**. Dann hat sich ein anderes Gerät mit Ihrem Code
     gekoppelt. Neuen Code erzeugen und von vorn beginnen.

   Warum der Vergleich? Der Worker prüft selbst schon, dass der Webhoster
   seinen Schlüssel unverändert erhalten hat. Der Vergleich durch einen
   Menschen stellt zusätzlich sicher, dass im Admin wirklich **Ihr** Pi
   steht und nicht ein Gerät, dem jemand den Code abgeschaut hat.

Den Fingerabdruck können Sie auf dem Pi jederzeit wieder anzeigen:

```bash
docker compose run --rm worker status
```

## 6. Worker starten und Modul einschalten

1. Worker dauerhaft im Hintergrund starten:

   ```bash
   docker compose up -d
   ```

2. Im Admin → „Worker“ den Haken **„Worker-Modul aktiv“** setzen und
   speichern. Erst dann gehen passende Aufgaben an den Worker.
   Die Frist „Rückfall auf den Browser nach (Minuten ohne Meldung)“ kann
   bleiben (60 Minuten).

3. Kontrolle nach etwa einer Minute, Seite neu laden:
   - Status **aktiv** und **online**,
   - „letzte Meldung“ mit aktueller Uhrzeit,
   - eine Version und die Fähigkeiten (Texterkennung, PDF-Seiten,
     KI-Auslesen).

4. Test: einen Beleg über `/einreichen` einreichen. In der Warteschlange
   auf der Admin-Seite erscheint die Aufgabe kurz und ist dann bei „In den
   letzten 24 Stunden erledigt“ mitgezählt.

### 6.1 Mail-Eingang (optional)

Der Worker kann zusätzlich ein **Postfach des Vereins** abrufen (z. B.
`belege@mein-verein.de`) und jede Mail mit Rechnung als Beleg in den
Posteingang legen – verschlüsselt wie eine Einreichung über das Formular.
Ohne Worker gibt es keinen Mail-Eingang. Hintergrund:
`docs/spec/07-worker.md` §9.

1. **Eigenes Postfach anlegen** (beim Mail-Anbieter des Vereins), nur für
   Belege. Der Worker markiert und verschiebt Mails, er löscht nie etwas –
   in einem gemischten Postfach stören die Markierungen.
2. Admin → „Worker“ → Abschnitt **„E-Mail-Eingang“** ausfüllen:
   - IMAP-Server, Verschlüsselung **SSL/TLS** (Port 993) oder **STARTTLS**
     (Port 143). Unverschlüsselt geht nicht; das Zertifikat muss gültig
     sein (selbstsignierte werden abgelehnt).
   - Benutzer und Passwort des Postfachs. Das Passwort wird verschlüsselt
     gespeichert und nie wieder angezeigt.
   - Abgerufener Ordner (meist `INBOX`), optional ein **Zielordner** für
     erledigte Mails (muss im Postfach schon existieren).
   - Optional **erlaubte Absender** (Adressen oder `@domain`, eine je
     Zeile). Leer = jeder Absender. Der Absender einer Mail lässt sich
     fälschen – die Liste hält Werbung ab, keinen gezielten Angriff.
   - Abrufintervall (1–60 Minuten, Vorgabe 5).
3. Haken **„E-Mail-Eingang aktiv“** und speichern. Eingereicht wird nur,
   was **ab heute** im Postfach ankommt; ältere Mails bleiben unberührt.
4. Test: eine Mail mit PDF-Anhang an das Postfach schicken. Nach dem
   nächsten Abruf steht sie im Posteingang mit der Marke **„per E-Mail“**,
   im Postfach ist sie markiert (bzw. in den Zielordner verschoben). Auf der
   Admin-Seite stehen Zeit und Zähler des letzten Abrufs.

**Was mit den Mails passiert** – sichtbar im Mailprogramm als Schlagwort
(Keyword), sofern der Mailserver eigene Schlagwörter erlaubt:

| Schlagwort | Bedeutung |
|---|---|
| `$Vereinsbelege` | Als Beleg eingereicht (zusätzlich gelesen). |
| `$VereinsbelegeAbgelehnt` | Nicht übernommen: kein passender Anhang, zu groß, Spam, Absender nicht erlaubt oder nach drei Versuchen unlesbar. Die Mail bleibt im Ordner – bitte ansehen und den Beleg ggf. von Hand hochladen. |
| `$VereinsbelegeVersuch`, `…Versuch2`, `…Versuch3` | Zähler der Versuche: Brach ein Abruf mitten in der Mail ab (Verbindung weg, Worker neu gestartet), wird die Mail beim nächsten Abruf erneut versucht – höchstens dreimal, danach „abgelehnt“. Eine schon eingereichte Mail wird dabei erkannt und nur als erledigt markiert, nie doppelt eingereicht. |

Erlaubt der Server keine eigenen Schlagwörter, markiert der Worker
eingereichte Mails nur als gelesen und abgelehnte als gelesen + Fähnchen
(`\Flagged`); als neu gelten dann nur ungelesene Mails. Ein Fähnchen, das
jemand von Hand gesetzt hat, lässt der Worker in Ruhe. Die Versuche zählt
er in diesem Fall nur im Arbeitsspeicher – nach einem harten Neustart
beginnt die Zählung von vorn.

**Grenzen:** die der Einreichung (Seiten, Dateigröße, Gesamtgröße), höchstens
20 Mails je Abruf und 200 je Tag. Zusätzlich nimmt der **Webhoster** je
Worker und Stunde höchstens 60 Mails und 500 MB an (Einstellungen
`mail_eingang_limit_stunde`, `mail_eingang_limit_mb_stunde`); darüber
beendet der Worker den Abruf, die übrigen Mails bleiben unmarkiert liegen
und folgen später. Die Admin-Seite zeigt dann „Mengengrenze je Stunde
erreicht“.

## 7. Updates

Das Image gibt es in drei Varianten („Tags“):

| Tag | Bedeutung | Für wen |
|---|---|---|
| `stable` | Die freigegebene Version. | **Normalbetrieb (Vorlage).** |
| `beta` | Die neueste veröffentlichte Version, noch nicht freigegeben. | Zum Testen. |
| `v1.2.3` | Genau diese Version, ändert sich nie. | Wer selbst bestimmen will, wann aktualisiert wird. |

Welche gerade läuft, zeigt die Admin-Seite „Worker“ (Version).

**Aktualisieren** (im Ordner `~/vereinsbelege-worker`):

```bash
docker compose pull
docker compose up -d
docker image prune -f
```

Der erste Befehl lädt die neue Version, der zweite startet den Worker
damit neu (eine laufende Aufgabe wird vorher sauber zurückgegeben), der
dritte löscht die alte Version von der Karte. Gibt es nichts Neues, tun die
Befehle nichts.

**Wann?** Nach jedem Update der Vereinsbelege-Installation. Passen Worker
und Webhoster nicht mehr zusammen, zeigt die Admin-Seite **„Worker-Update
nötig“** und der Worker bekommt keine Aufgaben mehr – bis er aktualisiert
ist, läuft alles wieder im Browser.

**Andere Variante wählen:** in `docker-compose.yml` die Zeile `image:`
ändern, z. B. `ghcr.io/mirkosc/vereinsbelege-worker:v0.8.0`, dann die
drei Befehle oben.

### 7.1 Optional: automatisch jede Woche aktualisieren

Nur mit `stable` empfohlen. Zwei Dateien anlegen (Benutzername `pi` und
Pfad ggf. anpassen):

```bash
sudo nano /etc/systemd/system/vereinsbelege-worker-update.service
```

```ini
[Unit]
Description=Vereinsbelege-Worker aktualisieren
Wants=network-online.target
After=network-online.target docker.service

[Service]
Type=oneshot
WorkingDirectory=/home/pi/vereinsbelege-worker
ExecStart=/usr/bin/docker compose pull --quiet
ExecStart=/usr/bin/docker compose up -d
ExecStart=/usr/bin/docker image prune -f
```

```bash
sudo nano /etc/systemd/system/vereinsbelege-worker-update.timer
```

```ini
[Unit]
Description=Vereinsbelege-Worker wöchentlich aktualisieren

[Timer]
OnCalendar=Sun 04:00
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
```

Einschalten und prüfen:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now vereinsbelege-worker-update.timer
systemctl list-timers vereinsbelege-worker-update.timer
```

## 8. Logs ansehen

```bash
docker compose logs --tail 50      # die letzten 50 Zeilen
docker compose logs -f             # laufend mitlesen, Ende mit Strg+C
docker compose logs --since 2h     # die letzten zwei Stunden
```

Jede Zeile ist ein kleiner JSON-Eintrag mit Uhrzeit und Ereignis. Die Logs
enthalten **nie Inhalte von Belegen**, keine Schlüssel und keine Ausgaben
der Texterkennung – nur Ereignis, Nummern, Dauer und Fehlercodes. Man kann
sie also gefahrlos weitergeben, wenn jemand bei der Fehlersuche hilft.

| Ereignis | Bedeutung |
|---|---|
| `worker_start` | Worker gestartet (mit Version; `aufgeraeumt` = Reste eines abgebrochenen Laufs entfernt). |
| `status` | Status beim Webhoster hat sich geändert: `gekoppelt` (wartet auf Bestätigung im Admin) oder `aktiv`. |
| `konfiguration` | KI-Einstellungen vom Webhoster übernommen (`ki_profile` = Anzahl). |
| `job_start` / `job_fertig` | Aufgabe begonnen / erledigt (`typ`: `ocr`, `render_pages`, `ai_extract`; `sekunden` = Dauer). |
| `job_fehler` | Aufgabe gescheitert und zurückgegeben. `endgueltig: true` = die Datei selbst ist das Problem (z. B. `PdfPasswortGeschuetzt`); sonst wird sie erneut versucht. |
| `job_verloren` | Aufgabe war inzwischen woanders (z. B. nach langer Pause im Browser erledigt) – harmlos. |
| `webhoster_fehler` | Der Webhoster hat mit einem Fehler geantwortet (`status`, `fehler`). Siehe Abschnitt 10. |
| `webhoster_nicht_erreichbar` | Keine Verbindung (Internet, DNS, Zertifikat, Uhrzeit). |
| `rueckgabe_fehlgeschlagen` | Eine Aufgabe ließ sich beim Beenden nicht zurückgeben; der Webhoster bietet sie nach 30 Minuten selbst wieder an. |
| `mail_abruf` | Ein Abruf des Postfachs (Mail-Eingang, Abschnitt 6.1): `neu`, `eingereicht`, `abgelehnt`, `fehler`, `sekunden`. |
| `mail_eingereicht` | Eine Mail ist im Posteingang (`status` `eingereicht` oder `vorhanden` = war schon da). |
| `mail_abgelehnt` | Eine Mail wurde nicht übernommen (`uid`, `grund`, z. B. `keine_anhaenge`, `spam`, `unlesbar`). |
| `mail_fehler` | Ein Versuch an einer Mail ist gescheitert (`uid`, `fehler`, `versuch` 1–3). |
| `mail_nicht_verschoben` | Der Server kann nicht verschieben (kein `MOVE`) – die Mail bleibt markiert im Ordner. |
| `worker_ende` | Worker sauber beendet. |

Bei Fehlern wartet der Worker erst 5 Sekunden, dann immer länger (bis
5 Minuten) und versucht es weiter – er gibt nie von selbst auf. Sobald der
Webhoster wieder antwortet, geht es normal weiter.

Weitere nützliche Befehle:

```bash
docker compose ps        # läuft der Worker? (STATUS "Up …")
docker stats --no-stream # Speicher- und CPU-Verbrauch, Spalte LIMIT = mem_limit
```

## 9. Anhalten, neu starten, entkoppeln

```bash
docker compose stop      # anhalten (bleibt bis zum nächsten "start" aus, auch nach Neustart des Pi)
docker compose start     # wieder starten
docker compose restart   # neu starten
```

**Den Worker nicht hart beenden** (kein `docker kill`, kein
`docker compose kill`, nicht einfach den Strom ziehen, wenn es sich
vermeiden lässt). Beim normalen Anhalten bricht der Worker eine laufende
Texterkennung ab (bis ca. 5 Sekunden) und meldet die Aufgabe beim Webhoster
als „zurückgegeben“ (bis ca. 10 Sekunden) – sie geht dann sofort wieder in
die Warteschlange. Deshalb steht in der Vorlage `stop_grace_period: 30s`;
Dockers Standard von 10 Sekunden wäre zu knapp. Wird der Worker hart
beendet, bleibt die Aufgabe beim Webhoster bis zu **30 Minuten** gesperrt,
bevor sie erneut angeboten wird. Daten gehen dabei nicht verloren, und weil
`/arbeit` im Arbeitsspeicher liegt, bleibt auch nach einem Stromausfall
nichts davon zurück.

Den Pi selbst immer mit `sudo shutdown -h now` herunterfahren – das hält
auch den Worker ordentlich an.

**Entkoppeln** (Pi wird ausgemustert, verloren, neu aufgesetzt): Admin →
„Worker“ → **„Worker entkoppeln“**. Der Worker hat damit sofort keinen
Zugang mehr, seine Dokumentschlüssel werden gelöscht und offene Aufgaben
laufen wieder im Browser. Auf dem Pi danach:

```bash
docker compose down -v   # Container und Volume "daten" (Identität) löschen
```

**Neu koppeln** (z. B. nach Neuinstallation des Pi oder wenn das Volume
`daten` verloren ging): erst im Admin entkoppeln, dann wie in Abschnitt 5
einen neuen Code erzeugen. Hat der Pi noch eine alte Identität, verweigert
`pair` das Koppeln; dann `--neu` anhängen:

```bash
docker compose run --rm worker pair https://belege.mein-verein.de WXYZ-2345 --neu
```

Eine Sicherung der Identitätsdatei ist nicht nötig – neu koppeln dauert
zwei Minuten und ist sicherer als eine Kopie des Schlüssels herumliegen zu
haben.

## 10. Fehlersuche

| Was Sie sehen | Ursache und Abhilfe |
|---|---|
| `docker compose pull`: `manifest unknown` oder `not found` | Diese Variante des Images gibt es (noch) nicht. Bei `stable`: Es wurde noch keine Version freigegeben – vorübergehend `beta` oder eine feste Version `vX.Y.Z` eintragen (Abschnitt 7). |
| `docker compose pull`: `unauthorized` / `denied` | Das Image ist noch nicht öffentlich – Fall für den Maintainer (Abschnitt 11). |
| `exec format error` | 32-Bit-System oder falsche Architektur. `uname -m` muss `aarch64` oder `x86_64` zeigen (Abschnitt 1). |
| `pair`: `Fehler: Der Kopplungscode ist ungültig oder abgelaufen.` | Code abgelaufen (15 min), schon benutzt oder vertippt. Im Admin neuen Code erzeugen. |
| `pair`: `Fehler: Es ist bereits ein Worker gekoppelt.` | Im Admin ist noch ein Worker eingetragen. Dort entkoppeln, dann neuen Code. |
| `pair`: `Dieser Worker ist bereits gekoppelt. Neu koppeln mit --neu …` | Der Pi hat noch eine alte Identität. Erst im Admin entkoppeln, dann `pair … --neu`. |
| `pair`: `Fehler: Zu viele falsche Codes …` | Zu viele Fehlversuche – 15 Minuten warten. |
| `pair`: `Webhoster nicht erreichbar: …` | Adresse falsch (genau die Adresse aus dem Befehl auf der Admin-Seite verwenden), kein Internet, oder die Uhr des Pi stimmt nicht (Abschnitt 2.1). |
| `pair`: `Fehler: Die Adresse des Webhosters muss mit https:// beginnen.` | Adresse mit `https://` angeben. |
| `pair`: `Fehler: Der Webhoster meldet einen anderen Fingerabdruck – Kopplung abgebrochen.` | Die Verbindung wurde unterwegs verändert. Nicht weitermachen, Netz prüfen, im Admin entkoppeln und neu beginnen. |
| Logs: `Nicht gekoppelt: zuerst worker pair …` und Container startet immer wieder | Abschnitt 5 fehlt noch. `docker compose stop`, koppeln, dann `docker compose up -d`. |
| Logs: `Das Arbeitsverzeichnis … liegt nicht im Arbeitsspeicher` | Der `tmpfs`-Eintrag in `docker-compose.yml` fehlt oder ist falsch eingerückt. Vorlage neu holen (Abschnitt 4). |
| Logs: `Die PHP-Erweiterung pcntl fehlt` | Kein offizielles Image. `image:` auf `ghcr.io/mirkosc/vereinsbelege-worker:…` stellen. |
| Logs: `webhoster_nicht_erreichbar` | Internet, DNS oder Zertifikat. Uhrzeit prüfen (`timedatectl`). Ist die Vereinsbelege-Seite im Browser erreichbar? |
| Logs: `webhoster_fehler` mit `status 401`, `nicht_authentifiziert` | Im Admin entkoppelt → neu koppeln. Oder die Uhr des Pi geht falsch (Abschnitt 2.1). |
| Logs: `webhoster_fehler` mit `fehler protokoll` / Admin: „Worker-Update nötig“ | Worker und Webhoster haben unterschiedliche Versionen → Worker aktualisieren (Abschnitt 7). |
| Logs: `webhoster_fehler` mit `status 429` | Zu viele Anfragen – erledigt sich von selbst. |
| Logs: `status gekoppelt`, aber keine Aufgaben | Fingerabdruck im Admin noch nicht bestätigt (Abschnitt 5, Schritt 4). |
| Admin: Status `aktiv`, aber keine Aufgaben kommen an | Haken „Worker-Modul aktiv“ fehlt (Abschnitt 6). |
| Admin: `offline` | Worker läuft nicht (`docker compose ps`) oder erreicht den Webhoster nicht (Logs). Nach der eingestellten Frist übernimmt der Browser; nach 24 Stunden ohne Meldung geht eine Alarm-Mail an die Verwaltung. |
| Admin, E-Mail-Eingang: „Anmeldung abgelehnt“ / „Server nicht erreichbar“ / „TLS fehlgeschlagen“ | Zugangsdaten, Servername und Port prüfen (Abschnitt 6.1). Manche Anbieter verlangen ein eigenes App-Passwort für IMAP. |
| Admin, E-Mail-Eingang: „Mengengrenze je Stunde erreicht“ | Mehr Mails als die Grenze des Webhosters – der Rest folgt in der nächsten Stunde von selbst. |
| Mail mit Schlagwort `$VereinsbelegeAbgelehnt`, Log `grund unlesbar` | Die Mail ließ sich dreimal nicht verarbeiten. Anhang speichern und über `/einreichen` hochladen. |
| `job_fehler` mit `PdfPasswortGeschuetzt` | Die PDF-Datei ist passwortgeschützt – kein Fehler des Workers. |
| Container startet ständig neu, `docker compose ps` zeigt `Restarting`, `docker inspect` zeigt `"OOMKilled": true` | Speicher zu knapp. Werte nach Abschnitt 4.2 anpassen (bei 2 GB: kleinere Werte und zram). |
| `docker stats` zeigt bei LIMIT den ganzen Speicher statt `mem_limit` | Speicherbegrenzung ist aus → Abschnitt 2.3. |
| Pi wird sehr langsam, SD-Karte blinkt dauernd | Swap auf der Karte → Abschnitt 2.2. |

Prüfen, ob ein Container wegen Speichermangels beendet wurde:

```bash
docker inspect --format '{{.State.OOMKilled}} {{.RestartCount}}' "$(docker compose ps -q worker)"
```

Hilft nichts davon: die letzten 100 Zeilen Log (`docker compose logs --tail
100`) und einen Screenshot der Admin-Seite „Worker“ an den Betreuer der
Installation schicken. Die Logs enthalten keine Belegdaten.

---

## 11. Für Maintainer

Dieser Abschnitt richtet sich an den Betreuer des Repositorys, nicht an
den Verein.

- **Paket einmalig öffentlich stellen.** Nach dem **ersten** Tag-Push legt
  `worker-image.yml` das Paket `ghcr.io/mirkosc/vereinsbelege-worker` an –
  GitHub macht neue Pakete standardmäßig **privat**. Einmalig in GitHub
  unter *Profil → Packages → vereinsbelege-worker → Package settings →
  Danger Zone → Change visibility* auf **Public** stellen. Sonst scheitert
  `docker compose pull` beim Verein mit `unauthorized`. Danach prüfen,
  z. B. ohne Anmeldung: `docker pull ghcr.io/mirkosc/vereinsbelege-worker:beta`.
- **Tags:** Jeder Tag `vX.Y.Z` baut nach dem End-to-End-Test ein
  Multi-Arch-Image `vX.Y.Z` (und `beta`, wenn es das höchste Versions-Tag
  ist). **`stable` existiert erst**, sobald ein Release in GitHub als
  „latest“ veröffentlicht bzw. hochgestuft wurde (`release: released`).
  Bis dahin funktioniert die Vorlage (`:stable`) nicht – vor der ersten
  Einrichtung beim Verein einmal ein Release freigeben.
- **Protokollversion:** Ändert sich `WorkerProtokoll::VERSION`, braucht der
  Verein nach dem Webhoster-Update auch ein Worker-Update (Admin zeigt
  „Worker-Update nötig“). In den Release-Notes darauf hinweisen.
- **Vorlage:** Die Anleitung lädt `worker/docker-compose.yml` von `main`.
  Änderungen an der Vorlage, die ein älteres `stable`-Image nicht kann,
  erst nach dem Release mergen oder hier versionieren.
- **Abnahme:** Die Prüfliste für eine echte Einrichtung steht im PR zu
  Issue #56.
