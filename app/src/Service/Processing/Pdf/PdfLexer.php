<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * Reads PDF syntax (ISO 32000-1 section 7.2/7.3) from a string, one value or
 * keyword at a time: the objects of the file as well as the operands and
 * operators of a content stream. Values come back as plain PHP where that is
 * unambiguous - int/float, bool, null, a string's bytes, a list for an
 * array, an array keyed by name (without slash) for a dictionary - and as
 * PdfName/PdfRef/PdfKeyword where it is not.
 *
 * Works on the whole file in place (`$pos` moves through it), never on
 * copies of it: a 32 MB scan is read without duplicating its image data.
 */
final class PdfLexer
{
    public const string LEERRAUM = "\x00\t\n\x0C\r ";

    private const string TRENNER = "()<>[]{}/%";

    /** Nesting of arrays/dictionaries - real files stay far below, a crafted one must not exhaust the stack. */
    private const int MAX_TIEFE = 64;

    public int $pos;

    private readonly int $laenge;

    public function __construct(private readonly string $daten, int $pos = 0)
    {
        $this->pos = $pos;
        $this->laenge = strlen($daten);
    }

    public function amEnde(): bool
    {
        $this->leerraum();

        return $this->pos >= $this->laenge;
    }

    /** Skips whitespace and comments. */
    public function leerraum(): void
    {
        while ($this->pos < $this->laenge) {
            $this->pos += strspn($this->daten, self::LEERRAUM, $this->pos);
            if ($this->pos < $this->laenge && $this->daten[$this->pos] === '%') {
                $this->pos += strcspn($this->daten, "\r\n", $this->pos);
                continue;
            }
            break;
        }
    }

    /** Whether the next token is exactly this keyword (does not consume it). */
    public function folgt(string $keyword): bool
    {
        $this->leerraum();
        $ende = $this->pos + strlen($keyword);

        return substr_compare($this->daten, $keyword, $this->pos, strlen($keyword)) === 0
            && ($ende >= $this->laenge || self::trennt($this->daten[$ende]));
    }

    /**
     * The next value or keyword. `$referenzen` recognises `n g R` as a
     * PdfRef - in the file's objects; a content stream has none.
     */
    public function wert(bool $referenzen = true, int $tiefe = 0): mixed
    {
        if ($tiefe > self::MAX_TIEFE) {
            throw new PdfDefekt('Zu tief verschachtelt.');
        }
        $this->leerraum();
        if ($this->pos >= $this->laenge) {
            throw new PdfDefekt('Unerwartetes Ende.');
        }

        $zeichen = $this->daten[$this->pos];

        return match (true) {
            $zeichen === '/' => $this->name(),
            $zeichen === '(' => $this->literal(),
            $zeichen === '<' && ($this->daten[$this->pos + 1] ?? '') === '<' => $this->dictionary($referenzen, $tiefe),
            $zeichen === '<' => $this->hex(),
            $zeichen === '[' => $this->array($referenzen, $tiefe),
            default => $this->token($referenzen),
        };
    }

    /**
     * Skips the binary data of an inline image (`BI … ID <data> EI`): right
     * after the `ID` operator, up to and including the `EI` that follows
     * whitespace and is followed by whitespace or the end.
     */
    public function inlineBildUeberspringen(): void
    {
        if (preg_match('/[\x00\t\n\x0C\r ]EI(?=[\x00\t\n\x0C\r ]|$)/', $this->daten, $treffer, PREG_OFFSET_CAPTURE, $this->pos + 1) !== 1) {
            $this->pos = $this->laenge;

            return;
        }
        $this->pos = $treffer[0][1] + 3;
    }

