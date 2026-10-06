<?php

declare(strict_types=1);

namespace App\Service\Processing\Pdf;

/**
 * Runs the text operators of a page's content streams (ISO 32000-1 section
 * 9.4) and writes down what they show, in drawing order.
 *
 * Positions are tracked through the text matrix and the CTM, exactly as far
 * as needed to put a line break where the baseline moves and a space where
 * the gap to the previous glyph is wider than a kerning adjustment - not to
 * lay out the page. Form XObjects are entered (some generators put the whole
 * page into one); images, inline images and everything graphical are
 * skipped without decoding.
 */
final class PdfInhalt
{
    /** Gap to the previous glyph, in em of the current font size, from which a space is inserted. */
    private const float WORTABSTAND = 0.15;

    /** Baseline shift, in em, from which the text continues on a new line. */
    private const float ZEILENWECHSEL = 0.5;

    /** Nesting of Form XObjects. */
    private const int MAX_FORM_TIEFE = 8;

    /** Operators per page - a content stream of a real invoice has a few thousand. */
    private const int MAX_OPERATOREN = 2_000_000;

    private string $text = '';

    private int $operatoren = 0;

    /** @var array{float, float}|null device position after the last glyph shown */
    private ?array $ende = null;

    /** Font size in device space of the last glyph shown. */
    private float $letzteGroesse = 0.0;

    /** @var array<int, PdfSchrift> font objects already read, by object number */
    private array $schriften = [];

    public function __construct(
        private readonly PdfDokument $dokument,
        private readonly int $maxZeichen,
    ) {
    }

    /**
     * The text of one page.
     *
     * @param array<string, mixed> $seite
     * @param array<string, mixed> $resourcen
     */
    public function seite(array $seite, array $resourcen): string
    {
        $this->text = '';
        $this->ende = null;
        $this->operatoren = 0;

        $inhalt = $this->dokument->aufloesen($seite['Contents'] ?? null);
        $teile = [];
        foreach (is_array($inhalt) ? $inhalt : [$inhalt] as $teil) {
            $teil = $this->dokument->aufloesen($teil);
            if ($teil instanceof PdfStream) {
                try {
                    $teile[] = $this->dokument->daten($teil);
                } catch (PdfDefekt) {
                    // A content stream that cannot be decoded shows nothing.
                }
            }
        }

        $this->ausfuehren(implode("\n", $teile), $resourcen, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0, []);

        return $this->text;
    }

