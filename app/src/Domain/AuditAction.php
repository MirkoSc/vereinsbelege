<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the audit log records (docs/spec/01-sicherheit.md section 6, issue
 * #21/M3-8). The value is what `audit_log.action` stores - never renamed
 * once shipped, because it is part of every hashed row that carries it.
 *
 * `entity()` names what `audit_log.entity_id` refers to for this action,
 * so the caller only passes an id and cannot pair it with the wrong kind.
 *
 * Only actions of features that exist are here. Receipts, suppliers,
 * bookings, matching, locking, export and import add theirs when they are
 * built (M4 on) - through App\Service\Audit\AuditLog::record(), like these.
 */
enum AuditAction: string
{
    case LoginErfolg = 'login.erfolg';
    case LoginFehlgeschlagen = 'login.fehlgeschlagen';
    case ZweiterFaktorFehlgeschlagen = 'login.zweiter_faktor_fehlgeschlagen';
    case Logout = 'logout';

    case TotpEingerichtet = 'mfa.totp_eingerichtet';
    case EmailCodeEingerichtet = 'mfa.email_eingerichtet';
    case BackupCodesNeu = 'mfa.backup_codes_neu';
    case GeraetWiderrufen = 'mfa.geraet_widerrufen';

    case PasswortGeaendert = 'passwort.geaendert';
    case PasswortResetAngefordert = 'passwort.reset_angefordert';
    case PasswortResetAbgeschlossen = 'passwort.reset_abgeschlossen';

    case BenutzerEingeladen = 'benutzer.eingeladen';
    case EinladungErneut = 'benutzer.einladung_erneut';
    case EinladungAngenommen = 'benutzer.einladung_angenommen';
    case BenutzerGeaendert = 'benutzer.geaendert';
    case BenutzerGesperrt = 'benutzer.gesperrt';
    case BenutzerEntsperrt = 'benutzer.entsperrt';

    case TresorFreigegeben = 'tresor.freigegeben';
    case TresorEntzogen = 'tresor.entzogen';
    case TresorWiederhergestellt = 'tresor.wiederhergestellt';
    case TresorWiederherstellungFehlgeschlagen = 'tresor.wiederherstellung_fehlgeschlagen';
    /**
     * Issue #154: after a restore without the old server key, the installer
     * re-linked this account (new address under the new key, grant if it
     * had none, authenticator reset) - written by the installer, so the
     * acting user is the account itself.
     */
    case ZugangNeuVerknuepft = 'tresor.zugang_neu_verknuepft';
    /**
     * Vault key rotation (issue #90, docs/spec/01-sicherheit.md section 2
     * "Schlüsselrotation"): entity is the vault generation (`vault.version`).
     */
    case TresorRotationGestartet = 'tresor.rotation_gestartet';
    case TresorRotationSchluesselBestaetigt = 'tresor.rotation_schluessel_bestaetigt';
    case TresorRotationAbgeschlossen = 'tresor.rotation_abgeschlossen';
    case TresorRotationAbgebrochen = 'tresor.rotation_abgebrochen';

    case RolleAngelegt = 'rolle.angelegt';
    case RolleGeaendert = 'rolle.geaendert';
    case RolleGeloescht = 'rolle.geloescht';

    case KostenstelleAngelegt = 'kostenstelle.angelegt';
    case KostenstelleGeaendert = 'kostenstelle.geaendert';
    case KostenstelleGeloescht = 'kostenstelle.geloescht';

    case KategorieAngelegt = 'kategorie.angelegt';
    case KategorieGeaendert = 'kategorie.geaendert';
    case KategorieGeloescht = 'kategorie.geloescht';

    case LieferantAngelegt = 'lieferant.angelegt';
    case LieferantGeaendert = 'lieferant.geaendert';
    case LieferantGeloescht = 'lieferant.geloescht';
    /** Issue #39/M6-5: entity is the merged-away supplier, the target is in the details. */
    case LieferantZusammengefuehrt = 'lieferant.zusammengefuehrt';
    /** Issue #48/M7-6: a supplier the AI created was looked at ("Als geprüft markieren"). */
    case LieferantGeprueft = 'lieferant.geprueft';

    case KontoAngelegt = 'konto.angelegt';
    case KontoGeaendert = 'konto.geaendert';
    case KontoGeloescht = 'konto.geloescht';
    case KassensturzErfasst = 'kassensturz.erfasst';
    case CsvFormatAngelegt = 'csv_format.angelegt';
    case CsvFormatGeaendert = 'csv_format.geaendert';
    case CsvFormatGeloescht = 'csv_format.geloescht';
    case KiAnbieterAngelegt = 'ki_anbieter.angelegt';
    case KiAnbieterGeaendert = 'ki_anbieter.geaendert';
    case KiAnbieterGeloescht = 'ki_anbieter.geloescht';
    case KiAnbieterStandard = 'ki_anbieter.standard';
    /** Issue #180/M7-2b: the optional fallback profile of the AI client chosen or removed. */
    case KiFallbackGewaehlt = 'ki_anbieter.fallback_gewaehlt';
    case KiFallbackEntfernt = 'ki_anbieter.fallback_entfernt';
    case KontoauszugImportiert = 'kontoauszug.importiert';
    case BuchungAngelegt = 'buchung.angelegt';
    case BuchungGeaendert = 'buchung.geaendert';
    case BuchungGeloescht = 'buchung.geloescht';
    /** Issue #64/M9-6: category or receipt status of an imported booking set by hand. */
    case BuchungEingeordnet = 'buchung.eingeordnet';
    case RegelAngelegt = 'regel.angelegt';
    case RegelGeaendert = 'regel.geaendert';
    case RegelGeloescht = 'regel.geloescht';
    case RegelAktiviert = 'regel.aktiviert';
    case RegelDeaktiviert = 'regel.deaktiviert';
    case RegelAngewendet = 'regel.angewendet';

    // Matching receipt <-> booking (M10-1, issue #65): ids and method only,
    // never an amount.
    case ZuordnungAngelegt = 'zuordnung.angelegt';
    case ZuordnungGeloest = 'zuordnung.geloest';
    case BelegDifferenzAbgeschlossen = 'beleg.differenz_abgeschlossen';
    case BelegDifferenzGeoeffnet = 'beleg.differenz_geoeffnet';
    // The matching run (M10-2, issue #66): counts only.
    case AbgleichAusgefuehrt = 'abgleich.ausgefuehrt';

    // Transfers between own accounts (M10-6, issue #70): the two booking
    // ids and the method, never an amount.
    case UmbuchungVerknuepft = 'umbuchung.verknuepft';
    case UmbuchungGeloest = 'umbuchung.geloest';
    case ZahllaufAngelegt = 'zahllauf.angelegt';
    case ZahllaufHeruntergeladen = 'zahllauf.heruntergeladen';
    case ZahllaufVerworfen = 'zahllauf.verworfen';

    /** No acting user (public submission, issue #24/M4-2): $userId is null. */
    case EinreichungEingegangen = 'einreichung.eingegangen';
    /** Internal capture (issue #28/M4-6): the capturing account acts. */
    case BelegErfasst = 'beleg.erfasst';
    // The inbox (issue #27/M4-5): status changes and the cost center.
    case BelegAngenommen = 'beleg.angenommen';
    case BelegAbgelehnt = 'beleg.abgelehnt';
    case BelegWiedervorlage = 'beleg.wiedervorlage';
    case BelegKostenstelle = 'beleg.kostenstelle';
    // The review page (issue #37/M6-3): details name the changed fields,
    // never their values.
    case BelegBearbeitet = 'beleg.bearbeitet';
    case BelegGeprueft = 'beleg.geprueft';
    // Festschreibung (issue #38/M6-4): lifting it records the reason.
    case BelegFestgeschrieben = 'beleg.festgeschrieben';
    case BelegFestschreibungAufgehoben = 'beleg.festschreibung_aufgehoben';
    // Duplicate detection (issue #40/M6-6): details name the other
    // documents (ids) and the reasons, never a value.
    case BelegDuplikatVerworfen = 'beleg.duplikat_verworfen';
    case BelegDuplikatBehalten = 'beleg.duplikat_behalten';
    // The AI extraction (issue #47/M7-5): a person asks for a new run.
    case BelegKiErneut = 'beleg.ki_erneut';
    // Recurring series (issue #57/M8-1): details name the changed fields,
    // the status before or the document linked - never an amount.
    case SerieBestaetigt = 'serie.bestaetigt';
    case SerieGeaendert = 'serie.geaendert';
    case SerieVerworfen = 'serie.verworfen';
    case SerieBelegVerknuepft = 'serie.beleg_verknuepft';

    case EinstellungMail = 'einstellung.mail';
    case EinstellungSpeicher = 'einstellung.speicher';
    case EinstellungUpdateKanal = 'einstellung.update_kanal';
    case EinstellungEinreichung = 'einstellung.einreichung';
    case EinstellungExport = 'einstellung.export';
    /** Nextcloud target of the export stored or removed (issue #95). */
    case EinstellungNextcloud = 'einstellung.nextcloud';
    /** Issue #64/M9-6: whether income needs a receipt by default (E-17). */
    case EinstellungBelegStandard = 'einstellung.beleg_standard';
    /** Issue #66/M10-2: points and thresholds of the matching (field names only). */
    case EinstellungAbgleich = 'einstellung.abgleich';

    // The ZIP export (issue #76/M12-2): filter, counts, who - never a name.
    case ExportErstellt = 'export.erstellt';
    // Export runs (issue #179/M12-4): the run id and the number of receipts.
    case ExportMarkiert = 'export.markiert';
    case ExportZurueckgesetzt = 'export.zurueckgesetzt';
    /** Every file of an export run filed into the Nextcloud (issue #95). */
    case ExportNextcloud = 'export.nextcloud';
    // The archive import (issue #77/M12-3): counts and document ids - never a name.
    case ArchivImportiert = 'archiv.importiert';

    // The optional worker (issue #51/M7b-1): the worker id, never a key.
    case WorkerKopplungscode = 'worker.kopplungscode';
    case WorkerGekoppelt = 'worker.gekoppelt';
    case WorkerBestaetigt = 'worker.bestaetigt';
    case WorkerEntkoppelt = 'worker.entkoppelt';

    case UpdateUmgeschaltet = 'update.umgeschaltet';
    case UpdateZurueckgerollt = 'update.zurueckgerollt';
    case WartungAufgehoben = 'wartung.aufgehoben';

    // Backups page (issue #112): file name and whether config.php is in it.
    case BackupErstellt = 'backup.erstellt';
    case BackupHeruntergeladen = 'backup.heruntergeladen';

    public function label(): string
    {
        return match ($this) {
            self::LoginErfolg => 'Anmeldung',
            self::LoginFehlgeschlagen => 'Anmeldung fehlgeschlagen',
            self::ZweiterFaktorFehlgeschlagen => 'Zweiter Faktor fehlgeschlagen',
            self::Logout => 'Abmeldung',
            self::TotpEingerichtet => 'Authenticator-App eingerichtet',
            self::EmailCodeEingerichtet => 'E-Mail-Code eingerichtet',
            self::BackupCodesNeu => 'Backup-Codes neu erzeugt',
            self::GeraetWiderrufen => 'Gemerktes Gerät entfernt',
            self::PasswortGeaendert => 'Passwort geändert',
            self::PasswortResetAngefordert => 'Passwort-Reset angefordert',
            self::PasswortResetAbgeschlossen => 'Passwort zurückgesetzt',
            self::BenutzerEingeladen => 'Benutzer eingeladen',
            self::EinladungErneut => 'Einladung erneut gesendet',
            self::EinladungAngenommen => 'Einladung angenommen',
            self::BenutzerGeaendert => 'Benutzer geändert',
            self::BenutzerGesperrt => 'Benutzer gesperrt',
            self::BenutzerEntsperrt => 'Benutzer entsperrt',
            self::TresorFreigegeben => 'Tresor freigegeben',
            self::TresorEntzogen => 'Tresor-Freigabe entzogen',
            self::TresorWiederhergestellt => 'Tresor mit Wiederherstellungsschlüssel entsperrt',
            self::TresorWiederherstellungFehlgeschlagen => 'Wiederherstellungsschlüssel fehlgeschlagen',
            self::ZugangNeuVerknuepft => 'Zugang nach Wiederherstellung neu verknüpft',
            self::TresorRotationGestartet => 'Tresor-Schlüsselrotation gestartet',
            self::TresorRotationSchluesselBestaetigt => 'Neuer Wiederherstellungsschlüssel bestätigt',
            self::TresorRotationAbgeschlossen => 'Tresor-Schlüsselrotation abgeschlossen',
            self::TresorRotationAbgebrochen => 'Tresor-Schlüsselrotation abgebrochen',
            self::RolleAngelegt => 'Rolle angelegt',
            self::RolleGeaendert => 'Rolle geändert',
            self::RolleGeloescht => 'Rolle gelöscht',
            self::KostenstelleAngelegt => 'Kostenstelle angelegt',
            self::KostenstelleGeaendert => 'Kostenstelle geändert',
            self::KostenstelleGeloescht => 'Kostenstelle gelöscht',
            self::KategorieAngelegt => 'Kategorie angelegt',
            self::KategorieGeaendert => 'Kategorie geändert',
            self::KategorieGeloescht => 'Kategorie gelöscht',
            self::LieferantAngelegt => 'Lieferant angelegt',
            self::LieferantGeaendert => 'Lieferant geändert',
            self::LieferantGeloescht => 'Lieferant gelöscht',
            self::LieferantZusammengefuehrt => 'Lieferanten zusammengeführt',
            self::LieferantGeprueft => 'Neuen Lieferanten geprüft',
            self::KontoAngelegt => 'Konto angelegt',
            self::KontoGeaendert => 'Konto geändert',
            self::KontoGeloescht => 'Konto gelöscht',
            self::KassensturzErfasst => 'Kassensturz erfasst',
            self::CsvFormatAngelegt => 'CSV-Format angelegt',
            self::CsvFormatGeaendert => 'CSV-Format geändert',
            self::CsvFormatGeloescht => 'CSV-Format gelöscht',
            self::KiAnbieterAngelegt => 'KI-Anbieter angelegt',
            self::KiAnbieterGeaendert => 'KI-Anbieter geändert',
            self::KiAnbieterGeloescht => 'KI-Anbieter gelöscht',
            self::KiAnbieterStandard => 'KI-Standardprofil gewählt',
            self::KiFallbackGewaehlt => 'KI-Fallback-Profil gewählt',
            self::KiFallbackEntfernt => 'KI-Fallback-Profil entfernt',
            self::KontoauszugImportiert => 'Kontoauszug importiert',
            self::BuchungAngelegt => 'Buchung erfasst',
            self::BuchungGeaendert => 'Buchung geändert',
            self::BuchungGeloescht => 'Buchung gelöscht',
            self::BuchungEingeordnet => 'Buchung eingeordnet',
            self::RegelAngelegt => 'Buchungsregel angelegt',
            self::RegelGeaendert => 'Buchungsregel geändert',
            self::RegelGeloescht => 'Buchungsregel gelöscht',
            self::RegelAktiviert => 'Buchungsregel aktiviert',
            self::RegelDeaktiviert => 'Buchungsregel deaktiviert',
            self::RegelAngewendet => 'Buchungsregel angewendet',
            self::ZuordnungAngelegt => 'Beleg einer Buchung zugeordnet',
            self::ZuordnungGeloest => 'Zuordnung Beleg ↔ Buchung gelöst',
            self::BelegDifferenzAbgeschlossen => 'Zahlungsdifferenz des Belegs abgeschlossen',
            self::BelegDifferenzGeoeffnet => 'Zahlungsdifferenz des Belegs wieder geöffnet',
            self::UmbuchungVerknuepft => 'Umbuchung zwischen eigenen Konten verknüpft',
            self::UmbuchungGeloest => 'Umbuchung zwischen eigenen Konten gelöst',
            self::ZahllaufAngelegt => 'SEPA-Zahllauf für Erstattungen angelegt',
            self::ZahllaufHeruntergeladen => 'SEPA-Datei eines Zahllaufs heruntergeladen',
            self::ZahllaufVerworfen => 'SEPA-Zahllauf verworfen',
            self::EinstellungBelegStandard => 'Beleg-Standard für Einnahmen geändert',
            self::EinstellungAbgleich => 'Punktebewertung des Abgleichs geändert',
            self::AbgleichAusgefuehrt => 'Abgleich ausgeführt',
            self::EinreichungEingegangen => 'Einreichung eingegangen',
            self::BelegErfasst => 'Beleg intern erfasst',
            self::BelegAngenommen => 'Beleg angenommen',
            self::BelegAbgelehnt => 'Beleg abgelehnt',
            self::BelegWiedervorlage => 'Beleg auf Wiedervorlage gelegt',
            self::BelegKostenstelle => 'Kostenstelle des Belegs geändert',
            self::BelegBearbeitet => 'Beleg erfasst/bearbeitet',
            self::BelegGeprueft => 'Beleg geprüft',
            self::BelegFestgeschrieben => 'Beleg festgeschrieben',
            self::BelegFestschreibungAufgehoben => 'Festschreibung aufgehoben',
            self::BelegDuplikatVerworfen => 'Beleg als Duplikat verworfen',
            self::BelegDuplikatBehalten => 'Duplikat-Verdacht: bewusst behalten',
            self::BelegKiErneut => 'KI-Auslesen neu angestoßen',
            self::SerieBestaetigt => 'Wiederkehrende Serie bestätigt',
            self::SerieGeaendert => 'Wiederkehrende Serie geändert',
            self::SerieVerworfen => 'Wiederkehrende Serie verworfen',
            self::SerieBelegVerknuepft => 'Beleg einer Serie zugeordnet',
            self::EinstellungMail => 'Mail-Einstellungen geändert',
            self::EinstellungSpeicher => 'Speicher-Backend geändert',
            self::EinstellungUpdateKanal => 'Update-Kanal geändert',
            self::EinstellungEinreichung => 'Einreichungs-Einstellungen geändert',
            self::EinstellungExport => 'Export-Muster geändert',
            self::EinstellungNextcloud => 'Nextcloud-Ablage eingerichtet/geändert',
            self::ExportErstellt => 'ZIP-Export erstellt',
            self::ExportMarkiert => 'Export als vollständig bestätigt',
            self::ExportZurueckgesetzt => 'Export-Markierung zurückgesetzt',
            self::ExportNextcloud => 'Export in Nextcloud abgelegt',
            self::ArchivImportiert => 'Archiv importiert',
            self::WorkerKopplungscode => 'Worker-Kopplungscode erzeugt',
            self::WorkerGekoppelt => 'Worker gekoppelt',
            self::WorkerBestaetigt => 'Worker-Fingerabdruck bestätigt',
            self::WorkerEntkoppelt => 'Worker entkoppelt',
            self::UpdateUmgeschaltet => 'Update eingespielt',
            self::UpdateZurueckgerollt => 'Update zurückgerollt',
            self::WartungAufgehoben => 'Wartungsmodus aufgehoben',
            self::BackupErstellt => 'Backup erstellt',
            self::BackupHeruntergeladen => 'Backup heruntergeladen',
        };
    }

    /**
     * What `entity_id` refers to, or null when the action has no object of
     * its own (a setting, an update).
     */
    public function entity(): ?string
    {
        return match ($this) {
            self::LoginErfolg, self::ZweiterFaktorFehlgeschlagen, self::Logout,
            self::TotpEingerichtet, self::EmailCodeEingerichtet, self::BackupCodesNeu,
            self::PasswortGeaendert, self::PasswortResetAngefordert, self::PasswortResetAbgeschlossen,
            self::BenutzerEingeladen, self::EinladungErneut, self::EinladungAngenommen,
            self::BenutzerGeaendert, self::BenutzerGesperrt, self::BenutzerEntsperrt,
            self::TresorFreigegeben, self::TresorEntzogen,
            self::TresorWiederhergestellt, self::TresorWiederherstellungFehlgeschlagen,
            self::ZugangNeuVerknuepft => 'user',
            self::TresorRotationGestartet, self::TresorRotationSchluesselBestaetigt,
            self::TresorRotationAbgeschlossen, self::TresorRotationAbgebrochen => 'vault',
            self::GeraetWiderrufen => 'trusted_device',
            self::RolleAngelegt, self::RolleGeaendert, self::RolleGeloescht => 'role',
            self::KostenstelleAngelegt, self::KostenstelleGeaendert, self::KostenstelleGeloescht => 'cost_center',
            self::KategorieAngelegt, self::KategorieGeaendert, self::KategorieGeloescht => 'category',
            self::LieferantAngelegt, self::LieferantGeaendert, self::LieferantGeloescht,
            self::LieferantZusammengefuehrt, self::LieferantGeprueft => 'supplier',
            self::KontoAngelegt, self::KontoGeaendert, self::KontoGeloescht => 'bank_account',
            self::KassensturzErfasst => 'cash_count',
            self::CsvFormatAngelegt, self::CsvFormatGeaendert, self::CsvFormatGeloescht => 'csv_profile',
            self::KiAnbieterAngelegt, self::KiAnbieterGeaendert, self::KiAnbieterGeloescht,
            self::KiAnbieterStandard, self::KiFallbackGewaehlt, self::KiFallbackEntfernt => 'ai_provider',
            self::KontoauszugImportiert => 'bank_import',
            self::ArchivImportiert => 'archive_import',
            self::WorkerGekoppelt, self::WorkerBestaetigt, self::WorkerEntkoppelt => 'worker',
            self::ExportMarkiert, self::ExportZurueckgesetzt, self::ExportNextcloud => 'export_run',
            self::BuchungAngelegt, self::BuchungGeaendert, self::BuchungGeloescht,
            self::BuchungEingeordnet => 'bank_transaction',
            self::RegelAngelegt, self::RegelGeaendert, self::RegelGeloescht,
            self::RegelAktiviert, self::RegelDeaktiviert, self::RegelAngewendet => 'assignment_rule',
            self::ZuordnungAngelegt, self::ZuordnungGeloest => 'allocation',
            self::UmbuchungVerknuepft, self::UmbuchungGeloest => 'transfer',
            self::ZahllaufAngelegt, self::ZahllaufHeruntergeladen, self::ZahllaufVerworfen => 'payment_run',
            self::EinreichungEingegangen, self::BelegErfasst,
            self::BelegAngenommen, self::BelegAbgelehnt, self::BelegWiedervorlage, self::BelegKostenstelle,
            self::BelegBearbeitet, self::BelegGeprueft,
            self::BelegFestgeschrieben, self::BelegFestschreibungAufgehoben,
            self::BelegDuplikatVerworfen, self::BelegDuplikatBehalten, self::BelegKiErneut,
            self::BelegDifferenzAbgeschlossen, self::BelegDifferenzGeoeffnet => 'document',
            self::SerieBestaetigt, self::SerieGeaendert, self::SerieVerworfen,
            self::SerieBelegVerknuepft => 'recurring_series',
            self::LoginFehlgeschlagen,
            self::EinstellungMail, self::EinstellungSpeicher, self::EinstellungUpdateKanal,
            self::EinstellungEinreichung, self::EinstellungExport, self::EinstellungBelegStandard,
            self::EinstellungNextcloud, self::EinstellungAbgleich, self::AbgleichAusgefuehrt,
            self::ExportErstellt, self::WorkerKopplungscode,
            self::UpdateUmgeschaltet, self::UpdateZurueckgerollt, self::WartungAufgehoben,
            self::BackupErstellt, self::BackupHeruntergeladen => null,
        };
    }

    /**
     * Label of an `entity` value, for the list and its filter.
     */
    public static function entityLabel(string $entity): string
    {
        return match ($entity) {
            'user' => 'Benutzer',
            'trusted_device' => 'Gerät',
            'role' => 'Rolle',
            'cost_center' => 'Kostenstelle',
            'category' => 'Kategorie',
            'supplier' => 'Lieferant',
            'bank_account' => 'Konto',
            'cash_count' => 'Kassensturz',
            'csv_profile' => 'CSV-Format',
            'ai_provider' => 'KI-Anbieter',
            'bank_import' => 'Kontoauszug-Import',
            'archive_import' => 'Archiv-Import',
            'export_run' => 'Export',
            'bank_transaction' => 'Buchung',
            'assignment_rule' => 'Buchungsregel',
            'allocation' => 'Zuordnung Beleg ↔ Buchung',
            'transfer' => 'Umbuchung',
            'payment_run' => 'Zahllauf',
            'document' => 'Beleg',
            'worker' => 'Worker',
            'vault' => 'Tresor-Generation',
            default => $entity,
        };
    }

    /**
     * @return list<string> every `entity` value some action uses
     */
    public static function entities(): array
    {
        $alle = [];
        foreach (self::cases() as $aktion) {
            $entity = $aktion->entity();
            if ($entity !== null) {
                $alle[$entity] = true;
            }
        }

        return array_keys($alle);
    }
}