    private function name(): PdfName
    {
        $this->pos++;
        $laenge = strcspn($this->daten, self::LEERRAUM . self::TRENNER, $this->pos);
        $roh = substr($this->daten, $this->pos, $laenge);
        $this->pos += $laenge;

        if (!str_contains($roh, '#')) {
            return new PdfName($roh);
        }

        return new PdfName((string) preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            static fn (array $m): string => chr((int) hexdec($m[1])),
            $roh,
        ));
    }

    private function literal(): string
    {
        $this->pos++;
        $ergebnis = '';
        $offen = 1;

        while ($this->pos < $this->laenge) {
            $stueck = strcspn($this->daten, '()\\', $this->pos);
            $ergebnis .= substr($this->daten, $this->pos, $stueck);
            $this->pos += $stueck;
            if ($this->pos >= $this->laenge) {
                break;
            }

            $zeichen = $this->daten[$this->pos++];
            if ($zeichen === '(') {
                $offen++;
                $ergebnis .= '(';
            } elseif ($zeichen === ')') {
                if (--$offen === 0) {
                    return $ergebnis;
                }
                $ergebnis .= ')';
            } else {
                $ergebnis .= $this->escape();
            }
        }

        throw new PdfDefekt('Zeichenkette nicht abgeschlossen.');
    }

    /** The character after a backslash in a literal string (ISO 32000-1 table 3). */
    private function escape(): string
    {
        if ($this->pos >= $this->laenge) {
            return '';
        }
        $zeichen = $this->daten[$this->pos++];

        switch ($zeichen) {
            case 'n':
                return "\n";
            case 'r':
                return "\r";
            case 't':
                return "\t";
            case 'b':
                return "\x08";
            case 'f':
                return "\x0C";
            case "\r":
                // Line continuation: backslash + EOL produces nothing.
                if (($this->daten[$this->pos] ?? '') === "\n") {
                    $this->pos++;
                }

                return '';
            case "\n":
                return '';
        }

        if ($zeichen >= '0' && $zeichen <= '7') {
            $oktal = $zeichen;
            while (strlen($oktal) < 3 && $this->pos < $this->laenge && $this->daten[$this->pos] >= '0' && $this->daten[$this->pos] <= '7') {
                $oktal .= $this->daten[$this->pos++];
            }

            return chr(octdec($oktal) & 0xFF);
        }

        // `\(`, `\)`, `\\` and any other character stand for themselves.
        return $zeichen;
    }

    private function hex(): string
    {
        $this->pos++;
        $ende = strpos($this->daten, '>', $this->pos);
        if ($ende === false) {
            throw new PdfDefekt('Hex-Zeichenkette nicht abgeschlossen.');
        }
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($this->daten, $this->pos, $ende - $this->pos));
        $this->pos = $ende + 1;
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return (string) hex2bin($hex);
    }

    /**
     * @return list<mixed>
     */
    private function array(bool $referenzen, int $tiefe): array
    {
        $this->pos++;
        $werte = [];
        while (true) {
            $this->leerraum();
            if ($this->pos >= $this->laenge) {
                throw new PdfDefekt('Array nicht abgeschlossen.');
            }
            if ($this->daten[$this->pos] === ']') {
                $this->pos++;

                return $werte;
            }
            $werte[] = $this->wert($referenzen, $tiefe + 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dictionary(bool $referenzen, int $tiefe): array
    {
        $this->pos += 2;
        $werte = [];
        while (true) {
            $this->leerraum();
            if ($this->pos >= $this->laenge) {
                throw new PdfDefekt('Dictionary nicht abgeschlossen.');
            }
            if ($this->daten[$this->pos] === '>' && ($this->daten[$this->pos + 1] ?? '') === '>') {
                $this->pos += 2;

                return $werte;
            }
            $schluessel = $this->wert($referenzen, $tiefe + 1);
            if (!$schluessel instanceof PdfName) {
                throw new PdfDefekt('Dictionary-Schlüssel ist kein Name.');
            }
            $werte[$schluessel->wert] = $this->wert($referenzen, $tiefe + 1);
        }
    }

    private function token(bool $referenzen): mixed
    {
        $laenge = strcspn($this->daten, self::LEERRAUM . self::TRENNER, $this->pos);
        if ($laenge === 0) {
            // A stray delimiter (`)`, `>`, `{`, `}`) - hand it on as a
            // keyword so the caller can skip it.
            return new PdfKeyword($this->daten[$this->pos++]);
        }
        $token = substr($this->daten, $this->pos, $laenge);
        $this->pos += $laenge;

        if (preg_match('/^[+-]?\d+$/', $token) === 1) {
            // More digits than an int holds: a float, never a cast that
            // PHP 8.5 warns about.
            if (strlen(ltrim($token, '+-0')) > 18) {
                return (float) $token;
            }
            $zahl = (int) $token;

            return $referenzen && $zahl >= 0 ? $this->referenz($zahl) : $zahl;
        }
        if (preg_match('/^[+-]?(\d+\.\d*|\.\d+)$/', $token) === 1) {
            return (float) $token;
        }

        return match ($token) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => new PdfKeyword($token),
        };
    }

    /** `n g R` after an integer n - otherwise n alone, the position unchanged. */
    private function referenz(int $nummer): int|PdfRef
    {
        if (preg_match('/\G[\x00\t\n\x0C\r ]+\d+[\x00\t\n\x0C\r ]+R(?=[\x00\t\n\x0C\r ()<>\[\]{}\/%]|$)/', $this->daten, $treffer, 0, $this->pos) === 1) {
            $this->pos += strlen($treffer[0]);

            return new PdfRef($nummer);
        }

        return $nummer;
    }

    private static function trennt(string $zeichen): bool
    {
        return str_contains(self::LEERRAUM . self::TRENNER, $zeichen);
    }
}
