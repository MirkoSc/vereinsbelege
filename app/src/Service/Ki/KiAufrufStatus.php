<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * How one AI call ended (docs/spec/03-erfassung-und-ki.md section 6
 * "Client"): the "Ergebnisstatus" of the call log. Only an answer that is
 * there but does not fit - no JSON, or JSON against the schema - is worth
 * the one repair call; asking again does not cure a timeout, an HTTP error
 * or an answer that is no chat completion at all.
 */
enum KiAufrufStatus: string
{
    case Gueltig = 'gueltig';
    case KeinJson = 'kein_json';
    case SchemaVerletzt = 'schema_verletzt';
    case UnerwarteteAntwort = 'unerwartete_antwort';
    case HttpFehler = 'http_fehler';
    case Zeitueberschreitung = 'zeitueberschreitung';
    case Verbindungsfehler = 'verbindungsfehler';

    public function reparierbar(): bool
    {
        return $this === self::KeinJson || $this === self::SchemaVerletzt;
    }

    /**
     * German, for the receipt and the log - never with content of the
     * answer, so it may stand anywhere.
     */
    public function meldung(): string
    {
        return match ($this) {
            self::Gueltig => 'KI-Antwort gültig.',
            self::KeinJson => 'Die KI-Antwort enthielt kein JSON-Objekt.',
            self::SchemaVerletzt => 'Die KI-Antwort entsprach nicht dem Schema.',
            self::UnerwarteteAntwort => 'Der KI-Anbieter hat keine verwertbare Chat-Antwort geliefert.',
            self::HttpFehler => 'Der KI-Anbieter hat die Anfrage abgelehnt.',
            self::Zeitueberschreitung => 'Der KI-Anbieter hat nicht rechtzeitig geantwortet.',
            self::Verbindungsfehler => 'KI-Anbieter nicht erreichbar.',
        };
    }
}
