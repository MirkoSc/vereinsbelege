<?php

declare(strict_types=1);

namespace App\Service\Bank\Sepa;

/**
 * Text for a SEPA file (issue #85, E-42): the basic Latin character set of
 * the EPC guidelines, which every bank in the SEPA area accepts -
 * a-z A-Z 0-9 / - ? : ( ) . , ' + and the space. German umlauts and ß
 * are spelt out (ä -> ae), other Latin letters lose their accent, `&`
 * becomes `+`; whatever is left outside the set becomes a space. Spaces
 * are collapsed and the result cut to the field's length.
 *
 * Pure functions, no state: used by App\Service\Bank\Sepa\Pain001 and
 * when a payment run is built.
 */
final class SepaText
{
    private const array ERSETZEN = [
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'SS',
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A', 'Æ' => 'AE',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C', 'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'Ď' => 'D', 'Đ' => 'D', 'Ð' => 'D', 'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ė' => 'E', 'Ę' => 'E', 'Ě' => 'E',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'Ğ' => 'G', 'ğ' => 'g',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'İ' => 'I', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i',
        'Ł' => 'L', 'Ľ' => 'L', 'ł' => 'l', 'ľ' => 'l',
        'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ō' => 'O', 'Ő' => 'O', 'Œ' => 'OE',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'Ř' => 'R', 'ř' => 'r',
        'Ś' => 'S', 'Š' => 'S', 'Ş' => 'S', 'ś' => 's', 'š' => 's', 'ş' => 's',
        'Ť' => 'T', 'Ţ' => 'T', 'ť' => 't', 'ţ' => 't', 'Þ' => 'Th', 'þ' => 'th',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ū' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
        'Ý' => 'Y', 'Ÿ' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
        'Ź' => 'Z', 'Ż' => 'Z', 'Ž' => 'Z', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        '&' => '+', '_' => '-', '–' => '-', '—' => '-', '‘' => "'", '’' => "'", '‚' => "'", '´' => "'", '`' => "'",
        '"' => "'", '„' => "'", '“' => "'", '”' => "'", '«' => "'", '»' => "'", ';' => ',', '!' => '.', '€' => 'EUR',
    ];

    private function __construct()
    {
        // Static utility, no instances.
    }

    /**
     * $text in the SEPA character set, at most $laenge characters, no
     * leading or trailing space. May come back empty.
     */
    public static function bereinigen(string $text, int $laenge): string
    {
        $text = strtr(mb_scrub($text, 'UTF-8'), self::ERSETZEN);
        $text = (string) preg_replace("~[^A-Za-z0-9/\\-?:().,'+ ]~u", ' ', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return rtrim(substr($text, 0, max(0, $laenge)));
    }

    /** Whether $text uses nothing but the SEPA character set. */
    public static function istZulaessig(string $text): bool
    {
        return preg_match("~^[A-Za-z0-9/\\-?:().,'+ ]*$~", $text) === 1;
    }

    /**
     * An identifier (MsgId, PmtInfId, EndToEndId): 1-35 characters of the
     * set, neither starting nor ending with `/` and without `//`
     * (EPC/DK rule for references).
     */
    public static function istReferenz(string $text): bool
    {
        return $text !== ''
            && strlen($text) <= 35
            && self::istZulaessig($text)
            && trim($text) === $text
            && !str_starts_with($text, '/')
            && !str_ends_with($text, '/')
            && !str_contains($text, '//');
    }
}
