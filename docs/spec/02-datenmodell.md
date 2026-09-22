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
| `user` | email_enc, email_bi UNIQUE, display_name_enc, password_hash, status (`eingeladen`/`aktiv`/`gesperrt`), expires_at NULL, mfa_required, created_at, last_login_at | S |
| `role` | name, is_system, permissions JSON | – |
| `user_role` | user_id, role_id | – |
| `user_cost_center` | user_id, cost_center_id (Scope „Vereinsverantwortlicher") | – |
| `user_scope` | user_id, period_from NULL, period_to NULL (Zeitraum-Scope externer Konten) | – |
| `user_key` | user_id, public_key, wrapped_private_key, kdf_salt, kdf_ops, kdf_mem | – (selbst gewrappt) |
| `vault` | version, public_key, created_at (Migration 004, M2-4) | – |
| `vault_grant` | user_id, vault_version, sealed_private_key, granted_by, granted_at | – (versiegelt) |
| `mfa_totp` | user_id, secret_enc, confirmed_at | S |
| `mfa_email_code` | user_id, code_hash, expires_at, attempts | – |
| `mfa_backup_code` | user_id, code_hash, used_at | – |
| `trusted_device` | user_id, token_hash, label, expires_at | – |
| `auth_token` | user_id, typ (`reset`/`invite`), token_hash, expires_at, used_at | – |
| `rate_limit` | key_hash, window_start, count (übernommen) | – |
| `audit_log` | ts, user_id NULL, action, entity, entity_id, ip_hash, details_enc, dek_sealed, prev_hash, hash | T (details) |

- `vault` steht als einzige dieser Tabellen schon (Migration 004, M2-4): der
  Chunk-Upload ist der erste Schreiber, der einen Datenschlüssel versiegeln
  muss, und dafür braucht er `public_key`. Die Zeile schreibt der Installer
  mit M3-2; bis dahin ist die Tabelle leer und der Upload-Abschluss antwortet
  503 (03 §4).

## Betrieb

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `setting` | name (PK), value, updated_at – nur nicht-sensible Einstellungen; `name` statt `key`, weil KEY in MySQL/MariaDB reserviert ist. Erster Eintrag: `update_kanal` (M1-2); Cron (M1-5): `cron_lock_until` (Sperre, Ablaufzeitpunkt), `cron_letztes_aufraeumen`, `cron_aufraeum_intervall_s`; Speicher (M2-3): `speicher_backend` (`fs`/`db`) | – |
| `mail_queue` | to_enc, subject_enc, body_enc, status, attempts, next_try_at, last_error | S |
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
| `submission` (öffentliche Einreichung) | reference_code UNIQUE, received_at, status, dek_sealed, payload_enc {name, email, erstattung: {art: `ueberweisung`/`bar`/`keine`, iban, kontoinhaber}, freitext, kostenstelle_hinweis} | T |
| `document` (Beleg-Dokument) | source (`einreichung`/`intern`/`archiv`/`erechnung`), submission_id NULL, original_blob_ids JSON, pdf_blob_id (aufbereitetes PDF bzw. Upload), status (s. u.), ocr_status (`keine`/`ausstehend`/`fertig`/`uebersprungen`), content_bi (Duplikaterkennung), dek_sealed, created_by NULL, created_at | T |
| `document_artifact` | document_id, kind (`page_image`/`pdfa`/`text`/`extraction`), seq, blob_id NULL, dek_sealed, data_enc NULL, producer (`session`/`browser`/`worker`), job_attempt_id, created_at – jedes Artefakt mit eigenem DEK, damit auch der Worker (ohne Zeilen-DEK des Dokuments) Ergebnisse ablegen kann; das jeweils neueste je kind gilt | T |
| `invoice` (fachlicher Beleg) | document_id, doc_type (`rechnung`/`quittung`/`gutschrift`/`kassenbon`/`sonstiges`), supplier_id NULL, invoice_date, due_date NULL, service_from/to NULL, category_id NULL, sphere NULL, cost_center_id NULL, recurring_series_id NULL, direction (`ausgabe`/`einnahme`), payment_status (`offen`/`teilbezahlt`/`bezahlt`/`erstattung_offen`/`erstattet`/`kein_zahlungsbezug`), checked_by/at, locked_by/at, dek_sealed, data_enc {invoice_number, gross, net, taxes[], currency, purpose_short, notes, payment_hint}, number_bi | T |
| `supplier` | dek_sealed, data_enc {name, aliases[], address, iban[], bic, vat_id, tax_number, email, website, creditor_id, mandate_refs[], customer_number}, name_bi, iban_bi (Mehrfach → Tabelle `supplier_key`), default_category_id, default_sphere, created_via (`ki`/`manuell`/`archiv`), needs_review, merged_into NULL | T |
| `supplier_key` | supplier_id, kind (`name`/`iban`/`vat_id`/`creditor_id`/`mandate`), value_bi | – (nur BI) |
| `category` | name, parent_id NULL, default_sphere, color, sort, active, ai_hint (Beschreibung für den Prompt) | – |
| `cost_center` | name (z. B. „Herren", „E-Jugend", „Vereinsheim"), sort, active | – |
| `recurring_series` | supplier_id, interval (`monat`/`quartal`/`halbjahr`/`jahr`/`unregelmaessig`), dek_sealed, data_enc {expected_gross, contract_ref, label}, next_expected, tolerance_days, active, confirmed | T |
| `bank_account` | kind (`bank`/`kasse`), dek_sealed, data_enc {name, iban, bic, bank}, iban_bi, opening_balance_enc, active | T |
| `bank_import` | account_id, format (`mt940`/`csv:<profil>`), file_blob_id, imported_by, imported_at, stats JSON (neu/duplikat/fehler), balance_check (`ok`/`abweichung`/`n.v.`) | – |
| `bank_transaction` | account_id, import_id, booking_date, value_date, direction, dek_sealed, data_enc {amount, currency, counterparty_name, counterparty_iban, purpose, eref, mref, cred, gvc, booking_text}, dedup_bi UNIQUE (je Konto), counterparty_bi, category_id NULL, doc_required (Default: Ausgabe 1, Einnahme 0 – Setting), doc_status (`fehlt`/`zugeordnet`/`nicht_noetig`), source (`import`/`manuell`) | T |
| `csv_profile` | name, header_signature, mapping JSON, delimiter, encoding, date_format, decimal_sep | – |
| `allocation` (Abgleich) | invoice_id, transaction_id, dek_sealed, amount_enc (Teilbetrag), method (`auto`/`vorschlag_bestaetigt`/`manuell`), score, rule_trace JSON, created_by, created_at | T (Betrag) |
| `assignment_rule` | Lern-/Regeltabelle: bedingung (z. B. counterparty_bi, Stichwort-BI) → category_id / doc_required=0 / supplier_id | – |

## Statusmodell `document.status`

```
eingegangen ──(Aufbereitung fertig)──► bereit_zur_auswertung
     │                                        │ (KI-Job)
     │                                        ▼
     │                                  ausgewertet ──► in_pruefung ──► geprueft ──► festgeschrieben
     │                                        │               │
     └──► ki_fehler / wiedervorlage ◄─────────┘               └──► abgelehnt (mit Grund, bleibt erhalten)
```

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
