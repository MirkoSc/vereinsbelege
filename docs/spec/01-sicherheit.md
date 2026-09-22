# 01 – Sicherheit: Anmeldung, Rollen, Verschlüsselung

## 1. Bedrohungsmodell

| Angreifer hat … | Schutzziel | Erreicht durch |
|---|---|---|
| nur DB-Zugriff (phpMyAdmin-Leck, DB-Dump, Backup-Datei) | fachliche Daten nicht lesbar | Tresor-Verschlüsselung; Server-Schlüssel liegt nicht in der DB |
| nur Lesezugriff aufs FTP (inkl. `config.php`, `var/blobs/`, Backups) | fachliche Daten nicht lesbar | Tresor-Private-Key liegt nirgends auf dem Server im Klartext |
| FTP + DB lesend | fachliche Daten nicht lesbar | wie oben; lesbar werden nur Betriebsdaten (Benutzer-Mails, API-Keys) |
| Schreibzugriff aufs FTP (Code manipulieren) | **nicht vollständig schützbar** | kann beim nächsten Login Schlüssel abgreifen. Gegenmaßnahmen: Release-Prüfsummen, Integritätscheck im Admin (Abschnitt 8), FTP-Passwort stark + nur bei Bedarf |
| Zugriff auf den optionalen Worker (z. B. Pi gestohlen) | Schaden begrenzen | Worker hat nie den Tresor-Schlüssel, nur DEKs gerade wartender Dokumente; Entkoppeln im Admin sperrt sofort (07 §3) |
| gestohlenes Passwort eines Nutzers | Login verhindern | 2FA, Rate-Limit, Mail bei neuem Gerät |
| Missbrauch der öffentlichen Einreichung | Spam/DoS begrenzen | Rate-Limit, Größen-/Typprüfung, Proof-of-Work, Honeypot, Mindest-Ausfülldauer |

Ehrlich dokumentieren (auch in der Datenschutzerklärung): Der gewählte
KI-Anbieter sieht die Belege im Klartext. Für personenbezogene Daten
(Einreicher-IBAN) ist bei Cloud-Anbietern ein AV-Vertrag nötig; der lokale
llama.cpp-Server ist die datensparsame Option.

## 2. Schlüsselhierarchie (libsodium, `ext-sodium`)

```
Wiederherstellungsschlüssel (Papier, Vereinstresor)
          │ (enthält)
Tresor-Schlüsselpaar  VK_pub (Klartext in DB)  /  VK_priv (nie im Klartext gespeichert)
          │                                   ├─ versiegelt an jeden freigegebenen Benutzer:
          │                                   │     vault_grant.sealed = box_seal(VK_priv, U_pub)
          │                                   └─ Benutzer-Schlüsselpaar U_pub / U_priv
          │                                         U_priv gewrappt mit KEK = Argon2id(Passwort, Salt)
          ▼
Datenschlüssel (DEK) je Datensatz / je Blob, zufällig
   dek_sealed = box_seal(DEK, VK_pub)        ← geht OHNE Geheimnis (öffentliche Einreichung!)
Felder:  XChaCha20-Poly1305-IETF(DEK, nonce, AAD = "tabelle|id|spalte")
Blobs:   secretstream_xchacha20poly1305 mit DEK, Chunks à 64 KiB
Blind Index: HMAC-SHA256(BIK, normalisierter Wert), BIK = KDF(VK_priv, "blind-index-v1")
```

Server-Schlüssel (`shared/config.php`, 32 Byte, bei Installation erzeugt):
`secretbox` für Betriebsdaten: `user.email_enc`, `mail_queue.*_enc`,
`ai_provider.api_key_enc`, `totp_secret_enc`. Login-Lookup über
`user.email_bi = HMAC(Server-Schlüssel, lowercase(email))`.

### Speicherformate (`App\Service\Crypto`)

Jeder gespeicherte Wert beginnt mit einem Versionsbyte – ein Leser, der eine
unbekannte Version findet, sagt das, statt Unsinn zurückzugeben.