    /**
     * @param array<string, mixed> $resourcen
     * @param array{float, float, float, float, float, float} $ctm
     * @param array<int, true> $formen object numbers of the Form XObjects being run, against cycles
     */
    private function ausfuehren(string $daten, array $resourcen, array $ctm, int $tiefe, array $formen): void
    {
        $lexer = new PdfLexer($daten);
        $operanden = [];
        $stapel = [];

        $tm = $tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $schrift = null;
        $groesse = 0.0;
        $zeichenabstand = 0.0;
        $wortabstand = 0.0;
        $skalierung = 1.0;
        $zeilenabstand = 0.0;
        $anstieg = 0.0;

        while (!$lexer->amEnde()) {
            if (strlen($this->text) >= $this->maxZeichen) {
                return;
            }
            try {
                $token = $lexer->wert(false);
            } catch (PdfDefekt) {
                return;
            }
            if (!$token instanceof PdfKeyword) {
                $operanden[] = $token;
                if (count($operanden) > 64) {
                    // Operands without an operator: not a content stream.
                    return;
                }
                continue;
            }
            if (++$this->operatoren > self::MAX_OPERATOREN) {
                throw new PdfZuGross('Zu viele Operatoren.');
            }

            switch ($token->wert) {
                case 'q':
                    $stapel[] = [$ctm, $schrift, $groesse, $zeichenabstand, $wortabstand, $skalierung, $zeilenabstand, $anstieg];
                    break;
                case 'Q':
                    if ($stapel !== []) {
                        [$ctm, $schrift, $groesse, $zeichenabstand, $wortabstand, $skalierung, $zeilenabstand, $anstieg] = array_pop($stapel);
                    }
                    break;
                case 'cm':
                    $ctm = self::mal(self::matrix($operanden), $ctm);
                    break;
                case 'BT':
                    $tm = $tlm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
                    break;
                case 'Tf':
                    $schrift = $this->schrift($resourcen, $operanden[0] ?? null);
                    $groesse = self::zahl($operanden[1] ?? null);
                    break;
                case 'Tc':
                    $zeichenabstand = self::zahl($operanden[0] ?? null);
                    break;
                case 'Tw':
                    $wortabstand = self::zahl($operanden[0] ?? null);
                    break;
                case 'Tz':
                    $skalierung = self::zahl($operanden[0] ?? null) / 100;
                    break;
                case 'TL':
                    $zeilenabstand = self::zahl($operanden[0] ?? null);
                    break;
                case 'Ts':
                    $anstieg = self::zahl($operanden[0] ?? null);
                    break;
                case 'Td':
                case 'TD':
                    $ty = self::zahl($operanden[1] ?? null);
                    if ($token->wert === 'TD') {
                        $zeilenabstand = -$ty;
                    }
                    $tm = $tlm = self::mal([1.0, 0.0, 0.0, 1.0, self::zahl($operanden[0] ?? null), $ty], $tlm);
                    break;
                case 'Tm':
                    $tm = $tlm = self::matrix($operanden);
                    break;
                case 'T*':
                    $tm = $tlm = self::mal([1.0, 0.0, 0.0, 1.0, 0.0, -$zeilenabstand], $tlm);
                    break;
                case "'":
                case '"':
                case 'Tj':
                case 'TJ':
                    if ($token->wert === '"') {
                        $wortabstand = self::zahl($operanden[0] ?? null);
                        $zeichenabstand = self::zahl($operanden[1] ?? null);
                    }
                    if ($token->wert === "'" || $token->wert === '"') {
                        $tm = $tlm = self::mal([1.0, 0.0, 0.0, 1.0, 0.0, -$zeilenabstand], $tlm);
                    }
                    $elemente = $token->wert === 'TJ' ? ($operanden[0] ?? []) : [end($operanden)];
                    if ($schrift === null || !is_array($elemente)) {
                        break;
                    }
                    foreach ($elemente as $element) {
                        if (is_string($element)) {
                            $tm = $this->zeigen($schrift, $element, $tm, $ctm, $groesse, $zeichenabstand, $wortabstand, $skalierung, $anstieg);
                        } elseif (is_int($element) || is_float($element)) {
                            // A TJ adjustment: thousandths of text space, against the writing direction.
                            $tm = self::mal([1.0, 0.0, 0.0, 1.0, -$element / 1000 * $groesse * $skalierung, 0.0], $tm);
                        }
                    }
                    break;
                case 'Do':
                    $this->form($resourcen, $operanden[0] ?? null, $ctm, $tiefe, $formen);
                    break;
                case 'ID':
                    $lexer->inlineBildUeberspringen();
                    break;
            }
            $operanden = [];
        }
    }

    /**
     * Writes one shown string and returns the text matrix advanced past it.
     *
     * @param array{float, float, float, float, float, float} $tm
     * @param array{float, float, float, float, float, float} $ctm
     *
     * @return array{float, float, float, float, float, float}
     */
    private function zeigen(PdfSchrift $schrift, string $bytes, array $tm, array $ctm, float $groesse, float $zeichenabstand, float $wortabstand, float $skalierung, float $anstieg): array
    {
        $gesamt = self::mal($tm, $ctm);
        [$x, $y] = self::punkt([1.0, 0.0, 0.0, 1.0, 0.0, $anstieg], $gesamt);
        $hoehe = abs($groesse) * hypot($gesamt[2], $gesamt[3]);
        if ($hoehe <= 0.0) {
            $hoehe = 1.0;
        }

        $text = '';
        $vorschub = 0.0;
        foreach ($schrift->zerlegen($bytes) as [$zeichen, $breite, $leerzeichen]) {
            $text .= $zeichen;
            $vorschub += ($breite / 1000 * $groesse + $zeichenabstand + ($leerzeichen ? $wortabstand : 0.0)) * $skalierung;
        }

        if ($text !== '') {
            $this->anfuegen($text, $x, $y, $hoehe, $gesamt);
        }

        $tm = self::mal([1.0, 0.0, 0.0, 1.0, $vorschub, 0.0], $tm);
        $this->ende = self::punkt([1.0, 0.0, 0.0, 1.0, 0.0, $anstieg], self::mal($tm, $ctm));

        return $tm;
    }

