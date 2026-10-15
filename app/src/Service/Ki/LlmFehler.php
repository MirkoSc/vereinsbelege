<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * A chat call brought no usable answer: no connection, timeout, an HTTP
 * error status, or a 2xx body that is no chat completion with text.
 *
 * The message is the German status text (plus the HTTP hint) and never
 * carries the provider's own words: those may echo parts of the request.
 * They are kept apart in $anbieterText - shortened and without the API key
 * - for whoever decides to show them to an admin.
 */
final class LlmFehler extends \RuntimeException
{
    private function __construct(
        string $meldung,
        public readonly KiAufrufStatus $status,
        public readonly ?int $httpStatus = null,
        public readonly string $anbieterText = '',
    ) {
        parent::__construct($meldung);
    }

    public static function ausVerbindungsfehler(KiVerbindungsfehler $fehler, #[\SensitiveParameter] string $apiKey): self
    {
        $status = $fehler->zeitueberschreitung ? KiAufrufStatus::Zeitueberschreitung : KiAufrufStatus::Verbindungsfehler;

        return new self($status->meldung(), $status, null, KiAnbieterText::bereinigt($fehler->getMessage(), $apiKey));
    }

    public static function http(int $httpStatus, string $body, #[\SensitiveParameter] string $apiKey): self
    {
        return new self(
            KiAufrufStatus::HttpFehler->meldung() . ' ' . KiAnbieterText::httpMeldung($httpStatus),
            KiAufrufStatus::HttpFehler,
            $httpStatus,
            KiAnbieterText::fehlertext($body, $apiKey),
        );
    }

    /**
     * @param string $grund German, without content of the answer
     */
    public static function unerwarteteAntwort(string $grund): self
    {
        return new self(KiAufrufStatus::UnerwarteteAntwort->meldung() . ' ' . $grund, KiAufrufStatus::UnerwarteteAntwort);
    }
}