| Wert | Spalte | Aufbau |
|---|---|---|
| Feld (Tresor) | `*_enc` | `Version(1) \| Nonce(24) \| XChaCha20-Poly1305-IETF`, AAD = `tabelle\|id\|spalte` |
| Betriebsdaten (Server-Schlüssel) | `*_enc` | `Version(1) \| Nonce(24) \| secretbox` |
| Datenschlüssel | `dek_sealed` | `Tresor-Version(1) \| box_seal(DEK, VK_pub)` |
| Benutzer-Privatkey | `user_key.wrapped_private_key` | `Version(1) \| Nonce(24) \| secretbox(U_priv, KEK)`, KEK = Argon2id(Passwort, `kdf_salt`, `kdf_ops`, `kdf_mem`) |
| Tresor-Freigabe | `vault_grant.sealed_private_key` | `Tresor-Version(1) \| box_seal(VK_priv, U_pub)` |
| Wiederherstellungsschlüssel | – (nur Papier) | `Version(1) \| VK_priv(32) \| Prüfsumme(2)`, Crockford-Base32 |
| Blind Index | `*_bi` | rohe 32 Byte `HMAC-SHA256(BIK, zweck \| 0x00 \| normalisierter Wert)` |
| Blob-Kopf | `file_blob.header` | `Version(1) \| secretstream-Header(24)` |
| Blob-Inhalt | Datei bzw. `file_blob_chunk.data` | Folge von Blöcken `secretstream(DEK)` à 64 KiB Klartext (65553 Byte Chiffrat), der letzte kürzer und mit `TAG_FINAL`; `file_blob.cipher_sha256` = SHA-256 über das gesamte Chiffrat |

- Die feste Chunkgröße erlaubt dem Leser, die Blöcke ohne eigenes
  Rahmenformat zu trennen – deshalb sind beide Speicher-Backends (`db`/`fs`)
  austauschbar. `TAG_FINAL` macht eine abgeschnittene Datei zum Fehler statt
  zu einem kürzeren Beleg; die Verkettung von secretstream erkennt
  vertauschte oder fehlende Blöcke. Beide Seiten halten nie mehr als einen
  Chunk im Speicher.
- Tabellen- und Spaltennamen in der AAD sind auf `[a-z][a-z0-9_]*` begrenzt,
  damit kein Bestandteil das Trennzeichen enthalten und die Bindung
  aushebeln kann.