    /**
     * @param array{float, float, float, float, float, float} $gesamt text space → device space
     */
    private function anfuegen(string $text, float $x, float $y, float $hoehe, array $gesamt): void
    {
        if ($this->ende !== null && $this->text !== '') {
            // Offsets measured along the writing direction (rotated text
            // included), in em of the larger of the two sizes.
            $richtung = hypot($gesamt[0], $gesamt[1]) > 0 ? [$gesamt[0], $gesamt[1]] : [1.0, 0.0];
            $laenge = hypot($richtung[0], $richtung[1]);
            $dx = $x - $this->ende[0];
            $dy = $y - $this->ende[1];
            $entlang = ($dx * $richtung[0] + $dy * $richtung[1]) / $laenge;
            $quer = ($dy * $richtung[0] - $dx * $richtung[1]) / $laenge;
            $em = max($hoehe, $this->letzteGroesse);

            if (abs($quer) > self::ZEILENWECHSEL * $em) {
                $this->text .= "\n";
            } elseif (($entlang > self::WORTABSTAND * $em || $entlang < -$em)
                && !str_ends_with($this->text, ' ') && !str_starts_with($text, ' ')) {
                $this->text .= ' ';
            }
        }

        $this->text .= $text;
        $this->letzteGroesse = $hoehe;
    }

    /**
     * @param array<string, mixed> $resourcen
     * @param array{float, float, float, float, float, float} $ctm
     * @param array<int, true> $formen
     */
    private function form(array $resourcen, mixed $name, array $ctm, int $tiefe, array $formen): void
    {
        if (!$name instanceof PdfName || $tiefe >= self::MAX_FORM_TIEFE) {
            return;
        }
        $objekte = $this->dokument->aufloesen($resourcen['XObject'] ?? null);
        $referenz = is_array($objekte) ? ($objekte[$name->wert] ?? null) : null;
        if ($referenz instanceof PdfRef) {
            if (isset($formen[$referenz->nummer])) {
                return;
            }
            $formen[$referenz->nummer] = true;
        }
        $form = $this->dokument->aufloesen($referenz);
        $art = $form instanceof PdfStream ? ($form->dict['Subtype'] ?? null) : null;
        if (!$form instanceof PdfStream || !$art instanceof PdfName || $art->wert !== 'Form') {
            // Images (and anything else) are never decoded.
            return;
        }

        try {
            $daten = $this->dokument->daten($form);
        } catch (PdfDefekt) {
            return;
        }
        $eigene = $this->dokument->aufloesen($form->dict['Resources'] ?? null);
        $matrix = $this->dokument->aufloesen($form->dict['Matrix'] ?? null);

        $this->ausfuehren(
            $daten,
            is_array($eigene) && !array_is_list($eigene) ? $eigene : $resourcen,
            self::mal(is_array($matrix) ? self::matrix($matrix) : [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $ctm),
            $tiefe + 1,
            $formen,
        );
    }

    /**
     * @param array<string, mixed> $resourcen
     */
    private function schrift(array $resourcen, mixed $name): ?PdfSchrift
    {
        if (!$name instanceof PdfName) {
            return null;
        }
        $schriften = $this->dokument->aufloesen($resourcen['Font'] ?? null);
        $referenz = is_array($schriften) ? ($schriften[$name->wert] ?? null) : null;
        if ($referenz instanceof PdfRef && isset($this->schriften[$referenz->nummer])) {
            return $this->schriften[$referenz->nummer];
        }

        $dict = $this->dokument->aufloesen($referenz);
        if (!is_array($dict) || array_is_list($dict)) {
            return null;
        }
        $schrift = new PdfSchrift($this->dokument, $dict);
        if ($referenz instanceof PdfRef) {
            $this->schriften[$referenz->nummer] = $schrift;
        }

        return $schrift;
    }

    /**
     * @param list<mixed> $werte
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function matrix(array $werte): array
    {
        $werte = array_values($werte);

        return [
            self::zahl($werte[0] ?? 1.0),
            self::zahl($werte[1] ?? 0.0),
            self::zahl($werte[2] ?? 0.0),
            self::zahl($werte[3] ?? 1.0),
            self::zahl($werte[4] ?? 0.0),
            self::zahl($werte[5] ?? 0.0),
        ];
    }

    /**
     * $a × $b - first $a, then $b (the order of ISO 32000-1 section 8.3.4).
     *
     * @param array{float, float, float, float, float, float} $a
     * @param array{float, float, float, float, float, float} $b
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function mal(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    /**
     * The origin of $matrix's space in device space.
     *
     * @param array{float, float, float, float, float, float} $versatz
     * @param array{float, float, float, float, float, float} $matrix
     *
     * @return array{float, float}
     */
    private static function punkt(array $versatz, array $matrix): array
    {
        $ergebnis = self::mal($versatz, $matrix);

        return [$ergebnis[4], $ergebnis[5]];
    }

    private static function zahl(mixed $wert): float
    {
        return is_int($wert) || is_float($wert) ? (float) $wert : 0.0;
    }
}
