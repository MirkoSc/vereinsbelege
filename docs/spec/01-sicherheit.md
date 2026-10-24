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
`user.email_bi = HMAC(Server-Schlüssel, lowercase(email))`. Folge: Ein
Backup **ohne** `config.php` (der Standard) ist nach dem Einspielen erst
wieder betretbar, wenn der alte Schlüssel nachgereicht oder ein Admin-Konto
im Installer per Wiederherstellungsschlüssel + Passwort neu verknüpft ist
(06 §2 „Restore ohne Server-Schlüssel“, issue #154); `config.php` gehört
deshalb getrennt gesichert neben den Wiederherstellungsschlüssel.

### Speicherformate (`App\Service\Crypto`)

Jeder gespeicherte Wert beginnt mit einem Versionsbyte – ein Leser, der eine
unbekannte Version findet, sagt das, statt Unsinn zurückzugeben.

| Wert | Spalte | Aufbau |
|---|---|---|
| Feld (Tresor) | `*_enc` | `Version(1) \| Nonce(24) \| XChaCha20-Poly1305-IETF`, AAD = `tabelle\|id\|spalte` |
| Betriebsdaten (Server-Schlüssel) | `*_enc` | `Version(1) \| Nonce(24) \| secretbox` |
| Datenschlüssel | `dek_sealed` | `Tresor-Version(1) \| box_seal(DEK, VK_pub)` |
| Benutzer-Privatkey | `user_key.wrapped_private_key` | `Version(1) \| Nonce(24) \| secretbox(U_priv, KEK)`, KEK = Argon2id(Passwort, `kdf_salt`, `kdf_ops`, `kdf_mem`, `kdf_alg`) – Profil siehe „Argon2id-Profil der Umhüllung" |
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
  Schlüsselrotation (siehe „Schlüsselrotation“). Die Freigabe trägt
  sie ebenso, damit eine Generation ihre Grants findet;
  `vault_grant.vault_version` spiegelt das Byte nur für SQL.
- Die KDF-Parameter stehen je Benutzer im Klartext daneben
  (`kdf_salt`, `kdf_ops`, `kdf_mem`, seit Migration 033 auch `kdf_alg`),
  damit die Vorgaben später angehoben werden können, ohne bestehende
  Benutzer auszusperren: eine alte Zeile wird mit ihren eigenen Parametern
  geöffnet und bei der nächsten Anmeldung (oder beim nächsten
  Passwortwechsel) auf den aktuellen Stand gehoben – Einzelheiten unter
  „Argon2id-Profil der Umhüllung".
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
- Argon2id-Parameter: seit issue #100 das Profil MODERATE, siehe der
  folgende Abschnitt.

### Argon2id-Profil der Umhüllung (issue #100)

**Entscheidung** (vorläufig, autonom getroffen – Bestätigung durch Mirko
offen, siehe E-19): neue Umhüllungen von `U_priv` verwenden das libsodium-
Profil **MODERATE** – `opslimit` 3, `memlimit` 256 MiB, Argon2id v1.3
(`App\Service\Crypto\KdfParameters::defaults()`). Bis issue #100 war es
INTERACTIVE (2 Durchläufe, 64 MiB). Das Login-Passwort selbst
(`password_hash`, §3) ist davon nicht betroffen – es behält die
PHP-Vorgaben.

Gemessen mit `tools/hosting-check.php` auf dem Zielhost (M0, 21.09.2026,
[Hosting-Befunde](../hosting-befunde.md)):

| Vorgang | Dauer |
|---|---|
| `sodium_crypto_pwhash` INTERACTIVE (64 MiB, 2 Durchläufe) | 52–56 ms |
| `sodium_crypto_pwhash` MODERATE (256 MiB, 3 Durchläufe) | 309–321 ms |
| `password_hash(PASSWORD_ARGON2ID)` | 166–170 ms |
| `memory_limit` | 384 MiB |

Begründung: Die Umhüllung wird je Anmeldung genau einmal abgeleitet. Eine
Anmeldung kostet damit rund 0,5 s (Passwort prüfen + Umhüllung öffnen),
bei der einmaligen Anhebung eines alten Kontos zusätzlich ~0,3 s – weit
innerhalb von `max_execution_time` und für den Menschen kaum spürbar. Ein
Angreifer mit einem gestohlenen Datenbank-Dump zahlt dagegen je Rateversuch
den sechsfachen Rechen- und Speicheraufwand (3 × 256 MiB statt 2 × 64 MiB),
und 256 MiB je parallelem Versuch bremsen GPU-Angriffe zusätzlich. Gerade
die Umhüllung ist das lohnende Ziel: sie schützt den Tresor, nicht nur das
Konto.

**Speicher:** libsodium belegt die 256 MiB selbst, außerhalb der
Speicherverwaltung von PHP. `memory_limit` zählt sie deshalb nicht mit und
verhindert sie auch nicht (lokal geprüft: MODERATE läuft mit
`memory_limit=32M`, `memory_get_peak_usage()` bleibt bei ~2 MiB); begrenzt
wird nur durch das RAM bzw. die Prozessgrenzen des Hosts. Ein PHP-FPM-
Prozess bearbeitet einen Request nach dem anderen, rechnet also höchstens
einen solchen Hash zugleich; parallele Anmeldungen laufen in getrennten
Prozessen und brauchen für ~0,3 s je 256 MiB. Für die Handvoll Konten eines
Vereins ist das unkritisch, gehört aber festgehalten. Bekommt libsodium den
Speicher nicht, scheitert die Ableitung mit `SodiumException` („internal
error") → `CryptoException`: beim Anlegen einer Umhüllung (Installer,
Einladung, Reset, Passwortwechsel) wird dann nichts geschrieben, weil alle
vier vor ihrer Transaktion umhüllen; beim Anheben im Login bleibt die alte,
gültige Umhüllung stehen.

**Versioniert gespeichert** je Benutzer in `user_key`: `kdf_salt`,
`kdf_ops`, `kdf_mem` und `kdf_alg` (Migration 033). `kdf_alg` ist die
libsodium-Kennung, die Algorithmus **und** Argon2-Version zusammen benennt
(2 = `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`, Argon2id v1.3) – libsodium kennt
kein Argon2id anderer Version, eine eigene Versionsspalte trüge immer
denselben Wert. Alle Zeilen vor Migration 033 sind Argon2id v1.3, daher der
Vorgabewert 2; er hält auch das vorige Release schreibfähig. Eine
unbekannte Kennung lehnt `KdfParameters` ab, statt einen falschen Schlüssel
abzuleiten, der wie ein falsches Passwort aussähe. Das Format der Umhüllung
selbst trägt sein eigenes Versionsbyte (Tabelle oben).

**Anheben bei der nächsten Anmeldung:** Ist eine Zeile schwächer als das
aktuelle Profil (`KdfParameters::isWeakerThanDefaults()`: weniger
Durchläufe **oder** weniger Speicher), umhüllt
`App\Service\Account\LoginService` nach erfolgreichem Entsperren dasselbe
Schlüsselpaar mit demselben Passwort neu – frisches Salt, jeder
Kostenfaktor mindestens auf dem aktuellen Profil (`UserKey::upgradedWrapping()`,
`KdfParameters::raisedToDefaults()`: je Faktor das Maximum aus gespeichertem
und aktuellem Wert). Das Schlüsselpaar bleibt, die Freigabe
gilt also weiter. Geschrieben wird nur, wenn die Zeile noch genau die
gerade geöffnete ist (`UserKeyRepository::replaceWrapping()`,
Compare-and-Swap auf `public_key` und `wrapped_private_key`): ein
Passwortwechsel oder Reset, der dazwischen festgeschrieben wird, gewinnt –
sonst stünde wieder eine Umhüllung unter dem alten Passwort in der Zeile.
Das Anheben ist „best effort": scheitert die Ableitung oder das Schreiben,
bleibt die alte Zeile, die nächste Anmeldung versucht es erneut. Liest der
neue Code zwischen den Updater-Schritten „switch" und „migrate" eine Zeile
ohne `kdf_alg`, gilt der Vorgabewert (Argon2id v1.3). Der Passwortwechsel hebt
ebenfalls an (`UserKey::rewrap()`). Stärkere gespeicherte Werte bleiben
unangetastet – wird das Profil im Code einmal gesenkt, gilt das nur für
neue Umhüllungen.

**Keine Kalibrierung im Installer** (ebenfalls vorläufig, E-19): das Profil
ist eine feste Konstante, kein je Installation gemessener Wert. Eine
Messung im Installer erfasst einen Augenblick ohne Last, macht jede
Installation anders (Tests, Fehlersuche) und würde auf einem schwachen Host
still die Sicherheit senken. Ob MODERATE trägt, zeigt vorher der
Hosting-Check (06 §5, Zeile „pwhash MODERATE" mit Budget 1 s); ein Host,
auf dem es nicht trägt, ist ein Befund mit Folge-Issue, kein stiller
Rückfall. Ein späterer Profilwechsel ist eine Änderung von
`KdfParameters::DEFAULT_*`; bestehende Konten öffnen weiter mit ihren
gespeicherten Werten und werden bei der nächsten Anmeldung angehoben.

### Benutzer-Lebenszyklus und Schlüssel
- **Einladung**: Admin legt Benutzer (E-Mail, Rollen) an → Einladungs-Mail
  (Token 72 h) → Nutzer setzt Passwort + 2FA → Schlüsselpaar wird erzeugt →
  Status „Tresor-Freigabe ausstehend".
- **Freigabe**: Jeder angemeldete Admin (mit entsperrtem Tresor) sieht ein
  Banner „N Freigaben ausstehend" → ein Klick versiegelt `VK_priv` an
  `U_pub`. Admins bekommen dazu eine Mail (ohne fachlichen Inhalt).
- **Passwort ändern** (altes bekannt): `U_priv` neu wrappen; Schlüsselpaar
  und Grant bleiben. Zusätzlich enden die **anderen** Sitzungen des Kontos,
  die ändernde bleibt (Entscheidung zu issue #18 – wer ändert, weil das
  alte Passwort bekannt sein könnte, will genau das). Umgesetzt mit M3-5
  (`App\Service\Account\PasswordChange`, `/app/sicherheit/passwort`);
  falsche alte Passwörter zählen je Konto (Zweck `passwort.account`, Limit
  wie beim Login).
- **Passwort vergessen** (Reset per Mail): `U_priv` ist verloren → neues
  Schlüsselpaar, alte Grant-Zeile gelöscht → erneute Freigabe durch einen
  Admin nötig. UI erklärt das vorab. Umgesetzt mit M3-5
  (`App\Service\Account\PasswordReset`, `/anmelden/passwort-vergessen` und
  `/anmelden/passwort-neu`): beide Seiten und die Mail nennen die Folge vor
  dem Absenden, das Formular verlangt dafür ein Häkchen; danach sagt ein
  Hinweis auf der Anmeldeseite und – wie bei jedem Konto ohne Grant – der
  Login selbst, dass die Freigabe aussteht.
- **Sitzungen beenden** ohne Sitzungsregister: `user.session_epoch`
  (Migration 009). Jede Sitzung übernimmt den Wert beim Login
  (`App\Http\Session::login()`, bei 2FA schon zum Zeitpunkt der
  Passwortprüfung über `PendingLogin`), `App\Http\LoginGuard` beendet jede
  Sitzung, deren Wert abweicht. Reset und Ändern erhöhen ihn; beim Ändern
  übernimmt die eigene Sitzung den neuen Wert (`Session::adoptEpoch()`, mit
  neuer Session-ID).
- **Installation aus einem Backup ohne Server-Schlüssel**: der Installer
  verlangt nach dem Einspielen den alten Schlüssel (getrennt gesicherte
  `config.php`) oder Wiederherstellungsschlüssel + bisheriges Passwort eines
  Kontos mit `admin.users` und verknüpft dieses Konto unter dem neuen Schlüssel
  neu (06 §2, `App\Installer\RestoreAccess`). Der Wiederherstellungsschlüssel
  bekommt damit keine neue Befugnis – er enthält `VK_priv` und öffnet ohnehin
  alles; das Passwort bindet den Schritt an ein bestehendes Konto, dessen
  Schlüsselpaar und Freigabe unverändert bleiben.
- **Letzter Admin hat Passwort vergessen**: Wiederherstellung im Installer-
  ähnlichen Flow `/admin/wiederherstellen` mit Wiederherstellungsschlüssel.
  Umgesetzt mit M3-9 (issue #22): Recht `admin.vault_grant` wie
  `/admin/tresor` – derselbe Vorgang, nur ohne fremden entsperrten Tresor
  als Voraussetzung. Ablauf: Passwort per Mail zurücksetzen (siehe oben) →
  anmelden (ohne Freigabe) → hier den Schlüssel eingeben
  (`App\Service\Crypto\RecoveryKey::parse()->openVault()`). Erfolg versiegelt
  den Tresor dauerhaft an den **eigenen** `user_key` – `freigeben()` kennt
  keine Sperre gegen sich selbst – und entsperrt ihn zusätzlich sofort in
  dieser Sitzung (`SessionVault`, `__Host-vk`), wie nach einem Login. Hat das
  Konto schon eine Freigabe (nur die Sitzung war gesperrt), entsteht keine
  zweite. Falsche oder mistippte Schlüssel zählen je Konto gegen
  `RateLimiter::LOGIN_LIMIT_PER_ACCOUNT` im 15-Minuten-Fenster
  (`App\Service\Account\VaultRecovery`); die Prüfsumme unterscheidet dabei
  Tippfehler von falschem Format oder einem Schlüssel einer anderen
  Installation (`App\Service\Crypto\RecoveryKeyException`,
  `RecoveryKeyProblem`). Jeder Versuch, erfolgreich oder nicht, steht im
  Audit-Log (unten); ein Erfolg löst zusätzlich die Sicherheitsmail an jeden
  Admin mit `admin.vault_grant` und eigener Freigabe aus – auch an das
  handelnde Konto selbst, damit ein missbrauchter Schlüssel auffällt.
- Umgesetzt mit M3-7 (issue #20): **Einladung** über `/admin/benutzer`
  (`App\Admin\UserController`, Recht `admin.users`,
  `App\Service\Account\Invitation`): Zeile mit Status `eingeladen`, Rollen
  und Scopes gleich über `AccessAssignment`, Link-Token wie beim Reset
  (32 Byte hex, nur Hash mit Zweck `auth_token.invite` in `auth_token`,
  Typ `invite`, 72 h, einmalig, „erneut senden" ersetzt den Link). Die Seite
  hinter dem Link (`/anmelden/einladung`, öffentlich mit Session und CSRF,
  `Referrer-Policy: no-referrer`) setzt das Passwort; erst dabei entsteht
  das Schlüsselpaar, das Konto wird `aktiv`. Der zweite Faktor wird beim
  ersten Login eingerichtet (erzwungen wie bei jedem Konto mit
  `mfa_required`, §3). „Freigabe ausstehend" ist abgeleitet, kein Status
  (02 „Benutzer und Sicherheit"). **Freigabe** über `/admin/tresor`
  (`App\Admin\VaultGrantController`, Recht `admin.vault_grant`,
  `App\Service\Account\UserAdministration`): versiegelt wird mit dem
  entsperrten Tresor **der eigenen Sitzung** des Admins – ohne ihn bietet
  die Seite keinen Knopf an. Das Banner steht auf jeder Seite hinter der
  Anmeldung für Konten mit `admin.vault_grant` (`App\Http\LoginGuard`,
  `partials/freigaben.php`). Die Mail an die Admins (Einladung angenommen,
  Passwort-Reset, Entsperren) geht an jedes aktive Konto mit
  `admin.vault_grant` **und** eigener Freigabe
  (`App\Service\Mail\FreigabeBenachrichtigung`); sie wird nur in die
  Queue gestellt, der Cron verschickt sie.
- **Sperren/Entfernen**: Grant-Zeile löschen. Umgesetzt mit M3-7: Sperren
  setzt den Status `gesperrt`, löscht den Grant und erhöht
  `user.session_epoch` (alle Sitzungen enden sofort); Entsperren führt
  zurück nach `aktiv` **ohne** Grant – das Konto ist wieder „Freigabe
  ausstehend" (eine nie angenommene Einladung wieder `eingeladen`).
  **Entziehen** löscht den Grant und erhöht ebenfalls `session_epoch`, sonst
  behielte eine laufende Sitzung `VK_priv`. Niemand sperrt oder entzieht
  sich selbst, und das letzte aktive Konto mit `admin.users` bzw. mit
  `admin.vault_grant` und eigener Freigabe bleibt erhalten. Wer einem Konto
  nicht mehr traut, das `VK_priv` schon einmal entsperrt hatte, wechselt den
  Tresor-Schlüssel (siehe „Schlüsselrotation“).
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

### Schlüsselrotation (issue #90, E-32)

Neues Tresor-Schlüsselpaar, alte Generation danach außer Dienst – als
Schrittkette auf `/admin/tresor/rotation` (Recht `admin.vault_grant`,
`App\Admin\VaultRotationController`, `App\Service\Tresor\Schluesselrotation`).

- **Zustand** steht nur in `vault` (Migration 042): eine Rotation läuft genau
  dann, wenn mehr als eine Generation kein `retired_at` hat.
  `recovery_confirmed_at` hält fest, dass der Wiederherstellungsschlüssel
  einer Generation bestätigt wurde (Bestand und Installer: gesetzt).
- **Starten** (eine Transaktion): Generation N+1 entsteht; jede Freigabe von N
  wird zusätzlich an dasselbe `U_pub` für N+1 versiegelt – `box_seal` braucht
  kein Passwort, niemand muss sich dafür anmelden. Ab sofort ist N+1 die
  aktuelle Generation: alles Neue (auch die öffentliche Einreichung) wird an
  N+1 versiegelt. Die Sitzung des Admins hält danach einen **Schlüsselbund**
  (`App\Service\Crypto\Vault` mit N als `previous`, in `SessionVault` unter
  demselben `K_s`); `openDataKey()` wählt die Generation nach dem
  Versionsbyte, `sealDataKey()`/`blindIndex()` nehmen immer die neueste.
- **Bestätigen**: der neue Wiederherstellungsschlüssel wird angezeigt (aus dem
  Bund berechnet, solange unbestätigt – ein geschlossener Tab verliert ihn
  nicht), die letzte Gruppe wird eingetippt wie im Installer.
- **Umschlüsseln**: je Schritt ~10 s; jede Zeile in eigener Transaktion
  gesperrt (`FOR UPDATE`), erneut geprüft, DEK mit N geöffnet, an N+1
  versiegelt. Nur `dek_sealed` wird neu geschrieben – die Daten selbst nicht.
  Erfasst werden **alle Tabellen mit Spalte `dek_sealed`** (per
  `information_schema`, auch künftige). Sonderfall `audit_log`: `dek_sealed`
  gehört zur Hash-Kette, der neu versiegelte Schlüssel steht in
  `dek_resealed` (außerhalb der Kette); Leser nehmen
  `COALESCE(dek_resealed, dek_sealed)`. In derselben Transaktion werden die
  Blind Indexes der Zeile unter dem neuen Schlüssel neu berechnet
  (`App\Service\Tresor\Indexpflegen`): `supplier_key` (aus den
  Lieferantendaten, inkl. gelernter Merkmale), `invoice.number_bi`,
  `bank_account.iban_bi`, `bank_transaction.counterparty_bi` und `.dedup_bi`
  (Laufnummer über den alten Schlüssel wiedergefunden, sonst die nächste unter
  beiden Schlüsseln freie). `document.content_bi` wird geleert und vom Job
  `detect_duplicate` neu berechnet; `bank_import.source_bi` wird geleert
  (nur Vorschlag des Kontos beim nächsten Auszug). Ein Test prüft, dass jede
  `_bi`-Spalte eine Pflege hat (Ausnahme `user.email_bi`, Server-Schlüssel).
- **Abschließen** nur, wenn bestätigt und keine Zeile mehr übrig ist, die N
  öffnet (Zeilen, die schon vorher nicht zu öffnen waren, werden gezählt und
  protokolliert, halten aber nicht auf): Freigaben von N gelöscht,
  `retired_at` gesetzt, `session_epoch` **aller** Konten erhöht – die eigene
  Sitzung übernimmt den neuen Wert und behält nur N+1.
- **Während der Rotation** sperrt `App\Http\LoginGuard` `/app` und `/admin`
  (HTML 503, JSON 409; Konten mit `admin.vault_grant` werden zur Rotation
  geleitet); offen bleiben die Rotation und `/app/sicherheit`. Eine Anmeldung
  öffnet beide Freigaben (neueste Pflicht, ältere best effort).
- **Wiederherstellungsschlüssel**: der alte öffnet nach dem Abschluss nichts
  Neues mehr und wird als „abgelöst“ erkannt (`RecoveryKeyProblem::Abgeloest`)
  – er bleibt aber für Backups von vor der Rotation nötig (06 §2) und ist
  deshalb weiter aufzubewahren.
- **Rest-Exposure**: Die DEKs selbst bleiben dieselben (kein Neu-Verschlüsseln
  aller Felder und Blobs, E-32). Wer vor der Rotation den alten `VK_priv`
  **und** eine Kopie der damaligen Datenbank oder eines Backups hatte, liest
  damit weiterhin alles, was damals schon existierte. Außerdem bleibt der
  Original-DEK in `audit_log.dek_sealed` an N versiegelt (Hash-Kette): mit
  dem alten Schlüssel lesbar bleiben die Audit-Details von vor der Rotation.
  Geschützt ist alles, was danach entsteht, und die laufende Datenbank gegen
  jemanden, der nur den alten Schlüssel (nicht die alten Daten) hat.

## 3. Anmeldung

- Login mit **E-Mail + Passwort**. `password_hash()` mit `PASSWORD_ARGON2ID`
  (Fallback `PASSWORD_BCRYPT`, falls Hosting-Check Argon2 verneint).
  Passwort-Hash und KEK-Salt sind getrennt. Umgesetzt mit M3-3
  (`App\Service\Account\LoginService`, Hashing an einer Stelle in
  `App\Service\Account\PasswordHasher`, die auch der Installer nutzt).
- Konten-Zustand beim Login: nur `status = aktiv` und ein `expires_at` in
  der Zukunft kommen durch. Ein Konto ohne `vault_grant` meldet sich
  trotzdem an – es sieht nur nichts, bis ein Admin freigibt (siehe
  „Benutzer-Lebenszyklus").
- Passwortregeln: min. 12 Zeichen, Abgleich gegen eine mitgelieferte Liste
  häufiger Passwörter, keine Zusammensetzungsregeln. Umgesetzt als
  `App\Service\Account\PasswordPolicy` (M3-2), Liste unter
  `app/data/haeufige-passwoerter.txt` (CLAUDE.md §2) – eine eigene
  Zusammenstellung, keine separat lizenzierte Fremdliste (CLAUDE.md §8).
- **Zweiter Faktor** (pro Rolle erzwingbar – die Rollen-Verfeinerung ist
  M3-6, bis dahin ist `user.mfa_required` der ganze Schalter; Default:
  Pflicht für alle). Umgesetzt mit M3-4 (issue #17,
  `App\Service\Account\MfaService`/`MfaEnrollment`, Migration
  `008_mfa.sql`). Der Schritt hängt zwischen „Passwort stimmt" und „Tresor
  entsperrt" (`App\Service\Account\LoginService::attempt()` öffnet den
  Tresor wie zuvor sofort, hält ihn aber nur *pending* –
  `App\Service\Account\PendingLogin`, unten):
  - **TOTP** (Authenticator-App, RFC 6238, `App\Service\Account\Totp`) –
    empfohlen. Der QR-Code entsteht serverseitig in reinem PHP
    (`App\Support\QrCode`, ISO/IEC 18004, Byte-Modus, Fehlerkorrektur M,
    Versionen 1–6 – Version 7 verlangt zusätzlich ein 18-Bit-„Versions"-Muster,
    das diese Klasse bewusst nicht abbildet, um die Prüfoberfläche klein zu
    halten; das Geheimnis steht daneben immer auch als Text). Eine eigene
    Implementierung statt einer Bibliothek, aus demselben Grund wie
    `SmtpTransport` (CLAUDE.md §8): der Protokollumfang ist klein und schon
    exakt spezifiziert. Gegengeprüft gegen eine etablierte Referenz
    (RFC-6238-Testvektoren für TOTP, das PyPI-Paket `qrcode` für den
    QR-Encoder – kein Bestandteil dieser Anwendung, nur zur Verifikation
    während der Entwicklung benutzt).
  - **E-Mail-Code**: 6 Ziffern, 10 min gültig, max. 5 Versuche, nur ein
    HMAC gespeichert (`mfa_email_code.code_hash`, derselbe Blind-Index wie
    `user.email_bi`, Zweck `mfa.email_code`, Wert `Benutzer-ID:Code`).
    Eigenständig wählbare Methode **und** Ausweichweg: wer TOTP eingerichtet
    hat, kann auf der Bestätigungsseite trotzdem einen Code per E-Mail
    anfordern. Das ist eine bewusste Abwägung – wer die Mailbox kontrolliert,
    kommt damit auch an einem TOTP-Konto vorbei.
    **Versand beim Login** (issue #153): ist E-Mail-Code die *eigene* Methode
    des Kontos, erzeugt und versendet `App\App\AuthController::submit()` beim
    Übergang auf `/anmelden/bestaetigen` genau einen Code (sofortiger
    Versandversuch wie bei den anderen Sicherheitsmails, 06 §3) – nie die
    Bestätigungsseite selbst, ein Neuladen verschickt also nichts. Bei TOTP
    geht nichts raus; die Mail bleibt dort der Ausweichweg per Knopf.
    Während das Limit des zweiten Faktors greift (`MfaService::isBlocked()`),
    wird kein Code verschickt; scheitert der Versand, läuft die Anmeldung
    weiter und die Seite weist per Flash auf „Code erneut senden“ hin.
  - 10 Einmal-Backup-Codes (gehasht, gleicher Blind-Index, Zweck
    `mfa.backup_code`) bei Einrichtung und bei „neu erzeugen"
    (`App\Service\Account\BackupCodes`, Crockford-Base32 wie der
    Wiederherstellungsschlüssel).
  - „Dieses Gerät 30 Tage merken" (optional, Token gehasht in
    `trusted_device`, Zweck `trusted_device.token`, pro Nutzer und pro
    Gerät widerrufbar über `/app/sicherheit`; Frist einstellbar über
    `mfa_geraet_merken_tage`, Vorgabe 30 –
    `App\Service\Account\MfaService::rememberDaysFromSettings()`).
  - **Erzwungene Einrichtung**: `mfa_required` ohne konfigurierte Methode
    (`user.mfa_method IS NULL`) ist kein normaler Zustand. Die Anmeldung
    wird trotzdem abgeschlossen (der Tresor öffnet sich), aber
    `App\Http\LoginGuard` leitet jede Seite unter `/app` und `/admin`
    – außer `/app/sicherheit/*` selbst – auf `/app/sicherheit/einrichten`
    um, bis eine Methode bestätigt ist.
  - **Session über den 2FA-Schritt hinweg**: `App\Service\Account\PendingLogin`
    ist das Gegenstück zu `SessionVault` für die Zwischenzeit –
    `$_SESSION['mfa_pending']` trägt Benutzer-ID, `VaultAccess`, gewählte
    Methode und das Rücksprungziel, der entsperrte Tresor (falls vorhanden)
    liegt darin verschlüsselt mit einem Sitzungsschlüssel, der nur im
    eigenen Cookie `__Host-2fa` liegt (10 min gültig, `SameSite=Strict`).
    Ohne dieses Cookie ist auch aus der Sitzungsdatei nichts zu entschlüsseln
    – dieselbe Eigenschaft wie beim Tresor-Cookie selbst.
- **Brute-Force-Schutz**: Rate-Limit je IP und je Konto (übernommener
  `RateLimiter`), generische Fehlermeldungen (keine User-Enumeration), auch
  bei „Passwort vergessen". Seit M3-3: feste Fenster von 15 Minuten,
  20 Fehlversuche je IP, 10 je Konto; nur Fehlversuche zählen, ein Erfolg
  löscht beide Zähler. Der Schlüssel der Zeile ist ein Hash aus Zweck und
  Wert, die IP steht nie im Klartext in der Tabelle. Unbekannte Adresse,
  falsches Passwort, gesperrtes und abgelaufenes Konto liefern **wortgleich
  dieselbe** Meldung, und der Zweig ohne Konto verbrennt einen
  Schein-`password_verify()`, damit auch die Laufzeit nichts verrät. Der
  zweite Faktor (M3-4) hat sein eigenes Zähler-Paar (`App\Service\Account\
  MfaService`, Zwecke `mfa.ip`/`mfa.account`, 20 je IP, 8 je Konto, dasselbe
  15-Minuten-Fenster) – enger als beim Passwort, weil ein zweiter Faktor
  genau dafür da ist, Erraten unpraktikabel zu machen. Der E-Mail-Code trägt
  zusätzlich sein eigenes Fünf-Versuche-Limit in der eigenen Zeile
  (`mfa_email_code.attempts`).
- **Passwort-Reset**: Token 32 Byte, nur Hash gespeichert, 30 min,
  einmalig; beendet alle Sessions des Nutzers. Umgesetzt mit M3-5
  (issue #18, Tabelle `auth_token`, Typ `reset`): Token hex-kodiert im
  Link, gespeichert nur `token_hash` = Blind-Index-HMAC des
  Server-Schlüssels mit Zweck `auth_token.reset`; eine neue Anforderung
  ersetzt den alten Link; verbraucht wird der Link erst, wenn das neue
  Passwort die Regeln erfüllt. Anforderungen zählen je IP (10) und je
  Adresse (3) im 15-Minuten-Fenster – jede Anforderung, nicht nur
  Fehlschläge, weil jede eine Mail auslösen kann. Unbekannte, gesperrte und
  abgelaufene Konten bekommen dieselbe Antwort und keine Mail. Die Mail geht
  wie alle Sicherheitsmails sofort raus (06 §3); die dadurch messbar längere
  Antwort bei existierenden Konten ist bewusst in Kauf genommen. Die Seite
  hinter dem Link sendet `Referrer-Policy: no-referrer`.
- **Link-Basis in Mails** (Schutz vor Host-Header-Injection): das Setting
  `oeffentliche_url` (Admin „Mail") gilt immer; ohne es wird der Host der
  Anfrage nur verwendet, wenn er die Domain der Absenderadresse oder eine
  Subdomain davon ist – sonst geht keine Mail raus, und `app.log` bekommt
  eine Zeile ohne Adresse und Token (`App\Service\Mail\PublicUrl`).
- Sicherheits-Mails an den Nutzer: neues Gerät, Passwort geändert, 2FA
  geändert, Tresor-Freigabe erteilt/entzogen, Tresor per
  Wiederherstellungsschlüssel entsperrt. Umgesetzt für „neues Gerät"
  und „2FA geändert" mit M3-4 (`App\Service\Mail\Mailer::
  sendeSicherheitshinweis()`, Vorlage `app/views/mail/sicherheitshinweis.php`
  – ein fester Satz aus dem Aufrufer, nie Nutzereingabe); „Passwort geändert"
  und „Passwort zurückgesetzt" mit M3-5, „Tresor-Freigabe erteilt/entzogen"
  mit M3-7 (dazu „Zugang gesperrt"), „Tresor per Wiederherstellungsschlüssel
  entsperrt" mit M3-9 – geht an jeden Admin mit `admin.vault_grant` und
  eigener Freigabe, das handelnde Konto eingeschlossen.
- Session-ID-Regeneration bei Login und Rechtewechsel.
- **Bootstrap**: Wie im Vereinskalender legt der Installer den ersten Admin
  an – hier direkt mit E-Mail/Passwort, Tresor-Erzeugung und
  Wiederherstellungsschlüssel im selben Flow. Umgesetzt mit M3-2 (06 §1);
  der Login dazu mit M3-3.
- **Geschützte Bereiche**: `/app/*`, `/admin/*` und die Upload-Routen
  `/api/upload*` liegen hinter `App\Http\LoginGuard`, deklariert je Route in
  `app/src/routes.php`. Die Upload-Routen antworten dort mit 401 JSON statt
  einer Weiterleitung – sie werden aus `fetch()` gefahren. Offen bleiben die
  Startseite, `/anmelden`, `/abmelden`, `/cron` (eigenes Token, 06 §4) und,
  seit M3-4, die Bestätigungsseite des zweiten Faktors
  (`/anmelden/bestaetigen`, `/anmelden/code-senden`, `/anmelden/backup-code`)
  – dort ist noch keine `App\Http\Session` angemeldet, nur ein
  `PendingLogin` unterwegs (siehe oben) – und, seit M3-5, die beiden Seiten
  von „Passwort vergessen" (`/anmelden/passwort-vergessen`,
  `/anmelden/passwort-neu`). Anmelde-, Bestätigungs- und Reset-Seiten sind
  die einzigen öffentlichen Seiten mit einer Session; alle brauchen ein
  CSRF-Token. `App\App\SecurityController`
  (`/app/sicherheit*`) liegt hinter dem Guard wie jede andere Seite in
  `/app` – der Guard nimmt diese Routen nur von seiner eigenen
  Einrichtungspflicht aus, nicht vom Login selbst. **Welcher** angemeldete
  Zugang was darf, entscheidet erst M3-6.

## 4. Rollen und Rechte

Rechte sind ein PHP-Enum `Permission`; Rollen sind Datensätze mit einer
Menge von Rechten (Admin kann Rollen anlegen/anpassen). Mitgelieferte Rollen:

| Recht | Admin | Vorstand | Finanzen | Kassenprüfer | Steuerberater | Vereinsverantwortlicher |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `inbox.view` Posteingang sehen | ✓ | ✓ | ✓ | ✓ | ✓ | eigene Kostenstelle |
| `document.edit` Belege prüfen/bearbeiten/festschreiben | ✓ | – | ✓ | – | – | – |
| `document.submit_internal` intern hochladen | ✓ | ✓ | ✓ | – | – | ✓ |
| `supplier.manage` | ✓ | – | ✓ | – | – | – |
| `bank.import` / `bank.book` (manuelle Buchung, Kasse, Konten pflegen) | ✓ | – | ✓ | – | – | – |
| `bank.view` | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| `matching.edit` Abgleich | ✓ | – | ✓ | – | – | – |
| `report.view` Auswertungen | ✓ | ✓ | ✓ | ✓ | ✓ | eigene Kostenstelle |
| `export.zip` / `export.csv` | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| `archive.import` | ✓ | – | ✓ | – | – | – |
| `audit.view` | ✓ | ✓ | – | ✓ | – | – |
| `admin.users` / `admin.vault_grant` | ✓ | – | – | – | – | – |
| `admin.settings` (KI, Mail, Speicher, Kategorien, Kostenstellen) | ✓ | – | – | – | – | – |
| `admin.system` (Backup, Update, Wartung) | ✓ | – | – | – | – | – |

- Kein Vier-Augen-Prinzip (E-09): wer `document.edit` hat, darf auch
  festschreiben – und die Festschreibung (mit Begründung) wieder aufheben
  (§7); auch die Person, die den Beleg selbst geprüft hat.
- **Externe Rollen** (Kassenprüfer, Steuerberater): Konto mit Pflicht-
  Ablaufdatum (Default 60 Tage, verlängerbar), 2FA immer Pflicht,
  ausschließlich lesend. Beim Anlegen wählbar: Zugriff auf Zeitraum
  beschränken (z. B. nur Geschäftsjahr 2026) – Filter im Repository
  erzwungen.
- „Vereinsverantwortlicher" (z. B. Jugendleiter, Abteilungsleiter) ist auf
  zugewiesene Kostenstellen beschränkt – Filter im Repository erzwungen,
  nicht in der View.
- Adminseite `/admin/*` nur mit mindestens einem `admin.*`-Recht erreichbar.

### Umsetzung (M3-6, issue #19)

- **Rechte:** `App\Domain\Permission` (Werte wie in der Tabelle, zugleich das
  Speicherformat in `role.permissions`). Je Recht speichert eine Rolle die
  Reichweite `alle` oder `kostenstelle` (`App\Domain\PermissionScope`);
  „eigene Kostenstelle" gibt es nur für `inbox.view` und `report.view`.
- **Mitgelieferte Rollen:** `App\Domain\SystemRole` (Matrix oben =
  `standardRechte()` = Seed in Migration 010, ein Test hält alle drei
  zusammen). Systemrollen sind nicht umbenennbar und nicht löschbar; ihre
  Rechte sind anpassbar – **außer Admin: hat immer alle Rechte**, auch
  künftig hinzukommende, egal was in der Zeile steht. Kassenprüfer und
  Steuerberater sind *extern* (`role.is_external`).
- **Mehrere Rollen je Konto:** Rechte = Vereinigung; gewährt mehr als eine
  Rolle dasselbe Recht, gilt die weitere Reichweite
  (`App\Domain\Berechtigungen`, pro Request frisch geladen – Rollenentzug
  wirkt beim nächsten Klick).
- **Rollen-CRUD:** `/admin/rollen` (Recht `admin.users`), Regeln in
  `App\Service\Account\RoleService`: Name Pflicht/eindeutig; externe
  Rollen nur lesende Rechte (`Permission::istLesend()`: `inbox.view`,
  `bank.view`, `report.view`, `export.*`, `audit.view`); eine noch
  zugewiesene Rolle wird nicht gelöscht und wechselt nicht zwischen
  intern/extern; `admin.users` kann der letzten Rolle, über die es jemand
  hat, nicht entzogen werden.
- **Zuweisung** (`App\Service\Account\AccessAssignment`, Oberfläche mit
  M3-7 unter `/admin/benutzer`): externe Rollen nicht mit internen
  kombinierbar; externes Konto → `expires_at` Pflicht (Default heute + 60
  Tage, per `verlaengern()` verschiebbar) und `mfa_required = 1`; internes
  Konto → Ablaufdatum **optional** (seit M3-7, z. B. befristete Helfer;
  leer = unbefristet). Die Oberfläche fragt den letzten Zugangstag ab und
  speichert ihn als 23:59:59 dieses Tages.
  Der letzte Verwalter (`admin.users`) behält sein Recht. Das Ablaufdatum
  greift über `User::mayLogIn()` bei Login **und** bei jedem Request
  (`LoginGuard`).
- **Scopes:** `App\Domain\Zugriffsbereich` = Kostenstellen
  (`user_cost_center`, nur für Rechte mit Reichweite `kostenstelle`) +
  Zeitraum (`user_scope`, beide Grenzen inklusive, gilt für alle Rechte des
  Kontos). Repositories holen ihn über
  `Berechtigungen::zugriffsbereich(Permission)` und filtern mit
  `sqlBedingung($datumsSpalte, $kostenstellenSpalte)` **in SQL**;
  `erlaubt()` prüft eine einzeln geladene Zeile (Detailseite per ID). Ein
  Kostenstellen-Scope ohne zugewiesene Kostenstelle sieht nichts, eine
  Zeile ohne Kostenstelle liegt außerhalb jedes Kostenstellen-Scopes.
- **Deklaration je Route:** `Router::get/post($muster, Zugriff, $handler)` –
  `App\Http\Zugriff` ist Pflichtargument: `oeffentlich()`, `cron()`,
  `angemeldet()`, `recht(Permission)`, `adminBereich()` (irgendein
  `admin.*`), optional `->alsApi()` (401/403 als JSON). `app/src/routes.php`
  leitet aus demselben Wert den Guard-Wrapper ab
  (`LoginGuard::pruefe()`), Deklaration und Prüfung können also nicht
  auseinanderlaufen. Fehlt das Recht: **403** (Fehlerseite bzw. JSON), nicht
  die Anmeldeseite. Zusätzlich gilt für jeden Pfad unter `/admin` die
  Untergrenze „mindestens ein `admin.*`-Recht". `/admin` leitet auf die
  erste Admin-Seite, die das Konto öffnen darf.
- **Navigation** wird serverseitig nach Rechten gefiltert (`NavItem::$recht`)
  – reine Höflichkeit, die Route prüft selbst.
- Bestehende Routen: `/app`, `/app/sicherheit*` = angemeldet;
  `/api/upload*` = `document.submit_internal`; `/admin/designsystem` =
  `admin.*`; `/admin/speicher*`, `/admin/mail*`, `/admin/kostenstellen*`
  (M4-1) = `admin.settings`; `/admin/update*`, `/admin/wartung/aufheben`,
  `/admin/systemcheck` (M3-10) = `admin.system`; `/admin/rollen*`, `/admin/benutzer*` = `admin.users`;
  `/admin/tresor*` = `admin.vault_grant` (M3-7); `/anmelden/einladung` =
  öffentlich (M3-7); `/app/audit*` = `audit.view` (M3-8);
  `/app/posteingang`, `/app/posteingang/{id}`,
  `/app/posteingang/{id}/datei/{blob}` = `inbox.view`,
  `/app/posteingang/{id}/(annehmen|ablehnen|wiedervorlage|kostenstelle)` =
  `document.edit` (M4-5). Der Posteingang ist der erste echte Aufrufer von
  `sqlBedingung()`: `document.cost_center_id` / `document.created_at`
  (`App\Repository\DocumentRepository::inbox()`/`inboxItem()`); eine ID
  außerhalb des Scopes antwortet 404 wie eine unbekannte.

## 5. Öffentliche Einreichung – Schutz

- **Stand M4-2** (issue #24): `/einreichen` hat keine Session; Credential ist
  ein zustandsloses, signiertes Formular-Token
  (`App\Service\Submission\FormToken`, HMAC unter einem aus dem
  Server-Schlüssel abgeleiteten Kontext-Schlüssel, 24 h gültig) – `GET
  /einreichen` stellt es aus, jede weitere Anfrage trägt es im Header
  `X-CSRF-Token` zurück.
- **Stand M4-3** (issue #25): Kein Einreich-Code, kein Captcha (E-14) –
  stattdessen mehrere unsichtbare Schranken, alle zustandslos an das
  Formular-Token gebunden (`App\Service\Submission\Spamschutz`):
  - **Proof-of-Work** (`App\Service\Submission\ProofOfWork`, ALTCHA-Prinzip,
    selbst gehostet, kein Drittanbieter, kein Cookie-Banner nötig): aus dem
    Token-Nonce wird deterministisch eine geheime Zahl abgeleitet, die der
    Browser nur durch Ausprobieren findet (`salt`/`challenge`/`max` in
    Daten-Attributen auf `#einreichen`, gelöst im Hintergrund von
    `public/js/einreichen.js`, sobald die Seite lädt). Die Lösung reist im
    Header `X-Pow-Loesung` mit jeder Anfrage; geprüft wird sie serverseitig
    ohne jede Speicherung – sie muss zu genau diesem Token passen.
  - **Honeypot-Feld** (`webseite`, für Menschen unsichtbar per CSS,
    `aria-hidden`, `tabindex="-1"`) und **Mindest-Ausfülldauer** (< 5 s
    zwischen Token-Ausstellung und Absenden) gelten für den finalen Submit;
    ein Treffer zählt sofort gegen das IP-Limit.
  - **Rate-Limit** je IP und global, pro Stunde (`App\Service\RateLimiter`,
    `RateLimiter::SUBMISSION_WINDOW_SECONDS`) – eigene Zähler für den Submit
    und für jeden einzelnen Seiten-Upload (dessen Budget sich aus dem
    Submit-Limit mal der Seitenobergrenze ergibt, da eine Einreichung aus
    mehreren Uploads besteht). Ein Feld-Fehler (z. B. fehlender Name) kostet
    kein Kontingent; nur eine tatsächlich abgeschlossene Einreichung zählt.
  - **Größen- und Seitenlimits**: je Datei, je Einreichung insgesamt und
    Seitenzahl je Einreichung – alle vier zusammen mit dem Rate-Limit
    einstellbar unter `/admin/einreichung` (`admin.settings`,
    `App\Admin\SubmissionSettingsController`,
    `App\Service\Submission\EinreichungsEinstellungen`, Settings-Keys
    `einreichung_*`, siehe 02 §Betrieb). Das Dateilimit kann
    `App\Service\Upload\UploadService::MAX_FILE_BYTES` nur unterschreiten,
    nie überschreiten.
  - Dieselbe Seite pausiert auch die Einreichung insgesamt
    (`einreichung_pausiert`): `/einreichen` zeigt dann nur einen Hinweis,
    Absenden und Upload antworten mit 503 – für den Fall, dass trotzdem
    Spam auftritt.
- Dateiprüfung serverseitig über Magic Bytes (JPEG, PNG, HEIC als Original
  (im Browser zusätzlich nach JPEG gewandelt, 03 §1), PDF; seit M7-4 E-Rechnungs-XML nur
  mit CII-/UBL-Wurzel und ohne DOCTYPE, ausgeliefert nur als Download –
  03 §3).
- Einreicher erhält Referenznummer + optional Bestätigungsmail (ohne
  Beleginhalt).
- IP wird nur als Hash für das Rate-Limit genutzt (`App\Service\RateLimiter`,
  derselbe Mechanismus wie bei der Anmeldung, 01 §3) und verworfen, sobald
  ihr Zeitfenster abgelaufen ist – keine separate, längere Aufbewahrung.

## 6. Audit-Log

- Append-only Tabelle `audit_log`; jede Zeile enthält `hash =
  SHA256(prev_hash || kanonisches JSON der Zeile)`. Details verschlüsselt
  (Tresor), Aktion/Entität/Zeit/Nutzer im Klartext.
- Geloggt: Login/Logout/Fehlversuche, 2FA-/Passwort-Änderungen,
  Freigaben, jede Änderung an Beleg/Lieferant/Buchung/Abgleich,
  Festschreibung, Export, Import, Einstellungsänderungen.
- Seite „Integrität prüfen" rechnet die Kette nach.

### Umsetzung (M3-8, issue #21)

- **Ort:** `/app/audit` (Liste mit Filtern) und `/app/audit/pruefen`
  (Schrittkette der Prüfung), beide Recht `audit.view`. Bewusst in `/app`,
  nicht `/admin`: `audit.view` haben laut §4 auch Vorstand und
  Kassenprüfer, `/admin` verlangt aber ein `admin.*`-Recht.
- **Schreiben:** `App\Service\Audit\AuditLog::record()` – aufgerufen von
  den Controllern nach erfolgreicher Aktion (dort liegen Akteur und IP).
  Jede Zeile ist **ein** `INSERT`, nie ein `UPDATE`
  (`App\Repository\AuditLogRepository` hat keine Änderungs- oder
  Löschmethode). Die ID ist Kopf-ID + 1 und wird vor dem Insert bestimmt,
  weil AAD und Hash sie brauchen; kollidieren zwei Schreiber auf dem
  Primärschlüssel, liest der Verlierer den Kopf neu (bis zu 5 Versuche) –
  ohne `SELECT … FOR UPDATE` (Hosting-Befund #98).
- **Kette:** `App\Service\Audit\AuditChain`. Erste Zeile: `prev_hash` =
  32 Nullbytes. Kanonisches JSON mit fester Schlüsselreihenfolge `id, ts,
  user_id, action, entity, entity_id, ip_hash (hex), details_enc (base64),
  dek_sealed (base64)`, `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE` –
  ein Speicherformat, nie ändern. Die Kette deckt nur Gespeichertes
  (Chiffrat) ab; die Prüfung braucht daher keinen Tresor. Sie erkennt
  geänderte (Inhalt), gelöschte/umsortierte (Lücke in den IDs) und
  eingeschobene/vertauschte Zeilen (Verkettung).
- **Grenze der Kette:** Am Ende abgeschnittene Zeilen oder eine ab einem
  Punkt komplett neu berechnete Kette fallen allein nicht auf. Deshalb
  zeigt die Prüfung am Ende den Hash des letzten Eintrags als
  **Kontrollwert**; wer ihn außerhalb der Datenbank notiert (z. B. im
  Kassenprüfungsbericht), erkennt bei der nächsten Prüfung beides.
- **Details** (Tresor, AAD `audit_log|id|details_enc`): nur kleine
  skalare Fakten – Grund, Rollen-/Kostenstellen-IDs, Datumsgrenzen, Namen
  geänderter Felder. **Nie** Passwörter, Tokens, Codes, Mail-Adressen,
  Namen von Personen oder Beträge. Lesbar nur in einer Sitzung mit
  entsperrtem Tresor; sonst zeigt die Liste „verschlüsselt".
- **IP:** nur als `ip_hash` = HMAC-SHA256 mit dem Server-Schlüssel (Zweck
  `audit.ip`), Teil der Kette und dauerhaft gespeichert – die Liste zeigt
  eine gekürzte Kennung („gleiche Adresse wie …"), nie die Adresse. Nicht
  per Durchprobieren aller IPv4-Adressen umkehrbar, weil der Schlüssel
  nicht in der DB liegt. (Die 7-Tage-Regel aus §5 gilt für den
  Rate-Limit-Hash der Einreichung, nicht hierfür.)
- **Fehlversuch beim Login:** ohne Konto-ID und ohne Adresse – das Log
  darf nicht verraten, was die generische Fehlermeldung verschweigt.
- **Aktionen** (`App\Domain\AuditAction`, Wert = gespeicherter
  `action`-String, nie umbenennen): Anmeldung (Erfolg, Fehlschlag, zweiter
  Faktor fehlgeschlagen), Abmeldung, 2FA eingerichtet (TOTP/E-Mail),
  Backup-Codes neu, gemerktes Gerät entfernt, Passwort geändert,
  Passwort-Reset angefordert/abgeschlossen, Benutzer eingeladen/Einladung
  erneut/angenommen/geändert/gesperrt/entsperrt, Tresor freigegeben/
  entzogen/per Wiederherstellungsschlüssel entsperrt (auch der
  Fehlschlag), Rolle angelegt/geändert/gelöscht, Kostenstelle angelegt/
  geändert/gelöscht (M4-1), Lieferant angelegt/geändert/gelöscht (M6-2,
  Details nur Feldnamen), Beleg festgeschrieben / Festschreibung aufgehoben
  (M6-4, Details nur der Grund der Aufhebung),
  Mail-/Speicher-/Update-Kanal-Einstellungen,
  Update eingespielt/zurückgerollt, Wartung aufgehoben. Belege, Lieferanten,
  Buchungen, Abgleich, Festschreibung, Export und Import ergänzen ihre
  Aktionen, wenn es sie gibt (ab M4).
- **Prüfung:** `public/js/audit.js` ruft `/app/audit/pruefen` je 2000
  Zeilen auf; jeder Schritt liest seinen Startwert aus der Zeile, bei der
  der vorige endete (zustandslos, jeder Request kurz).

## 7. Festschreibung

- Ein geprüfter Beleg wird festgeschrieben (`locked_at`).
  Danach sind Betrag, Datum, Lieferant, Dokumente unveränderlich; Korrektur
  nur über „Festschreibung aufheben" (Recht + Pflicht-Begründung, Audit).

### Umsetzung (M6-4, issue #38)

- **Ablauf:** `App\Service\Invoice\Festschreibung`, aufgerufen von der
  Prüfansicht (`POST /app/belege/pruefen/{id}/festschreiben` und
  `…/festschreibung-aufheben`, Recht `document.edit`, Scope wie die
  Prüfansicht: außerhalb 404, CSRF, Tresor entsperrt). `festschreiben`:
  `geprueft` → `festgeschrieben` und `invoice.locked_by/locked_at`
  (Migration 020), `festschreibung-aufheben`: `festgeschrieben` →
  `in_pruefung`, `locked_*` **und** `checked_*` werden geleert. Beides in
  **einer** Transaktion, die den Beleg zuerst im gelesenen Status sperrt
  (`SELECT … FOR UPDATE`) und den Audit-Eintrag mitschreibt – zwei gleichzeitige
  Entscheidungen gewinnen nicht beide. Status und `locked_at` stimmen immer
  überein (`festgeschrieben` ⇔ `locked_at` gesetzt).
- **Kein Vier-Augen-Prinzip (E-09):** dasselbe Recht `document.edit` für
  Festschreiben und Aufheben, auch für die Person, die den Beleg geprüft hat.
  Die Kontrolle liegt in Pflicht-Begründung und Audit-Log, nicht in einem
  zweiten Recht.
- **Was unveränderlich ist:** der **ganze** Beleg – alle Angaben der
  Prüfansicht (Beträge, Datum, Nummer, Lieferant/Zahler, Kategorie,
  Kostenstelle, Richtung, Belegart …), die Kostenstelle des Dokuments und die
  Dokumente (Bilder/PDFs). Die Prüfansicht zeigt ihn nur lesbar, der
  Posteingang bietet nichts mehr an.
- **Serverseitig erzwungen, zweifach:** (1) die Services lehnen laut ab
  (`Pruefung::speichern`, `Posteingang::kostenstelle`/`entscheiden`, die
  Statusübergänge in `DocumentStatus`; `pruefbar()` schließt `festgeschrieben`
  aus); (2) die Repositories fassen eine festgeschriebene Zeile gar nicht erst
  an – `InvoiceRepository::update()`/`setzeGeprueft()` mit `locked_at IS NULL`,
  `DocumentRepository::setzeKostenstelle()`/`setzePdfBlob()` mit
  `status <> 'festgeschrieben'` in der SQL-Bedingung (stille No-Ops). Wer
  künftig Belege schreibt (Lieferanten zusammenführen M6-5, Duplikaterkennung
  M6-6, KI-Auslesen M7, Abgleich M10), muss das einhalten und einen
  festgeschriebenen Beleg als unveränderlich behandeln. Das Zusammenführen
  (M6-5) hängt nur nicht festgeschriebene Belege um
  (`InvoiceRepository::haengeLieferantUm()` mit `locked_at IS NULL`); ein
  festgeschriebener behält den zusammengeführten Lieferanten und wird über
  dessen `merged_into` aufgelöst (03 §7 „Stand M6-5“). Die Duplikaterkennung
  (M6-6) setzt `document.content_bi` einmalig auch bei festgeschriebenen
  Belegen (`DocumentRepository::setzeContentBi()` mit `content_bi IS NULL`) –
  ein aus den unveränderlichen Originalen abgeleiteter Index, keine Angabe des
  Belegs; nur so fällt eine erneute Einreichung eines längst festgeschriebenen
  Belegs auf. „Als Duplikat verwerfen“ und „Bewusst behalten“ lehnen einen
  festgeschriebenen Beleg laut ab (02 „Statusmodell“, Stand M6-6).
- **Korrekturweg:** „Festschreibung aufheben" mit **Pflicht-Begründung**
  (höchstens 2000 Zeichen). Der Beleg ist danach wieder `in_pruefung`, steht
  in der Warteschlange, wird in der Prüfansicht korrigiert, erneut „Geprüft"
  und festgeschrieben. Den Grund trägt der Audit-Eintrag
  `beleg.festschreibung_aufgehoben` (Details im Tresor, lesbar für `audit.view`
  mit entsperrtem Tresor); `beleg.festgeschrieben` hat keine Details. Einen
  stillen Weg (direktes Ändern, Statuswechsel ohne Grund) gibt es nicht.
- **Oberfläche:** Prüfansicht (Button „Festschreiben" bei `geprueft`,
  „Festschreibung aufheben" mit Begründung bei `festgeschrieben`), Abschnitt
  „Geprüft – bereit zum Festschreiben" auf `/app/belege/pruefen`, im
  Posteingang-Detail ein Link auf die Prüfansicht für geprüfte und
  festgeschriebene Belege. Sammel-Festschreiben gibt es noch nicht.
- **Grenzen:** Später verknüpfte Daten (Abgleich Beleg ↔ Buchung, M10) sind
  eigene Tabellen; wie sich das Aufheben auf bestehende Zuordnungen auswirkt,
  klärt M10.

## 8. Integritätscheck Code

- Admin-Seite vergleicht die Dateien in `current/` gegen die
  `checksums`-Liste des installierten Releases (vom Updater gespeichert) und
  zeigt Abweichungen. Kein Schutz gegen einen versierten Angreifer, aber
  erkennt plumpe Manipulationen.

## Pflicht-Tests

Krypto-Roundtrips (Feld, Blob, Sealed-DEK), AAD-Vertauschung schlägt fehl,
Blind Index deterministisch; Argon2id-Profil (issue #100): Vorgabe ist
MODERATE, eine Umhüllung des älteren INTERACTIVE-Profils öffnet weiter
(auch ohne `kdf_alg` geschrieben, wie vom vorigen Release), wird beim Login
angehoben und öffnet danach erneut, ein dazwischen festgeschriebener
Passwortwechsel wird nicht überschrieben, unbekannter Algorithmus
abgelehnt; Benutzer-Lebenszyklus (Einladung → Freigabe →
Passwort ändern → Reset → erneute Freigabe) inkl. „ohne Grant kein
Entschlüsseln"; Wiederherstellungsschlüssel-Flow; Session ohne Cookie
`__Host-vk` kann nicht entschlüsseln; TOTP (RFC-6238-Testvektoren),
E-Mail-Code (Ablauf, Versuchslimit, genau ein Versand beim Login nur bei
E-Mail-Methode), Reset-Token (einmalig, Ablauf);
Rechte-Matrix als DataProvider über alle Routen (jede Route hat eine
Rechte-Deklaration – Test schlägt an, wenn eine Route keine hat);
Kostenstellen-Scope; Zeitraum-Scope und Ablauf externer Konten
(Kassenprüfer/Steuerberater); Audit-Hash-Kette inkl.
Manipulationserkennung; CSP-Compliance (übernommen). Öffentliche
Einreichung (issue #25/M4-3): Proof-of-Work löst und prüft dieselbe Zahl
zum selben Token, falsche/fehlende Lösung abgelehnt; Rate-Limit je IP und
global für Submit und Upload greift nach dem konfigurierten Limit und
lässt Feld-Fehler unangetastet; Honeypot und Mindest-Ausfülldauer
abgelehnt; Größen- und Seitenlimits durchgesetzt; Pause sperrt Formular
und Upload (503) und zeigt auf `/einreichen` nur den Hinweis.
Festschreibung (issue #38/M6-4): nur ein geprüfter Beleg lässt sich
festschreiben, zweimal und veraltet wird abgelehnt (genau ein Audit-Eintrag);
Recht/Scope/CSRF/Tresor; ein festgeschriebener Beleg ist über Prüfansicht,
Posteingang und Repositories unveränderlich; Aufheben nur mit Grund, danach
`in_pruefung` mit geleerten `locked_*`/`checked_*`, Grund nur im
Audit-Chiffrat, erneutes Prüfen und Festschreiben möglich; Status und
`locked_at` stimmen überein; Statusmatrix (`festgeschrieben → in_pruefung`).
