<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionSource;

/**
 * The filters of the booking list /app/buchungen (M9-5, issue #63,
 * docs/spec/04-bank-und-abgleich.md section 1). Everything but the search
 * text is plaintext structure and filters in SQL
 * (App\Repository\BankTransactionRepository::liste()); the search text
 * looks into purpose and counterparty, which only PHP can read after
 * decrypting.
 *
 * `regel` narrows the list to the bookings one rule has acted on (M9-6) -
 * linked from the rule, so its effect can be looked at.
 *
 * Without dates in the query the list shows the current financial year
 * (= calendar year, E-16) - a club has a few thousand bookings a year, and
 * every one shown is decrypted. Sending the form with empty dates lifts
 * that limit on purpose.
 */
final readonly class BuchungFilter
{
    /** `kategorie` value for "no category assigned". */
    public const string OHNE_KATEGORIE = 'ohne';

    public const int SUCHE_MAX = 100;

    /**
     * @param int|null $kategorieId null = every category, 0 = bookings without one
     */
    public function __construct(
        public ?int $kontoId = null,
        public ?\DateTimeImmutable $von = null,
        public ?\DateTimeImmutable $bis = null,
        public ?BankTransactionDirection $richtung = null,
        public ?BankTransactionDocStatus $belegStatus = null,
        public ?BankTransactionSource $quelle = null,
        public ?int $kategorieId = null,
        public string $suche = '',
        public ?int $regelId = null,
    ) {
    }

    /**
     * Reads the list's query string. Unknown or malformed values are
     * ignored rather than refused - it is a filter, not a form.
     *
     * @param array<string, mixed> $query
     */
    public static function ausAbfrage(array $query, \DateTimeImmutable $heute): self
    {
        $text = static fn(string $feld): string => is_string($query[$feld] ?? null) ? trim($query[$feld]) : '';
        $id = static fn(string $feld): ?int => ctype_digit($text($feld)) && (int) $text($feld) > 0 ? (int) $text($feld) : null;
        $datum = static function (string $feld) use ($text): ?\DateTimeImmutable {
            $wert = $text($feld);
            $tag = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

            return $tag !== false && $tag->format('Y-m-d') === $wert ? $tag : null;
        };

        // Dates absent from the query: the current year. Present but empty:
        // no limit - the reader cleared them.
        $mitDatum = array_key_exists('von', $query) || array_key_exists('bis', $query);
        $jahr = (int) $heute->format('Y');

        return new self(
            kontoId: $id('konto'),
            von: $mitDatum ? $datum('von') : $heute->setDate($jahr, 1, 1)->setTime(0, 0),
            bis: $mitDatum ? $datum('bis') : $heute->setDate($jahr, 12, 31)->setTime(0, 0),
            richtung: BankTransactionDirection::tryFrom($text('richtung')),
            belegStatus: BankTransactionDocStatus::tryFrom($text('beleg')),
            quelle: BankTransactionSource::tryFrom($text('quelle')),
            kategorieId: $text('kategorie') === self::OHNE_KATEGORIE ? 0 : $id('kategorie'),
            suche: mb_substr($text('suche'), 0, self::SUCHE_MAX),
            regelId: $id('regel'),
        );
    }

    /** Whether anything narrows the list beyond the default year - for "Filter zurücksetzen". */
    public function aktiv(\DateTimeImmutable $heute): bool
    {
        $standard = self::ausAbfrage([], $heute);

        return $this->kontoId !== null || $this->richtung !== null || $this->belegStatus !== null
            || $this->quelle !== null || $this->kategorieId !== null || $this->suche !== '' || $this->regelId !== null
            || $this->von?->format('Y-m-d') !== $standard->von?->format('Y-m-d')
            || $this->bis?->format('Y-m-d') !== $standard->bis?->format('Y-m-d');
    }

    /** Whether a decrypted booking matches the search text (case-insensitive, purpose and counterparty). */
    public function passtZurSuche(string ...$felder): bool
    {
        if ($this->suche === '') {
            return true;
        }
        foreach ($felder as $feld) {
            if (mb_stripos($feld, $this->suche) !== false) {
                return true;
            }
        }

        return false;
    }
}
