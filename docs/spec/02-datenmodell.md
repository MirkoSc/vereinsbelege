# 02 – Datenmodell

Konventionen: `id` BIGINT UNSIGNED AUTO_INCREMENT; `*_enc` = verschlüsselt
(VARBINARY/BLOB); `*_bi` = Blind Index (BINARY(32), indiziert);
`dek_sealed` = versiegelter Datenschlüssel der Zeile; Geld in Cent
(innerhalb des verschlüsselten Werts als Integer). `T` = Tresor,
`S` = Server-Schlüssel, `–` = Klartext.

Die Tabellen sind ein Entwurf; die jeweilige Migration darf Details
anpassen, muss dann aber diese Datei im selben PR nachziehen.

## Benutzer & Sicherheit

| Tabelle | Spalten (Auszug) | Verschl. |
|---|---|---|
| `user` | email_enc, email_bi UNIQUE, display_name_enc, password_hash, status (`eingeladen`/`aktiv`/`gesperrt`), expires_at NULL, mfa_required, mfa_method NULL (`totp`/`email`, Migration 008, M3-4), session_epoch (Migration 009, M3-5), created_at, last_login_at (Migration 006, M3-2) | S |
| `role` | system_key NULL UNIQUE (`admin`/`vorstand`/… für die sechs mitgelieferten), name UNIQUE, is_system, is_external, permissions JSON `{recht: "alle"\|"kostenstelle"}`, created_at (Migration 010, M3-6) | – |
| `user_role` | user_id, role_id – PK beide; Rolle `ON DELETE RESTRICT` (Migration 010, M3-6) | – |
| `user_cost_center` | user_id, cost_center_id (Scope „Vereinsverantwortlicher"; Migration 010, M3-6) | – |
| `user_scope` | user_id PK, period_from NULL, period_to NULL (Zeitraum-Scope externer Konten, Grenzen inklusive; Migration 010, M3-6) | – |
| `user_key` | user_id, public_key, wrapped_private_key, kdf_salt, kdf_ops, kdf_mem (Migration 006, M3-2) | – (selbst gewrappt) |
| `vault` | version, public_key, created_at (Migration 004, M2-4) | – |
| `vault_grant` | user_id, vault_version, sealed_private_key, granted_by, granted_at (Migration 006, M3-2) | – (versiegelt) |
| `mfa_totp` | user_id, secret_enc, confirmed_at, created_at (Migration 008, M3-4) | S |
| `mfa_email_code` | user_id, code_hash, expires_at, attempts, created_at (Migration 008, M3-4) | – |
| `mfa_backup_code` | id, user_id, code_hash, used_at NULL, created_at (Migration 008, M3-4) | – |
| `trusted_device` | id, user_id, token_hash UNIQUE, label, created_at, last_used_at NULL, expires_at (Migration 008, M3-4) | – |
| `auth_token` | id, user_id, typ (`reset`/`invite`), token_hash UNIQUE, expires_at, used_at NULL, created_at (Migration 009, M3-5; `invite` seit M3-7) | – |
| `rate_limit` | key_hash (PK, SHA-256 über `zweck:wert`), window_start, count (übernommen; Migration 007, M3-3) – feste Fenster, eine Zeile je aktivem Schlüssel statt einer je Versuch; der Wert (IP, Konto-Blindindex) steht nie im Klartext darin | – |
| `audit_log` | id (vom Schreiber gesetzt, kein AUTO_INCREMENT), ts, user_id NULL (kein FK), action, entity NULL, entity_id NULL, ip_hash NULL (HMAC, Server-Schlüssel), details_enc NULL, dek_sealed NULL, prev_hash, hash (Migration 011, M3-8) | T (details) |

- `vault` stand als einzige dieser Tabellen schon vor `user`/`user_key`/
  `vault_grant` (Migration 004, M2-4): der Chunk-Upload ist der erste
  Schreiber, der einen Datenschlüssel versiegeln muss, und dafür braucht er
  nur `public_key`. Die Zeile schreibt seit M3-2 der Installer
  (`App\Installer\FirstAdminSetup`, 06 §1); vor der ersten Installation ist
  die Tabelle leer und der Upload-Abschluss antwortet 503 (03 §4).
- `role`/`user_role` kamen mit M3-6 (01 §4, Migration 010): sie legt die
  sechs mitgelieferten Rollen an und weist allen bis dahin bestehenden
  Konten (= dem Installer-Admin) die Admin-Rolle zu; seitdem tut das
  `App\Installer\FirstAdminSetup` beim Anlegen selbst. `is_external` ist
  eine Ergänzung gegenüber dem ersten Entwurf (Pflicht-Ablaufdatum, 2FA,
  nur lesend – auch für vereinseigene externe Rollen), `system_key` der
  stabile Schlüssel, über den der Code Systemrollen findet – der Name ist
  Anzeige. Rechte werden pro Request aus diesen Tabellen gelesen
  (`App\Repository\UserAccessRepository::berechtigungen()`).
- `audit_log` (Migration 011, M3-8, 01 §6): append-only, genau ein
  `INSERT` je Zeile. Die `id` setzt der Schreiber (Kopf + 1), weil AAD der
  Details und Hash sie vor dem Insert brauchen; ein Wettlauf zweier
  Schreiber endet als Primärschlüssel-Konflikt und wird wiederholt.
  `user_id` ohne Fremdschlüssel – das Log überlebt die Konten, die es
  nennt. `ip_hash` ist ein HMAC mit dem Server-Schlüssel (nicht der
  unverschlüsselte SHA-256 von `rate_limit`) und bleibt, weil er Teil der
  Hash-Kette ist. Ohne Details sind `details_enc`/`dek_sealed` NULL.
- `cost_center` legt ebenfalls schon Migration 010 an, weil
  `user_cost_center` darauf verweist; die Pflegeseite kommt mit M4-1.
- `user.last_login_at` und `user.password_hash` schreibt seit M3-3 der Login
  (`App\Service\Account\LoginService`): den Hash nur dann neu, wenn
  `password_needs_rehash()` angehobene Kostenfaktoren meldet – der Klartext
  liegt in genau diesem Moment ohnehin vor. `user_key` bleibt dabei
  unberührt: das KEK-Salt ist bewusst vom Passwort-Hash getrennt (01 §3),
  die Umhüllung wird erst beim echten Passwortwechsel erneuert (M3-5). Seit
  M3-4 (issue #17) ist „Passwort stimmt" nicht mehr dasselbe wie „Anmeldung
  abgeschlossen" – `last_login_at` wird deshalb nicht mehr in `attempt()`
  gesetzt, sondern in `LoginService::registerSuccess()`, das
  `App\App\AuthController` bzw. `App\App\MfaController` erst aufrufen, wenn
  kein zweiter Faktor mehr aussteht.
- `user_key` wird seit M3-5 bei jedem Passwortwechsel ersetzt
  (`UserKeyRepository::replace()`): nach „Passwort ändern" dasselbe
  Schlüsselpaar unter neuer Umhüllung (frisches Salt, ggf. angehobene
  KDF-Parameter), nach „Passwort vergessen" ein neues Paar – dann sind auch
  alle `vault_grant`-Zeilen des Kontos gelöscht (01 §2). Beides erhöht
  `user.session_epoch`, den Zähler, gegen den `App\Http\LoginGuard` jede
  Sitzung prüft (01 §2 „Sitzungen beenden").
- `auth_token.token_hash` ist derselbe Blind-Index-HMAC wie die
  `*_hash`-Spalten unten, Zweck `auth_token.reset` bzw. `auth_token.invite`
  – ohne Benutzer-ID im Wert: das 32-Byte-Token ist allein eindeutig und
  muss ohne Konto nachgeschlagen werden (`App\Service\Account\
  PasswordReset`, `App\Service\Account\Invitation`).
- Ein eingeladenes Konto (M3-7) ist eine `user`-Zeile mit Status
  `eingeladen`, leerem `password_hash` und **ohne** `user_key` – das
  Schlüsselpaar entsteht erst, wenn die eingeladene Person ihr Passwort
  setzt. „Freigabe ausstehend" ist kein eigener Status, sondern abgeleitet:
  `aktiv`, nicht abgelaufen, `user_key` vorhanden, keine `vault_grant`-Zeile
  der aktuellen Tresor-Version (`VaultGrantRepository::pendingUserIds()`).
  `vault_grant.granted_by` nennt den freigebenden Admin.
- Die `*_hash`-Spalten von `mfa_email_code`, `mfa_backup_code` und
  `trusted_device` sind kein neues Primitiv: derselbe Blind-Index-HMAC wie
  `user.email_bi` (`App\Service\Crypto\ServerCrypto::blindIndex()`), mit
  eigenem Zweck (`mfa.email_code`, `mfa.backup_code`,
  `trusted_device.token`) und der Benutzer-ID im gehashten Wert, damit
  derselbe Code oder Token bei zwei Konten nie denselben Hash ergibt
  (`App\Service\Account\MfaService`).

## Betrieb

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `setting` | name (PK), value, updated_at – nur nicht-sensible Einstellungen; `name` statt `key`, weil KEY in MySQL/MariaDB reserviert ist. Erster Eintrag: `update_kanal` (M1-2); Cron (M1-5): `cron_lock_until` (Sperre, Ablaufzeitpunkt), `cron_letztes_aufraeumen`, `cron_aufraeum_intervall_s`; Speicher (M2-3): `speicher_backend` (`fs`/`db`); Mail (M3-1, 06 §3): `mail_transport` (`smtp`/`php_mail`), `mail_smtp_host`, `mail_smtp_port`, `mail_smtp_sicherheit` (`implizit`/`starttls`/`keine`), `mail_smtp_benutzer`, `mail_smtp_passwort_enc` (Base64 von `ServerCrypto::encrypt()` – die einzige Spalte hier, die kein Klartext ist), `mail_absender`, `mail_antwort_an`, `mail_vereinsname`; Anmeldung (M3-3, 01 §2): `session_idle_timeout_s` (Vorgabe 1800), `session_absolute_timeout_s` (Vorgabe 43200) – ohne Zeile gilt die Vorgabe, ein unbrauchbarer Wert ebenso; zweiter Faktor (M3-4, 01 §3): `mfa_geraet_merken_tage` (Vorgabe 30, dieselbe Fallback-Regel); Links in Mails (M3-5, 01 §3): `oeffentliche_url` (leer = Host der Anfrage nur bei Absender-Domain); öffentliche Einreichung (M4-3, issue #25, 01 §5): `einreichung_pausiert` (`0`/`1`), `einreichung_limit_ip_stunde` (Vorgabe 10), `einreichung_limit_gesamt_stunde` (Vorgabe 60), `einreichung_max_seiten` (Vorgabe 20), `einreichung_max_datei_mb` (Vorgabe 10, gedeckelt auf `UploadService::MAX_FILE_BYTES`), `einreichung_max_einreichung_mb` (Vorgabe 50) – dieselbe Fallback-Regel wie oben | – (außer `mail_smtp_passwort_enc`: S) |
| `mail_queue` | to_enc, subject_enc, body_enc, status (`offen`/`laeuft`/`gesendet`/`fehler`), attempts, next_try_at, last_error – keine eigene Sperrspalte: ein Claim setzt `laeuft` und schiebt `next_try_at` als Platzhalter vor, bis der Versuch den echten Termin schreibt (06 §3) | S |
| `ai_provider` | name, base_url, api_key_enc, model, caps JSON (`vision`, `json_schema`, `max_images`, `max_tokens`), timeout_s, active, is_default | S (api_key) |
| `job` | typ, ref_type, ref_id, executor (`session`/`browser`/`worker`), status (`offen`/`laeuft`/`fertig`/`fehler`/`uebersprungen`), step, state JSON, attempts, last_error, locked_by, locked_until, created_at, updated_at (M1-5; `executor`/`status` als VARCHAR, die PHP-Enums sind maßgeblich; `last_error` nur die Exception-Klasse) | – (state ohne Klartext-Fachdaten) |
| `worker` | name, public_key, key_fingerprint, secret_hash, status (`gekoppelt`/`aktiv`/`gesperrt`), protocol_version, app_version, capabilities JSON, last_heartbeat_at, config_sealed (an W_pub), config_version | – |
| `worker_pairing` | code_hash, expires_at, used_at | – |
| `worker_grant` | worker_id, blob_id, sealed_dek (an W_pub), job_id, created_at – wird nach Abschluss gelöscht | – (versiegelt) |
| `worker_context` | worker_id, document_id, sealed_payload (an W_pub: nur Freitext + Kostenstellen-Hinweis) – wird nach Abschluss gelöscht | – (versiegelt) |
| `worker_nonce` | worker_id, nonce, seen_at (Replay-Schutz) | – |
| `schema_version` | übernommen | – |

## Dateien

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `file_blob` | storage (`db`/`fs`), fs_name NULL (Zufalls-ID), size (Klartextlänge), cipher_sha256 NULL, dek_sealed, header (Version + secretstream), meta_enc (MIME, Originalname, Pixelmaße, Seitenzahl), created_at | T |
| `file_blob_chunk` | blob_id, seq, data (MEDIUMBLOB, 256 KiB je Zeile) – FK auf `file_blob` mit ON DELETE CASCADE | T |

- `file_blob` statt `blob`, weil BLOB in MySQL/MariaDB ein reserviertes Wort
  ist – gleicher Grund wie `setting.name` statt `key`. Die Spaltennamen
  (`blob_id`, `pdf_blob_id`, `file_blob_id`) bleiben kurz.
- Inhalt ist immer Chiffrat: secretstream mit dem DEK der Datei, Klartext-
  Chunks à 64 KiB (Formate siehe 01 §2). Schreiben geht ohne Geheimnis
  (`dek_sealed` an `VK_pub`), Lesen nur mit entsperrtem Tresor.
- `cipher_sha256` ist NULL, solange der Strom nicht zu Ende geschrieben ist;
  erst damit gilt ein Blob als lesbar. Fertig geschrieben dient die Prüfsumme
  der Integritätsprüfung nach dem Backend-Wechsel (M2-5).
- Backend `fs`: `shared/var/blobs/<2 Zeichen>/<Zufalls-ID>` – der Dateiname
  ist eine Zufalls-ID (32 Hex-Zeichen), nie der hochgeladene Name; die
  Unterverzeichnisse halten das Verzeichnis listbar. Geschrieben wird in eine
  `.part`-Datei und erst am Ende umbenannt.
- Backend-Wahl über das Setting `speicher_backend` (Default `fs`, E-06);
  das Umstellen ist eine Schrittkette (M2-5).
- Ins Backup kommen beide Backends (M2-6, 06 §2): `db` über den Dump – die
  Binärspalten als Hex-Literale, sonst zerlegt die Zeichensatz-Behandlung das
  Chiffrat –, `fs` als Dateien unter `blobs/` im ZIP.

### Backend umstellen (M2-5)

Beide Backends legen denselben Byte-Strom ab – der Wechsel ist ein Kopieren
von Chiffrat, **kein Entschlüsseln und kein Tresor**. Gemessen wird jede Kopie
an `cipher_sha256`. Admin-Seite `/admin/speicher`
(`Admin\StorageController`, `Service\Storage\StorageSwitchService`,
`public/js/speicher.js`), ein Schritt je Request:

1. **Ziel setzen** – `speicher_backend` wird **sofort** umgestellt: neue
   Uploads landen ab dann im Ziel, die Kette zieht nur den Bestand nach. Der
   Mischzustand ist unkritisch, weil jede Zeile ihr Backend selbst trägt.
2. **`verschieben`** (wiederholbar) – arbeitet Zeilen mit `storage <> Ziel`
   und gesetzter Prüfsumme ab, bis ein Zeit- (10 s) oder Byte-Budget erreicht
   ist. Je Blob: bei Ziel `fs` zuerst `fs_name` in die Zeile (eine Waisen-
   Datei bleibt so auffindbar), dann Chiffrat strömend kopieren und die
   Prüfsumme vergleichen, dann `storage` umstellen, **danach** die Quelle
   löschen, bei Ziel `db` zum Schluss `fs_name` leeren. Jeder Abbruch
   hinterlässt höchstens eine Kopie zu viel, nie eine fehlende.
   Ein Blob, dessen Kopie nicht zur Prüfsumme passt oder dessen Quelle
   unlesbar ist, bleibt unverändert liegen; seine Zeilen-ID wird gemeldet und
   die Kette macht weiter.
3. **`aufraeumen`** – löscht Reste: Chunks zu Zeilen mit `storage = 'fs'`,
   Dateien zu Zeilen mit `storage = 'db'` samt `fs_name`.
4. **`pruefstart` / `pruefen`** (wiederholbar) – Integritätsprüfung über alle
   fertigen Blobs; Cursor, Zähler und bis zu 50 auffällige Zeilen-IDs stehen
   als JSON im Setting `speicher_pruefung` (nur IDs und Zahlen).

Der Umzugsfortschritt wird **nirgends gespeichert**: „offen" ist
`COUNT(*) WHERE storage <> Ziel`. Deshalb ist jeder Schritt wiederholbar und
ein abgebrochener Lauf einfach fortsetzbar. Unfertige Zeilen
(`cipher_sha256 IS NULL`) werden nicht verschoben – ohne Prüfsumme ist der
Umzug nicht prüfbar – und auf der Admin-Seite ausgewiesen.

**Pflicht-Tests:** `StorageSwitchTest` (db→fs und fs→db: alles liegt danach im
Ziel, Chiffrat unverändert, Klartext über den Tresor identisch, Quelle leer;
neue Uploads gehen während des Laufs schon ins Ziel; abgebrochener Lauf wird
fortgesetzt; Schritte idempotent; unfertige Zeilen bleiben liegen und werden
gezählt; `aufraeumen` entfernt Reste ohne Inhaltsverlust; Prüfung findet
manipuliertes Chiffrat und nennt nur die Zeilen-ID; unlesbare Quelle bzw.
Prüfsummen-Abweichung lässt den Blob auf der Quelle; gespeicherter Zustand
enthält nur Zahlen); `tests/js/speicher.test.js` (Kettenlogik, insbesondere:
ein Request ohne Fortschritt beendet die Kette statt endlos zu wiederholen).

## Fachdaten

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `submission` (öffentliche Einreichung, seit M4-6 auch je intern erfasstem Beleg) | reference_code UNIQUE NULL (zweistufig geschrieben wie `file_blob`, s. u.), form_hash (SHA-256 des Formular-Token-Nonce, UNIQUE – macht ein wiederholtes Absenden idempotent), received_at, status, dek_sealed, payload_enc {name, email, erstattung: {art: `ueberweisung`/`bar`/`keine`, iban, kontoinhaber}, freitext, kostenstelle_hinweis} – intern erfasst: name = Anzeigename des Kontos, ohne email, erstattung nur wenn angegeben, freitext ggf. leer | T |
| `document` (Beleg-Dokument) | source (`einreichung`/`intern`/`archiv`/`erechnung`), submission_id NULL, cost_center_id NULL (Klartext-Strukturfeld wie bei `invoice`, FK `RESTRICT`; aus der Mannschaftswahl der Einreichung, im Posteingang änderbar – Filter und Scope „eigene Kostenstelle“ von `inbox.view`, seit M4-5/Migration 014), original_blob_ids JSON, processed_blob_ids JSON NULL (parallel zu `original_blob_ids`: je Seite die aufbereitete Fassung des Scanners oder null; NULL, wenn keine Seite eine hat – seit M5-4/Migration 016, Details 03 §1/§2), pdf_blob_id (aufbereitetes PDF bzw. Upload), status (s. u.), ocr_status (`keine`/`ausstehend`/`fertig`/`uebersprungen`), resubmit_on DATE NULL (Wiedervorlage-Datum), status_note_enc NULL (Ablehnungsgrund bzw. Wiedervorlage-Notiz, AEAD mit Zeilen-DEK), status_changed_at/by NULL, content_bi (Duplikaterkennung), dek_sealed, created_by NULL (bei `intern` das erfassende Konto, M4-6), created_at | T |
| `submission_upload` | blob_id (FK `file_blob`, `ON DELETE CASCADE`), form_hash, created_at – Blobs, die das Formular-Token hochgeladen hat, bis eine Einreichung sie beansprucht (Zeile gelöscht) oder der Cron sie nach 24 h abräumt (`App\Service\Cron\SubmissionUploadCleanupTask`, M4-2) | – (nur IDs/Hash) |
| `document_artifact` | document_id, kind (`page_image`/`pdfa`/`text`/`extraction`), seq, blob_id NULL, dek_sealed, data_enc NULL, producer (`session`/`browser`/`worker`), job_id NULL (kein FK, wie `job.ref_id` – der Job wird nach 7 Tagen aufgeräumt, das Artefakt bleibt; seit M4-8/Migration 015), created_at – jedes Artefakt mit eigenem DEK, damit auch der Worker (ohne Zeilen-DEK des Dokuments) Ergebnisse ablegen kann; das jeweils neueste je kind gilt. **Stand M4-8** (issue #30): Kind `page_image`, Producer `browser`, durch `App\Service\Document\PdfRasterung` (`render_pages`-Job) – das per-Artefakt-DEK wird auch hier gesetzt, ist aber ungenutzt, weil `blob_id` bereits sein eigenes trägt (Details: 03 §3). `UNIQUE(document_id, kind, job_id, seq)`, `blob_id` mit `ON DELETE CASCADE` – ein gelöschtes Blob (ein abgelöster Rendering-Lauf) nimmt die Zeile mit | T |
| `invoice` (fachlicher Beleg) | document_id UNIQUE (FK `RESTRICT`), doc_type (`rechnung`/`quittung`/`gutschrift`/`kassenbon`/`sonstiges`, `App\Domain\InvoiceType`), supplier_id NULL (FK `RESTRICT`), invoice_date, due_date NULL, service_from/to NULL, category_id NULL (FK `RESTRICT`), sphere NULL, cost_center_id NULL (FK `RESTRICT`), recurring_series_id NULL, direction (`ausgabe`/`einnahme`, `App\Domain\InvoiceDirection`), payment_status (`offen`/`teilbezahlt`/`bezahlt`/`erstattung_offen`/`erstattet`/`kein_zahlungsbezug`), checked_by/at, locked_by/at, dek_sealed, data_enc {invoice_number, gross, net, taxes[{rate, amount}], currency, purpose_short, notes, payment_hint}, number_bi (Blind Index der Rechnungsnummer, Zweck `invoice.number`, Leerraum entfernt; `INDEX(supplier_id, number_bi)` für die Duplikaterkennung M6-6), created_by/at, updated_by/at. **Stand M6-3** (issue #37, Migration 019): angelegt von der Prüfansicht (03 §6), ohne `recurring_series_id` (kommt mit M8 – die Zieltabelle fehlt noch), `locked_by/at` (M6-4) und `payment_status` (Abgleich M9/M10); `data_enc` noch ohne `payment_hint`. Beträge Integer-Cent, Steuersatz als Dezimal-String mit Punkt („19“, „5.5“) | T |
| `supplier` (Lieferant bzw. Zahler) | role (`lieferant`/`zahler`/`beide`, Klartext – filtert Liste und später die Auswahl in der Prüfansicht), dek_sealed, data_enc {name, aliases[], address, iban[], bic, vat_id, tax_number, email, website, creditor_id, mandate_refs[], customer_number, notes}, default_category_id NULL (FK `RESTRICT`), default_sphere NULL, created_via (`ki`/`manuell`/`archiv`), needs_review, merged_into NULL (FK auf `supplier`), created_at, updated_at – **keine** `*_bi`-Spalten, alle Blind Indexes stehen in `supplier_key` (Migration 018, M6-2, s. u. „Lieferanten“) | T |
| `supplier_key` | supplier_id (FK `CASCADE`), kind (`name`/`iban`/`vat_id`/`tax_number`/`creditor_id`/`mandate`), value_bi – mehrere Zeilen je Kind; `INDEX(kind, value_bi)` für die Auflösung (Migration 018, M6-2) | – (nur BI) |
| `category` | name UNIQUE, direction (`einnahme`/`ausgabe`/`beide`), parent_id NULL (FK `RESTRICT`), default_sphere NULL, color NULL (Schlüssel der Palette `App\Domain\CategoryColor`), sort (je Richtung), active, ai_hint (Beschreibung für den Prompt, max. 500 Zeichen) (Migration 017, M6-1; Pflege `/admin/kategorien`, Recht `admin.settings`, s. u. „Kategorien“) | – |
| `cost_center` | name UNIQUE (z. B. „Herren", „E-Jugend", „Vereinsheim"), sort, active (Tabelle seit Migration 010, M3-6; Pflege `/admin/kostenstellen`, Recht `admin.settings`, seit M4-1/Migration 012: Löschen nur ohne Zuweisung, `ON DELETE RESTRICT` auf `user_cost_center`; als Verwendung zählen auch `document` und – seit M6-3 – `invoice`, `CostCenterRepository::documentCount()`) | – |
| `recurring_series` | supplier_id, interval (`monat`/`quartal`/`halbjahr`/`jahr`/`unregelmaessig`), dek_sealed, data_enc {expected_gross, contract_ref, label}, next_expected, tolerance_days, active, confirmed | T |
| `bank_account` | kind (`bank`/`kasse`, `App\Domain\BankAccountKind`, nach dem Anlegen fest), dek_sealed, data_enc {name, iban, bic, bank} (Kasse: nur name), iban_bi NULL **UNIQUE** (Zweck `bank_account.iban`), opening_balance_enc {amount (Cent), currency} (Saldo zu Beginn des Stichtags), opening_date (Stichtag, Klartext), active, created_at, updated_at. **Stand M9-1** (issue #59, Migration 020, s. u. „Konten“) | T |
| `cash_count` (Kassensturz) | account_id (FK `RESTRICT`), counted_on DATE (Klartext – Zeitraum-Scope), dek_sealed, data_enc {expected, counted (Cent), currency, note}, created_by NULL (FK `SET NULL`), created_at – append-only; Differenz = counted − expected wird abgeleitet, nicht gespeichert (Migration 020, M9-1) | T |
| `bank_import` | account_id, format (`mt940`/`csv:<profil>`), file_blob_id, imported_by, imported_at, stats JSON (neu/duplikat/fehler), balance_check (`ok`/`abweichung`/`n.v.`) | – |
| `bank_transaction` | account_id, import_id, booking_date, value_date, direction, dek_sealed, data_enc {amount, currency, counterparty_name, counterparty_iban, purpose, eref, mref, cred, gvc, booking_text}, dedup_bi UNIQUE (je Konto), counterparty_bi, category_id NULL, doc_required (Default: Ausgabe 1, Einnahme 0 – Setting), doc_status (`fehlt`/`zugeordnet`/`nicht_noetig`), source (`import`/`manuell`) | T |
| `csv_profile` | name, header_signature, mapping JSON, delimiter, encoding, date_format, decimal_sep | – |
| `allocation` (Abgleich) | invoice_id, transaction_id, dek_sealed, amount_enc (Teilbetrag), method (`auto`/`vorschlag_bestaetigt`/`manuell`), score, rule_trace JSON, created_by, created_at | T (Betrag) |
| `assignment_rule` | Lern-/Regeltabelle: bedingung (z. B. counterparty_bi, Stichwort-BI) → category_id / doc_required=0 / supplier_id | – |

## Statusmodell `document.status`

```
eingegangen ──(Annehmen im Posteingang)──► bereit_zur_auswertung
     │                                        │ (KI-Job)
     │                                        ▼
     │                                  ausgewertet ──► in_pruefung ──► geprueft ──► festgeschrieben
     │                                        │               │
     ├──► wiedervorlage ◄─────────────────────┤               └──► abgelehnt (mit Grund, bleibt erhalten)
     │                                        │
     └──► abgelehnt              ki_fehler ◄──┘ (auch aus bereit_zur_auswertung)

bereit_zur_auswertung / ki_fehler ──(Prüfansicht, manuell)──► in_pruefung
```

Übergänge vollständig (maßgeblich ist `App\Domain\DocumentStatus::uebergaenge()`,
Test `tests/Domain/DocumentStatusTest.php`; seit M4-5/issue #27):

| von | nach |
|---|---|
| `eingegangen` | `bereit_zur_auswertung`, `wiedervorlage`, `abgelehnt` |
| `bereit_zur_auswertung` | `ausgewertet`, `ki_fehler`, `in_pruefung` |
| `ausgewertet` | `in_pruefung`, `ki_fehler`, `wiedervorlage` |
| `in_pruefung` | `geprueft`, `abgelehnt` |
| `geprueft` | `festgeschrieben` |
| `ki_fehler` | `bereit_zur_auswertung` (neuer Versuch), `wiedervorlage`, `in_pruefung` |
| `wiedervorlage` | `bereit_zur_auswertung`, `abgelehnt` |
| `festgeschrieben`, `abgelehnt` | – (Endzustände) |

Aktionen im Posteingang (`App\Domain\InboxAction`, Recht `document.edit`):
**Annehmen** aus `eingegangen`/`wiedervorlage` → `bereit_zur_auswertung`;
**Ablehnen** (Grund Pflicht) aus `eingegangen`/`wiedervorlage`/`in_pruefung`
→ `abgelehnt` – so lässt sich Spam oder ein nicht zuständiger Beleg schon im
Posteingang aussortieren; **Wiedervorlage** (Datum Pflicht, ab heute; Notiz
optional) aus `eingegangen`/`ausgewertet`/`ki_fehler` → `wiedervorlage`. Eine
Wiedervorlage erscheint ab ihrem Datum wieder unter „Offen“ (keine Mail).
Grund und Notiz liegen verschlüsselt in `status_note_enc`, im Audit-Log als
versiegelte Details. Ein Statuswechsel gilt nur, wenn der Beleg noch im
erwarteten Ausgangsstatus ist (`UPDATE … WHERE status = ?`) – zwei
gleichzeitige Entscheidungen gewinnen nicht beide.

Prüfansicht (M6-3, issue #37, 03 §6, Recht `document.edit`): Ein Beleg
lässt sich erfassen, solange aus seinem Status `in_pruefung` erreichbar ist
oder er dort steht (`DocumentStatus::pruefbar()`: `bereit_zur_auswertung`,
`ausgewertet`, `ki_fehler`, `in_pruefung`). Ohne KI führt der Weg direkt von
`bereit_zur_auswertung` (bzw. nach einem KI-Fehler von `ki_fehler`) nach
`in_pruefung` – beim ersten Speichern; „Geprüft, nächster“ setzt danach
`geprueft` samt `invoice.checked_by/at`. Beides in einer Transaktion, die den
Beleg zuerst im gelesenen Status sperrt (`SELECT … FOR UPDATE`). Ein
geprüfter Beleg ist in der Prüfansicht nur noch lesbar; Zurücknehmen gibt es
noch nicht.

`duplikat_verdacht` ist ein Flag (content_bi gleich oder Lieferant +
Rechnungsnummer gleich), kein Status.

## Sphären (steuerliche Zuordnung eines gemeinnützigen Vereins)

**Per Setting `sphaeren_aktiv` ausgeblendet (Default aus, E-15).** Die
Spalten bleiben (NULL), damit Einschalten keine Migration braucht.
Enum `Sphere`: `ideell`, `vermoegensverwaltung`, `zweckbetrieb`,
`wirtschaftlich`. Wenn aktiv: KI schlägt vor, Kategorie liefert Default,
Mensch bestätigt. (Fachliche Richtigkeit mit Steuerberater klären – die Software
liefert nur die Struktur.)

## Kategorien – Startbestand (per Migration als Seed, editierbar)

Kategorien haben eine Richtung (`ausgabe`/`einnahme`/`beide`).

**Einnahmen:** Mitgliedsbeiträge · Spenden · Sponsoring & Bandenwerbung ·
Zuschüsse (Verband, Gemeinde) · Eintrittsgelder · Verkauf Speisen &
Getränke · Vermietung Vereinsheim/Platz · Veranstaltungserlöse · Sonstige
Einnahmen.

**Ausgaben:**

Verpflegung & Bewirtung · Platzpflege & Grünanlagen · Sportplatz-
Instandhaltung · Vereinsheim & Gebäude · Energie & Wasser · Sportausrüstung &
Trikots · Bälle & Trainingsmaterial · Spielbetrieb & Schiedsrichter ·
Verbandsbeiträge & Gebühren · Versicherungen · Veranstaltungen & Feste ·
Fahrtkosten · Jugendarbeit · Übungsleiter & Ehrenamt · Büro & Verwaltung ·
IT & Software · Bankgebühren · Werbung & Sponsoring · Ehrungen & Geschenke ·
Sonstiges. Jede Kategorie hat ein `ai_hint`-Feld mit Beispielen
(„Rasendünger, Mäharbeiten, Sand, Linierfarbe" für Platzpflege).

**Umsetzung (M6-1, issue #35, Migration 017):** Der Seed legt genau diese
Liste an – jede Kategorie aktiv, mit `ai_hint` und einer Farbe. Pflege unter
`/admin/kategorien` (`App\Admin\CategoryController`, Regeln in
`App\Service\MasterData\CategoryService`, Recht `admin.settings` wie die
Kostenstellen):

- Die Liste ist nach Richtung gruppiert; ↑/↓ verschiebt **innerhalb** der
  Richtung und normalisiert deren `sort` auf 10, 20, 30, … Eine neue oder in
  eine andere Richtung verschobene Kategorie landet am Ende ihrer Gruppe.
  Angeboten werden für eine Richtung die aktiven Kategorien dieser Richtung
  plus `beide` (`CategoryRepository::active()`).
- Farbe ist eine feste Palette (`App\Domain\CategoryColor`, CSS-Klassen
  `.farbpunkt-<farbe>` mit Token für hell/dunkel), kein freier Hex-Wert: die
  CSP erlaubt keine Inline-Styles (CLAUDE.md §4).
- **Löschen nur ohne Verwendung, sonst deaktivieren.** Was als Verwendung
  zählt, entscheidet allein `CategoryRepository::usageCount()` – heute
  Unterkategorien (`parent_id`), `supplier.default_category_id` (M6-2) und
  `invoice.category_id` (M6-3). Jede spätere Tabelle mit `category_id`
  (`bank_transaction`, `assignment_rule`) legt ihren Fremdschlüssel mit
  `ON DELETE RESTRICT` an **und** zählt dort mit. Deaktivierte Kategorien werden nicht mehr
  angeboten, bestehende Zuordnungen bleiben.
- `parent_id` ist vorbereitet, Unterkategorien sind aber noch nicht pflegbar
  (flache Liste). `default_sphere` bleibt NULL und unsichtbar, solange
  `sphaeren_aktiv` aus ist (E-15).
- Alles Klartext – Stammdaten, keine fachlichen Daten; die Kategorie-ID
  filtert direkt in SQL.

**Pflicht-Tests:** `CategoryAdminFlowTest` (Seed vollständig laut Liste,
aktiv, mit KI-Hinweis und Farbe; Tabelle ohne `*_enc`/`dek_sealed`;
Anlegen/Ändern/Verschieben je Richtung/Löschen mit Audit-Eintrag;
Validierung; CSRF; verwendete Kategorie wird nicht gelöscht, lässt sich
deaktivieren; der FK allein verweigert das Löschen; 404; 403 ohne
`admin.settings`).

## Lieferanten

**Umsetzung (M6-2, issue #36, Migration 018):** Lieferanten und Zahler sind
eine Tabelle – ein Geschäftspartner, ob er Rechnungen schickt oder an den
Verein zahlt, sagt `role` (`App\Domain\SupplierRole`: `lieferant`, `zahler`,
`beide`). Pflege unter `/app/lieferanten` (`App\App\SupplierController`,
Regeln und Verschlüsselung in `App\Service\MasterData\SupplierService`,
Recht `supplier.manage` – Admin, Finanzen; auch zum Lesen).

- **Tresor-Daten:** alles außer der Struktur liegt in `data_enc` (JSON,
  AAD `supplier|<id>|data_enc`, Zeilen-DEK in `dek_sealed`). Die Zeile wird
  in einer Transaktion zweistufig geschrieben (Insert → ID → `data_enc`).
  Lesen **und** Schreiben brauchen den entsperrten Tresor der Sitzung –
  Schreiben, weil die Blind Indexes aus ihm abgeleitet sind. Ohne Tresor
  zeigen die Seiten nur einen Hinweis. Liste, Sortierung (nach Name) und
  Suche (Name, Alias, IBAN, auch Teilstücke) laufen in PHP nach dem
  Entschlüsseln.
- **`supplier_key`** wird bei jedem Speichern aus den gespeicherten Daten
  komplett neu geschrieben (`App\Service\MasterData\SupplierKeys`):
  Name und jeder Alias als `name`, jede IBAN, USt-ID, Steuernummer,
  Gläubiger-ID und jede Mandatsreferenz. Zweck des Blind Index =
  `supplier.<kind>` (`App\Domain\SupplierKeyKind`, Werte nie umbenennen).
  Normalisierung: Name wie 03 §7 Stufe 4 (klein, ä→ae/ß→ss, Akzente weg,
  Satzzeichen → Leerzeichen, Rechtsform **am Ende** entfernt – „Getränke
  Müller GmbH & Co. KG“ → „getraenke mueller“, „Sport AG Nord“ bleibt);
  IBAN, USt-ID, Gläubiger-ID, Mandatsreferenz groß ohne Leerraum;
  Steuernummer nur Buchstaben/Ziffern. Die Auflösung (M7-6) nutzt dieselben
  Funktionen.
- **Eindeutig:** IBAN, USt-ID, Steuernummer und Gläubiger-ID gehören zu
  genau einem (nicht zusammengeführten) Lieferanten – ein zweiter wird mit
  Hinweis und Link auf den ersten abgelehnt (Doppelte → Zusammenführen,
  M6-5). Geprüft im Service, nicht per `UNIQUE`, damit das Zusammenführen
  die Schlüssel umhängen kann. Gleicher Name/Alias ist erlaubt, die Seite
  weist darauf hin. Mandatsreferenzen sind nur je Gläubiger eindeutig.
- **Standard-Kategorie:** muss existieren, aktiv sein (außer der bereits
  gespeicherten) und zur Rolle passen – Lieferant: Ausgabe/beide, Zahler:
  Einnahme/beide. `CategoryRepository::usageCount()` zählt sie mit.
- **Mehrfachwerte** (Aliasse, IBANs, Mandatsreferenzen) sind im Formular
  Textfelder „eine pro Zeile“ (je höchstens 20) – kein JavaScript nötig.
- **Löschen** nur ohne Verwendung (`SupplierRepository::usageCount()`,
  heute: zusammengeführte Lieferanten und `invoice` (M6-3);
  `recurring_series`, `assignment_rule` zählen dort mit, sobald es sie
  gibt). Die Schlüssel gehen per `CASCADE` mit.
- **Audit** (`lieferant.angelegt/geaendert/geloescht`, Entität `supplier`):
  Details nur die **Namen** der geänderten Felder bzw. die Rolle – nie
  Name, IBAN o. Ä. (ein Zahler kann eine Person sein, 01 §6). Speichern
  ohne Änderung schreibt keine Zeile. Flash-Meldungen nennen keinen Namen.
- `created_via` ist hier immer `manuell`, `needs_review` 0; `ki` mit
  `needs_review` legt erst M7-6 an (die Seite zeigt dann „prüfen“).
  `default_sphere` bleibt NULL und unsichtbar (E-15).

**Pflicht-Tests:** `SupplierKeysTest` (Namens-Normalisierung inkl.
Rechtsformen und Umlaute, Schlüssel je Feld, leere Felder ohne Schlüssel,
eigener Zweck je Kind); `SupplierFlowTest` (mehrere IBANs/Aliasse
round-trip; weder Name noch IBAN noch normalisierter Name im Klartext in
`supplier`/`supplier_key`; `supplier_key` = nachgerechnete Blind Indexes
nach Anlegen und Ändern; Audit nur mit Feldnamen; ungültige IBAN;
doppelte IBAN/USt-ID mit Link abgelehnt, eigene IBAN kein Konflikt;
gleicher Name mit Hinweis; Kategorie passend zur Rolle, aktiv, dann in
Verwendung; Liste sortiert, Filter Rolle, Suche Name/IBAN; ohne Tresor
nichts sichtbar und nichts geschrieben; CSRF; 404; Spaltenliste);
`RoutePermissionMatrixTest` (nur Admin/Finanzen).

## Konten

**Umsetzung (M9-1, issue #59, Migration 020):** Bankkonten und Kassen sind
eine Tabelle `bank_account`; die Art (`App\Domain\BankAccountKind`: `bank`,
`kasse`) wird beim Anlegen gewählt und ist danach fest. Pflege unter
`/app/konten` (`App\App\AccountController`, Regeln und Verschlüsselung in
`App\Service\Bank\BankAccountService`). Rechte: **Lesen `bank.view`**
(Admin, Vorstand, Finanzen, Kassenprüfer, Steuerberater – ohne Formular,
nur die gespeicherten Werte), **Anlegen/Ändern/Löschen und Kassensturz
`bank.book`** (Admin, Finanzen).

- **Tresor-Daten:** `data_enc` (AAD `bank_account|<id>|data_enc`) und
  `opening_balance_enc` (AAD `bank_account|<id>|opening_balance_enc`) unter
  dem Zeilen-DEK; zweistufig in einer Transaktion geschrieben. Lesen **und**
  Schreiben brauchen den entsperrten Tresor (Schreiben wegen `iban_bi`);
  ohne Tresor zeigen die Seiten nur einen Hinweis. Sortierung (aktive
  zuerst, Bank vor Kasse, dann Name) in PHP.
- **Bankkonto:** IBAN optional (Sparbuch ohne IBAN), wenn angegeben gültig
  (`App\Domain\Iban`), groß ohne Leerraum gespeichert; **eine IBAN gehört
  zu genau einem Konto** – geprüft im Service (Meldung mit Link auf das
  andere Konto), `UNIQUE(iban_bi)` als Netz. Der Kontoauszug-Import (M9-4)
  findet das Konto über diesen Blind Index. BIC optional (8/11 Zeichen),
  Bankname optional. Anfangssaldo darf negativ sein.
- **Kasse:** nur Name und Anfangsbestand, keine Bankverbindung (abgelehnt,
  falls doch gesendet), Anfangsbestand ≥ 0.
- **Anfangssaldo** = Bestand **zu Beginn** des Stichtags in Integer-Cent
  (`App\Service\Processing\Betrag`, Eingabe wie „1.234,56“), Währung EUR
  mit gespeichert. Buchungen ab dem Stichtag kommen hinzu. Der Stichtag
  liegt nicht in der Zukunft und nicht nach dem ersten Kassensturz der Kasse
  (`BankAccountRepository::firstCashCountOn()`) – ein gespeicherter
  Kassensturz bleibt so auf seinen Anfangsbestand bezogen.
- **Löschen nur ohne Verwendung, sonst deaktivieren** (`active`). Was als
  Verwendung zählt, entscheidet allein `BankAccountRepository::usageCount()`
  – heute `cash_count`. Jede spätere Tabelle mit `account_id`
  (`bank_import`, `bank_transaction`) legt ihren Fremdschlüssel mit
  `ON DELETE RESTRICT` an **und** zählt dort mit. Deaktivierte Konten
  bleiben lesbar, nehmen aber keinen Kassensturz mehr an.
- **Kassensturz** (`App\Service\Bank\Kassensturz`, Tabelle `cash_count`,
  nur für eine aktive Kasse): Datum (≥ Stichtag, ≤ heute), gezählter
  Bestand (≥ 0), Notiz (≤ 500 Zeichen). Den Soll-Bestand rechnet der Server
  (`sollBestand()`: Anfangsbestand + Kassenbuchungen vom Stichtag bis
  einschließlich Datum – bis M9-5 gibt es keine Buchungen, also der
  Anfangsbestand). „Differenz berechnen“ zeigt Soll/Ist/Differenz mit
  Ergebnis (`App\Domain\CashCountOutcome`: Kasse stimmt / Fehlbetrag /
  Überschuss) ohne zu speichern; „Kassensturz speichern“ hält Soll und Ist
  **zum Zeitpunkt der Zählung** fest – spätere Buchungen ändern einen alten
  Kassensturz nicht. Kein Ändern, kein Löschen. Die Differenz als Buchung
  „Kassendifferenz“ vorzuschlagen, übernimmt M9-5 (issue #63), sobald es
  Kassenbuchungen gibt.
- **Zeitraum-Scope:** Die Kassensturz-Liste filtert per
  `Zugriffsbereich::sqlBedingung('c.counted_on', null)` in SQL. Konten
  selbst sind Stammdaten und bleiben sichtbar; den **Anfangssaldo** sieht
  ein Leser ohne `bank.book` nur, wenn der Stichtag in seinem Zeitraum liegt
  (sonst „außerhalb Ihres Zeitraums“) – er ist der Bestand eines Tages.
- **Audit:** `konto.angelegt` (Details: Art und Namen der Felder mit Wert),
  `konto.geaendert` (nur Namen der geänderten Felder; ohne Änderung weder
  Zeile noch Schreibzugriff, `updated_at` bleibt), `konto.geloescht` (Art) – Entität `bank_account`;
  `kassensturz.erfasst` – Entität `cash_count`, **ohne Details**. Nie
  Name, IBAN oder Betrag im Log oder in Flash-Meldungen.

**Pflicht-Tests:** `BankAccountFlowTest` (Bankkonto: IBAN/Name/Betrag nicht
im Klartext, `iban_bi` = nachgerechneter Blind Index, Betrag als Cent;
Kasse ohne Bankverbindung und nicht negativ, Bankkonto darf negativ sein;
Validierung mit markiertem Feld, Stichtag nicht in der Zukunft; doppelte
IBAN mit Link abgelehnt, eigene kein Konflikt; Ändern mit Feldnamen im
Audit, Art unveränderlich, ohne Änderung keine Zeile und kein Schreiben;
Stichtag höchstens bis zum ersten Kassensturz; verwendete Kasse nicht löschbar – auch der FK allein
nicht –, deaktivierbar; ungenutztes Konto löschbar; Leserechte ohne
Formular; Anfangssaldo außerhalb des Zeitraums verborgen; ohne Tresor
nichts sichtbar und nichts geschrieben; CSRF; 404 (auch Löschen ohne
liegen gebliebene Meldung);
Spaltenlisten; Kassensturz: Differenz-Anzeige ohne Speichern für
Fehlbetrag/stimmt/Überschuss, Speichern verschlüsselt mit Soll vom Server
und Audit ohne Details, Regeln für Datum/Betrag/Notiz/inaktive Kasse,
Zeitraum-Scope); `CashCountTest` (Differenz und Ergebnis);
`RoutePermissionMatrixTest` (Lesen `bank.view`, Schreiben `bank.book`).
