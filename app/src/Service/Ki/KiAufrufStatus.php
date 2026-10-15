<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * How one AI call ended (docs/spec/03-erfassung-und-ki.md section 6
 * "Client"): the "Ergebnisstatus" of the call log. Only an answer that is
 * there but does not fit - no JSON, or JSON against the schema - is worth
 * the one repair call; asking again does not cure a timeout, an HTTP error
 * or an answer that is no chat completion at all. Only an outage - the
 * provider did not answer, or said "try later" - is worth the one attempt
 * with the fallback profile (ausfall()).
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
     * The provider is out of reach for now: no connection, no answer in
     * time, or HTTP 429 (rate limit) / 5xx (its own trouble). Another
     * profile may still answer. Not an outage: a 4xx like 401/400 (key or
     * request is wrong - the fallback would get the same request), a 2xx
     * that is no chat completion, and answers that arrived but do not fit.
     */
    public function ausfall(?int $httpStatus): bool
    {
        return match ($this) {
            self::Zeitueberschreitung, self::Verbindungsfehler => true,
            self::HttpFehler => $httpStatus === 429 || ($httpStatus !== null && $httpStatus >= 500 && $httpStatus <= 599),
            default => false,
        };
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