- Die Tresor-Version im `dek_sealed` ist der vorgesehene Pfad für eine
  spätere Schlüsselrotation (siehe „Sperren/Entfernen"). Die Freigabe trägt
  sie ebenso, damit eine Generation ihre Grants findet;
  `vault_grant.vault_version` spiegelt das Byte nur für SQL.
- Die KDF-Parameter stehen je Benutzer im Klartext daneben
  (`kdf_salt`, `kdf_ops`, `kdf_mem`), damit die Vorgaben später angehoben
  werden können, ohne bestehende Benutzer auszusperren: eine alte Zeile wird
  mit ihren eigenen Parametern geöffnet und beim nächsten Passwortwechsel auf
  den aktuellen Stand gehoben.
- Das Versionsbyte des Wiederherstellungsschlüssels ist die Version **dieser
  Kodierung**, nicht die Tresor-Generation – ein gedruckter Schlüssel muss
  auch für eine Installation lesbar bleiben, die das Format inzwischen
  geändert hat. Zu welchem Tresor er gehört, entscheidet der Vergleich mit
  `vault.public_key`.
- Der Wiederherstellungsschlüssel enthält VK_priv selbst und wird nirgends
  gespeichert; 35 Byte Nutzlast ergeben genau 56 Zeichen = 7 Gruppen à 8.
  Crockford-Base32 lässt I, L, O und U weg, beim Einlesen gelten zusätzlich
  `O → 0` und `I`/`L → 1`, Groß-/Kleinschreibung und Trennzeichen sind egal;
  die Prüfsumme (BLAKE2b, 2 Byte) fängt den Rest ab.
- Der HMAC-Schlüssel wird abgeleitet (`BIK = BLAKE2b(VK_priv,
  "blind-index-v1")`, für `user.email_bi` entsprechend aus dem
  Server-Schlüssel), damit dasselbe Geheimnis nicht für zwei Primitive dient.
- Jeder Blind Index trägt seinen Zweck (`supplier.iban`,
  `bank_account.iban`): derselbe Wert in zwei Spalten ergibt zwei
  verschiedene Indexe und ist damit für einen DB-Leser nicht korrelierbar.
  Normalisiert werden Groß-/Kleinschreibung und Leerraum; alles Weitere
  (IBAN ohne Gruppen, Rechnungsnummer) macht der Aufrufer.

### Session-Entsperrung
- Login erfolgreich (inkl. 2FA) → KEK aus Passwort → `U_priv` entpacken →
  `VK_priv = box_seal_open(vault_grant.sealed)`.
- `VK_priv` wird mit einem zufälligen Session-Schlüssel `K_s`
  verschlüsselt in `$_SESSION` abgelegt; `K_s` steht **nur** im Cookie
  `__Host-vk` (`httponly`, `secure`, `samesite=Strict`). Server-Sessiondatei
  allein ist damit wertlos. Umgesetzt mit M3-3
  (`App\Service\Account\SessionVault`, Ablage
  `Version(1) | Nonce(24) | secretbox(VK_priv, K_s)`; das Cookie baut
  `App\Http\Cookie::vaultKey()`).
- Das `__Host-`-Präfix verlangt zwingend `Secure`, `Path=/` und kein
  `Domain`. Über reines HTTP – also ausschließlich im Docker-Dev auf
  `http://localhost:8080` – würde der Browser ein solches Cookie verwerfen
  und der Login wäre dort unbenutzbar; deshalb heißt es dann schlicht `vk`,
  bei sonst gleichen Attributen. `App\Http\Session::start()` leitet sein
  `secure`-Flag aus demselben Grund aus dem Request-Schema ab. Produktion
  läuft über HTTPS (Installationsvoraussetzung), dort gilt immer
  `__Host-vk`. Gelesen wird unter beiden Namen, geschrieben nach Schema.
- Idle-Timeout 30 min, absolut 12 h. Beides sind Settings
  (`session_idle_timeout_s`, `session_absolute_timeout_s`,
  `App\Service\Account\SessionTimeouts`); ein unbrauchbarer Wert fällt auf
  den Vorgabewert zurück, statt den Ablauf abzuschalten. Logout löscht
  beides – Sitzung und Cookie.
- Ohne `__Host-vk` ist die Sitzung weiterhin angemeldet, kann aber nichts
  entschlüsseln. Das ist ein eigener Zustand, kein Fehler: Seiten zeigen
  dann einen Hinweis statt leerer Listen
  (`App\Service\Account\VaultAccess`).
- Jeder Request hinter der Anmeldung prüft das Konto erneut
  (`App\Http\LoginGuard`): Sperren oder Ablaufdatum wirken sofort, nicht
  erst beim nächsten Login.
- Argon2id-Parameter: `OPSLIMIT_INTERACTIVE`/`MEMLIMIT_INTERACTIVE`
  (64 MiB) als Default; im Hosting-Check verifizieren (memory_limit!).

### Benutzer-Lebenszyklus und Schlüssel
- **Einladung**: Admin legt Benutzer (E-Mail, Rollen) an → Einladungs-Mail
  (Token 72 h) → Nutzer setzt Passwort + 2FA → Schlüsselpaar wird erzeugt →
  Status „Tresor-Freigabe ausstehend".
- **Freigabe**: Jeder angemeldete Admin (mit entsperrtem Tresor) sieht ein
  Banner „N Freigaben ausstehend" → ein Klick versiegelt `VK_priv` an
  `U_pub`. Admins bekommen dazu eine Mail (ohne fachlichen Inhalt).
- **Passwort ändern** (altes bekannt): `U_priv` neu wrappen, sonst nichts.
- **Passwort vergessen** (Reset per Mail): `U_priv` ist verloren → neues
  Schlüsselpaar, alte Grant-Zeile gelöscht → erneute Freigabe durch einen
  Admin nötig. UI erklärt das vorab.
- **Letzter Admin hat Passwort vergessen**: Wiederherstellung im Installer-
  ähnlichen Flow `/admin/wiederherstellen` mit Wiederherstellungsschlüssel.
- **Sperren/Entfernen**: Grant-Zeile löschen. Echte Schlüsselrotation
  (neuer VK, alles umschlüsseln per Schrittkette) ist Backlog, der Pfad wird
  aber im Datenmodell vorgesehen (`vault.version`, `dek_sealed` mit
  Versionspräfix).
- **Wiederherstellungsschlüssel**: bei Installation einmalig angezeigt
  (Crockford-Base32, 7 Gruppen à 8, Prüfsumme – Format siehe
  „Speicherformate") + als druckbare Seite. Installation erst
  abschließbar nach Eingabe der letzten Gruppe (Beweis, dass notiert).
  Umgesetzt mit M3-2 (`App\Installer\FirstAdminSetup`, 06 §1): Tresor und
  Admin stehen schon in der Datenbank, sobald der Schlüssel angezeigt wird –
  `VK_priv` darf ja nie unverschlüsselt in der Session liegen –, nur der Hash
  der letzten Gruppe wartet dort auf die Bestätigung. Bricht der Vorgang vorher
  ab (Browser zu), bietet `/install` „Neu beginnen“ an und entfernt die
  halbfertige Zeile wieder, statt eine leere Datenbank zu verlangen.

## 3. Anmeldung

- Login mit **E-Mail + Passwort**. `password_hash()` mit `PASSWORD_ARGON2ID`
  (Fallback `PASSWORD_BCRYPT`, falls Hosting-Check Argon2 verneint).
  Passwort-Hash und KEK-Salt sind getrennt. Umgesetzt mit M3-3
  (`App\Service\Account\LoginService`, Hashing an einer Stelle in
  `App\Service\Account\PasswordHasher`, die auch der Installer nutzt).
  M3-3 meldet **ohne** zweiten Faktor an; M3-4 hängt den Schritt zwischen
  „Passwort stimmt" und „Tresor entsperrt" ein (`user.mfa_required`), der
  Tresor bleibt bis dahin zu.
- Konten-Zustand beim Login: nur `status = aktiv` und ein `expires_at` in
  der Zukunft kommen durch. Ein Konto ohne `vault_grant` meldet sich
  trotzdem an – es sieht nur nichts, bis ein Admin freigibt (siehe
  „Benutzer-Lebenszyklus").
- Passwortregeln: min. 12 Zeichen, Abgleich gegen eine mitgelieferte Liste
  häufiger Passwörter, keine Zusammensetzungsregeln. Umgesetzt als
  `App\Service\Account\PasswordPolicy` (M3-2), Liste unter
  `app/data/haeufige-passwoerter.txt` (CLAUDE.md §2) – eine eigene
  Zusammenstellung, keine separat lizenzierte Fremdliste (CLAUDE.md §8).
- **Zweiter Faktor** (pro Rolle erzwingbar, Default: Pflicht für alle):
  - **TOTP** (Authenticator-App, RFC 6238, QR-Code serverseitig in reinem
    PHP) – empfohlen.
  - **E-Mail-Code**: 6 Ziffern, 10 min gültig, max. 5 Versuche, nur Hash
    gespeichert.
  - 10 Einmal-Backup-Codes (gehasht) bei Einrichtung.
  - „Dieses Gerät 30 Tage merken" (optional, Token gehasht, pro Nutzer
    widerrufbar).
- **Brute-Force-Schutz**: Rate-Limit je IP und je Konto (übernommener
  `RateLimiter`), generische Fehlermeldungen (keine User-Enumeration), auch
  bei „Passwort vergessen". Seit M3-3: feste Fenster von 15 Minuten,
  20 Fehlversuche je IP, 10 je Konto; nur Fehlversuche zählen, ein Erfolg
  löscht beide Zähler. Der Schlüssel der Zeile ist ein Hash aus Zweck und
  Wert, die IP steht nie im Klartext in der Tabelle. Unbekannte Adresse,
  falsches Passwort, gesperrtes und abgelaufenes Konto liefern **wortgleich
  dieselbe** Meldung, und der Zweig ohne Konto verbrennt einen
  Schein-`password_verify()`, damit auch die Laufzeit nichts verrät.
- **Passwort-Reset**: Token 32 Byte, nur Hash gespeichert, 30 min,
  einmalig; beendet alle Sessions des Nutzers.
- Sicherheits-Mails an den Nutzer: neues Gerät, Passwort geändert, 2FA
  geändert, Tresor-Freigabe erteilt/entzogen.
- Session-ID-Regeneration bei Login und Rechtewechsel.
- **Bootstrap**: Wie im Vereinskalender legt der Installer den ersten Admin
  an – hier direkt mit E-Mail/Passwort, Tresor-Erzeugung und
  Wiederherstellungsschlüssel im selben Flow. Umgesetzt mit M3-2 (06 §1);
  der Login dazu mit M3-3.
- **Geschützte Bereiche**: `/app/*`, `/admin/*` und die Upload-Routen
  `/api/upload*` liegen hinter `App\Http\LoginGuard`, deklariert je Route in
  `app/src/routes.php`. Die Upload-Routen antworten dort mit 401 JSON statt
  einer Weiterleitung – sie werden aus `fetch()` gefahren. Offen bleiben die
  Startseite, `/anmelden`, `/abmelden` und `/cron` (eigenes Token, 06 §4).
  Die Anmeldeseite ist die einzige öffentliche Seite mit einer Session; sie
  braucht ein CSRF-Token und ist der Ort, an dem die spätere Sitzung
  beginnt. **Welcher** angemeldete Zugang was darf, entscheidet erst M3-6.

## 4. Rollen und Rechte

Rechte sind ein PHP-Enum `Permission`; Rollen sind Datensätze mit einer
Menge von Rechten (Admin kann Rollen anlegen/anpassen). Mitgelieferte Rollen:

| Recht | Admin | Vorstand | Finanzen | Kassenprüfer | Steuerberater | Vereinsverantwortlicher |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `inbox.view` Posteingang sehen | ✓ | ✓ | ✓ | ✓ | ✓ | eigene Kostenstelle |
| `document.edit` Belege prüfen/bearbeiten/festschreiben | ✓ | – | ✓ | – | – | – |
| `document.submit_internal` intern hochladen | ✓ | ✓ | ✓ | – | – | ✓ |
| `supplier.manage` | ✓ | – | ✓ | – | – | – |
| `bank.import` / `bank.book` (manuelle Buchung, Kasse) | ✓ | – | ✓ | – | – | – |
| `bank.view` | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| `matching.edit` Abgleich | ✓ | – | ✓ | – | – | – |
| `report.view` Auswertungen | ✓ | ✓ | ✓ | ✓ | ✓ | eigene Kostenstelle |
| `export.zip` / `export.csv` | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| `archive.import` | ✓ | – | ✓ | – | – | – |
| `audit.view` | ✓ | ✓ | – | ✓ | – | – |
| `admin.users` / `admin.vault_grant` | ✓ | – | – | – | – | – |
| `admin.settings` (KI, Mail, Speicher, Kategorien) | ✓ | – | – | – | – | – |
| `admin.system` (Backup, Update, Wartung) | ✓ | – | – | – | – | – |

- Kein Vier-Augen-Prinzip (E-09): wer `document.edit` hat, darf auch
  festschreiben.
- **Externe Rollen** (Kassenprüfer, Steuerberater): Konto mit Pflicht-
  Ablaufdatum (Default 60 Tage, verlängerbar), 2FA immer Pflicht,
  ausschließlich lesend. Beim Anlegen wählbar: Zugriff auf Zeitraum
  beschränken (z. B. nur Geschäftsjahr 2026) – Filter im Repository
  erzwungen.
- „Vereinsverantwortlicher" (z. B. Jugendleiter, Abteilungsleiter) ist auf
  zugewiesene Kostenstellen beschränkt – Filter im Repository erzwungen,
  nicht in der View.
- Adminseite `/admin/*` nur mit mindestens einem `admin.*`-Recht erreichbar.

## 5. Öffentliche Einreichung – Schutz

- Rate-Limit je IP (z. B. 10 Einreichungen/Stunde, Setting).
- Proof-of-Work-Challenge (selbst gehostet, ALTCHA-Prinzip, kein
  Drittanbieter, kein Cookie-Banner nötig).
- **Honeypot-Feld** (für Menschen unsichtbar) und **Mindest-Ausfülldauer**
  (Formular-Token mit Zeitstempel, < 5 s = Bot).
- Kein Einreich-Code, kein Captcha (E-14). Falls trotzdem Spam auftritt:
  Admin kann die Einreichung vorübergehend pausieren und die Einträge im
  Posteingang gesammelt verwerfen.
- Dateiprüfung serverseitig über Magic Bytes (JPEG, PNG, HEIC→ablehnen mit
  Hinweis bzw. clientseitig konvertiert, PDF), Maximalgröße je Datei und je
  Einreichung, Seitenzahl-Limit.
- Einreicher erhält Referenznummer + optional Bestätigungsmail (ohne
  Beleginhalt).
- IP wird nur als Hash für das Rate-Limit genutzt und nach 7 Tagen verworfen.

## 6. Audit-Log

- Append-only Tabelle `audit_log`; jede Zeile enthält `hash =
  SHA256(prev_hash || kanonisches JSON der Zeile)`. Details verschlüsselt
  (Tresor), Aktion/Entität/Zeit/Nutzer im Klartext.
- Geloggt: Login/Logout/Fehlversuche, 2FA-/Passwort-Änderungen,
  Freigaben, jede Änderung an Beleg/Lieferant/Buchung/Abgleich,
  Festschreibung, Export, Import, Einstellungsänderungen.
- Admin-Seite „Integrität prüfen" rechnet die Kette nach.

## 7. Festschreibung

- Ein geprüfter Beleg wird festgeschrieben (`locked_at`).
  Danach sind Betrag, Datum, Lieferant, Dokumente unveränderlich; Korrektur
  nur über „Festschreibung aufheben" (Recht + Pflicht-Begründung, Audit).

## 8. Integritätscheck Code

- Admin-Seite vergleicht die Dateien in `current/` gegen die
  `checksums`-Liste des installierten Releases (vom Updater gespeichert) und
  zeigt Abweichungen. Kein Schutz gegen einen versierten Angreifer, aber
  erkennt plumpe Manipulationen.

## Pflicht-Tests

Krypto-Roundtrips (Feld, Blob, Sealed-DEK), AAD-Vertauschung schlägt fehl,
Blind Index deterministisch; Benutzer-Lebenszyklus (Einladung → Freigabe →
Passwort ändern → Reset → erneute Freigabe) inkl. „ohne Grant kein
Entschlüsseln"; Wiederherstellungsschlüssel-Flow; Session ohne Cookie
`__Host-vk` kann nicht entschlüsseln; TOTP (RFC-6238-Testvektoren),
E-Mail-Code (Ablauf, Versuchslimit), Reset-Token (einmalig, Ablauf);
Rechte-Matrix als DataProvider über alle Routen (jede Route hat eine
Rechte-Deklaration – Test schlägt an, wenn eine Route keine hat);
Kostenstellen-Scope; Zeitraum-Scope und Ablauf externer Konten
(Kassenprüfer/Steuerberater); Audit-Hash-Kette inkl.
Manipulationserkennung; CSP-Compliance (übernommen).
