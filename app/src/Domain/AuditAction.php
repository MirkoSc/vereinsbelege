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

    case RolleAngelegt = 'rolle.angelegt';
    case RolleGeaendert = 'rolle.geaendert';
    case RolleGeloescht = 'rolle.geloescht';

    case KostenstelleAngelegt = 'kostenstelle.angelegt';
    case KostenstelleGeaendert = 'kostenstelle.geaendert';
    case KostenstelleGeloescht = 'kostenstelle.geloescht';

    /** No acting user (public submission, issue #24/M4-2): $userId is null. */
    case EinreichungEingegangen = 'einreichung.eingegangen';
    /** Internal capture (issue #28/M4-6): the capturing account acts. */
    case BelegErfasst = 'beleg.erfasst';
    // The inbox (issue #27/M4-5): status changes and the cost center.
    case BelegAngenommen = 'beleg.angenommen';
    case BelegAbgelehnt = 'beleg.abgelehnt';
    case BelegWiedervorlage = 'beleg.wiedervorlage';
    case BelegKostenstelle = 'beleg.kostenstelle';

    case EinstellungMail = 'einstellung.mail';
    case EinstellungSpeicher = 'einstellung.speicher';
    case EinstellungUpdateKanal = 'einstellung.update_kanal';
    case EinstellungEinreichung = 'einstellung.einreichung';

    case UpdateUmgeschaltet = 'update.umgeschaltet';
    case UpdateZurueckgerollt = 'update.zurueckgerollt';
    case WartungAufgehoben = 'wartung.aufgehoben';

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
            self::RolleAngelegt => 'Rolle angelegt',
            self::RolleGeaendert => 'Rolle geändert',
            self::RolleGeloescht => 'Rolle gelöscht',
            self::KostenstelleAngelegt => 'Kostenstelle angelegt',
            self::KostenstelleGeaendert => 'Kostenstelle geändert',
            self::KostenstelleGeloescht => 'Kostenstelle gelöscht',
            self::EinreichungEingegangen => 'Einreichung eingegangen',
            self::BelegErfasst => 'Beleg intern erfasst',
            self::BelegAngenommen => 'Beleg angenommen',
            self::BelegAbgelehnt => 'Beleg abgelehnt',
            self::BelegWiedervorlage => 'Beleg auf Wiedervorlage gelegt',
            self::BelegKostenstelle => 'Kostenstelle des Belegs geändert',
            self::EinstellungMail => 'Mail-Einstellungen geändert',
            self::EinstellungSpeicher => 'Speicher-Backend geändert',
            self::EinstellungUpdateKanal => 'Update-Kanal geändert',
            self::EinstellungEinreichung => 'Einreichungs-Einstellungen geändert',
            self::UpdateUmgeschaltet => 'Update eingespielt',
            self::UpdateZurueckgerollt => 'Update zurückgerollt',
            self::WartungAufgehoben => 'Wartungsmodus aufgehoben',
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
            self::TresorWiederhergestellt, self::TresorWiederherstellungFehlgeschlagen => 'user',
            self::GeraetWiderrufen => 'trusted_device',
            self::RolleAngelegt, self::RolleGeaendert, self::RolleGeloescht => 'role',
            self::KostenstelleAngelegt, self::KostenstelleGeaendert, self::KostenstelleGeloescht => 'cost_center',
            self::EinreichungEingegangen, self::BelegErfasst,
            self::BelegAngenommen, self::BelegAbgelehnt, self::BelegWiedervorlage, self::BelegKostenstelle => 'document',
            self::LoginFehlgeschlagen,
            self::EinstellungMail, self::EinstellungSpeicher, self::EinstellungUpdateKanal,
            self::EinstellungEinreichung,
            self::UpdateUmgeschaltet, self::UpdateZurueckgerollt, self::WartungAufgehoben => null,
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
            'document' => 'Beleg',
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
