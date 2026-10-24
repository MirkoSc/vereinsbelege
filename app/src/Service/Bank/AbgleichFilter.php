<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Service\Processing\Betrag;

/**
 * Filters and paging of the matching page (M10-4, issue #68, docs/spec/
 * 04-bank-und-abgleich.md section 5 "Stand M10-4", E-41).
 *
 * Structure only travels in the URL (GET): account, period, the selected
 * receipt, the pages. Amount and search text are club data (CLAUDE.md
 * section 4, like the search, 03 section 10) and only arrive by POST - the
 * page is then rendered directly, never redirected with them.
 */
final readonly class AbgleichFilter
{
    public const int SEITE = 25;

    public const int SUCHE_MAX = 100;

    public function __construct(
        public ?int $kontoId = null,
        public ?\DateTimeImmutable $von = null,
        public ?\DateTimeImmutable $bis = null,
        public ?int $betrag = null,
        public string $suche = '',
        public ?int $belegId = null,
        public int $seiteBelege = 1,
        public int $seiteBuchungen = 1,
    ) {
    }

    /**
     * @param array<string, mixed> $query the GET parameters
     * @param array<string, mixed> $post the POST fields (amount, search) - empty for a GET
     */
    public static function aus(array $query, array $post = []): self
    {
        $alle = $post === [] ? $query : $post;
        $text = static function (array $quelle, string $feld): string {
            $wert = $quelle[$feld] ?? '';

            return is_string($wert) ? trim($wert) : '';
        };
        $id = static function (string $wert): ?int {
            return ctype_digit($wert) && strlen($wert) <= 18 && (int) $wert > 0 ? (int) $wert : null;
        };
        $datum = static function (string $wert): ?\DateTimeImmutable {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

            return $d !== false && $d->format('Y-m-d') === $wert ? $d : null;
        };
        $seite = static function (string $wert): int {
            return ctype_digit($wert) && strlen($wert) <= 6 ? max(1, (int) $wert) : 1;
        };
        $betrag = $post === [] ? null : Betrag::parse($text($post, 'betrag'));

        return new self(
            kontoId: $id($text($alle, 'konto')),
            von: $datum($text($alle, 'von')),
            bis: $datum($text($alle, 'bis')),
            betrag: $betrag === null ? null : abs($betrag),
            suche: $post === [] ? '' : mb_substr($text($post, 'suche'), 0, self::SUCHE_MAX),
            belegId: $id($text($alle, 'beleg')),
            seiteBelege: $seite($text($alle, 'seite_belege')),
            seiteBuchungen: $seite($text($alle, 'seite_buchungen')),
        );
    }

    /** Whether amount or search are set - then paging must POST them again. */
    public function vertraulich(): bool
    {
        return $this->betrag !== null || $this->suche !== '';
    }

    /**
     * The structural part for a URL (GET links and redirects).
     *
     * @param array<string, int|string|null> $aendern
     *
     * @return array<string, string>
     */
    public function abfrage(array $aendern = []): array
    {
        $werte = [
            'konto' => $this->kontoId,
            'von' => $this->von?->format('Y-m-d'),
            'bis' => $this->bis?->format('Y-m-d'),
            'beleg' => $this->belegId,
            'seite_belege' => $this->seiteBelege,
            'seite_buchungen' => $this->seiteBuchungen,
            ...$aendern,
        ];

        foreach (['seite_belege', 'seite_buchungen'] as $seite) {
            if ($werte[$seite] === 1) {
                $werte[$seite] = null;
            }
        }

        return array_map(strval(...), array_filter($werte, static fn(int|string|null $w): bool => $w !== null && $w !== ''));
    }

    /**
     * The URL of the page with the structural filters, optionally changed.
     *
     * @param array<string, int|string|null> $aendern
     */
    public function url(array $aendern = []): string
    {
        $abfrage = http_build_query($this->abfrage($aendern));

        return '/app/abgleich' . ($abfrage === '' ? '' : '?' . $abfrage);
    }

    /** Whether a date lies in the period. */
    public function imZeitraum(\DateTimeImmutable $datum): bool
    {
        $tag = $datum->format('Y-m-d');

        return ($this->von === null || $tag >= $this->von->format('Y-m-d'))
            && ($this->bis === null || $tag <= $this->bis->format('Y-m-d'));
    }

    /** Whether one of the amounts (cents, any sign) is the filtered one. */
    public function passtZumBetrag(int ...$betraege): bool
    {
        if ($this->betrag === null) {
            return true;
        }
        foreach ($betraege as $b) {
            if (abs($b) === $this->betrag) {
                return true;
            }
        }

        return false;
    }

    /** Whether one of the texts contains the search text (case-insensitive, spaces aside). */
    public function passtZurSuche(string ...$felder): bool
    {
        if ($this->suche === '') {
            return true;
        }
        // Spaces do not count, so a grouped IBAN finds the stored one.
        $kompakt = preg_replace('/\s+/u', '', $this->suche) ?? $this->suche;
        foreach ($felder as $feld) {
            if (mb_stripos($feld, $this->suche) !== false
                || ($kompakt !== '' && mb_stripos(preg_replace('/\s+/u', '', $feld) ?? $feld, $kompakt) !== false)
            ) {
                return true;
            }
        }

        return false;
    }
}
