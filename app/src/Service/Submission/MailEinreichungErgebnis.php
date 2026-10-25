<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * The outcome of App\Service\Submission\MailEinreichung::einreichen()
 * (issue #87): a new receipt, one made from the same mail before, or a
 * refusal with an identifier (never text from the mail).
 */
final readonly class MailEinreichungErgebnis
{
    public const string EINGEREICHT = 'eingereicht';

    public const string VORHANDEN = 'vorhanden';

    public const string ABGELEHNT = 'abgelehnt';

    private function __construct(
        public string $status,
        public ?string $referenz,
        public ?string $fehler,
    ) {
    }

    public static function eingereicht(string $referenz): self
    {
        return new self(self::EINGEREICHT, $referenz, null);
    }

    public static function vorhanden(string $referenz): self
    {
        return new self(self::VORHANDEN, $referenz, null);
    }

    public static function abgelehnt(string $fehler): self
    {
        return new self(self::ABGELEHNT, null, $fehler);
    }
}
