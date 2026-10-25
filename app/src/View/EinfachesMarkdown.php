<?php

declare(strict_types=1);

namespace App\View;

/**
 * Renders the small text format of the club-maintained legal texts
 * (issue #80/M13-3: Impressum, Datenschutzerklärung, the notice on
 * /einreichen) to HTML - and nothing else.
 *
 * The input comes from an admin form and ends up on public pages, so the
 * rule is: every character of the input is escaped; the only markup that
 * exists is what this class writes itself. There is no raw-HTML pass-through
 * and no attribute other than a checked `href`.
 *
 * Supported, line based (deliberately a tiny subset of Markdown):
 * - `## Heading` → <h3>, `### Heading` → <h4> (`# ` counts as `## `: the
 *   page title is the <h2>)
 * - lines starting with `- ` or `* ` → one <ul>
 * - other lines → paragraphs; a blank line starts a new one, a single line
 *   break stays a line break (addresses in the Impressum)
 * - inline `**bold**` and `[text](target)` with target `https://`,
 *   `http://`, `mailto:`, `tel:` or a path on this site (`/datenschutz`);
 *   any other target (javascript:, data:, //host, /\host - which browsers
 *   read as //host) stays plain text
 */
final class EinfachesMarkdown
{
    private const string LINK = '/\[([^\[\]\n]+)\]\(([^()\s]*)\)/u';

    public static function html(string $text): string
    {
        $zeilen = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $html = [];
        /** @var list<string> $absatz */
        $absatz = [];
        /** @var list<string> $liste */
        $liste = [];

        $absatzSchliessen = static function () use (&$absatz, &$html): void {
            if ($absatz !== []) {
                $html[] = '<p>' . implode("<br>\n", $absatz) . '</p>';
                $absatz = [];
            }
        };
        $listeSchliessen = static function () use (&$liste, &$html): void {
            if ($liste !== []) {
                $html[] = "<ul>\n<li>" . implode("</li>\n<li>", $liste) . "</li>\n</ul>";
                $liste = [];
            }
        };

        foreach ($zeilen as $zeile) {
            $zeile = rtrim($zeile);
            $ohneEinzug = ltrim($zeile);

            if ($ohneEinzug === '') {
                $absatzSchliessen();
                $listeSchliessen();
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/u', $ohneEinzug, $m) === 1) {
                $absatzSchliessen();
                $listeSchliessen();
                $tag = strlen($m[1]) === 3 ? 'h4' : 'h3';
                $html[] = '<' . $tag . '>' . self::inline(trim($m[2])) . '</' . $tag . '>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $ohneEinzug, $m) === 1) {
                $absatzSchliessen();
                $liste[] = self::inline($m[1]);
                continue;
            }

            $listeSchliessen();
            $absatz[] = self::inline($ohneEinzug);
        }

        $absatzSchliessen();
        $listeSchliessen();

        return implode("\n", $html);
    }

    /**
     * Placeholders the club still has to fill in: `[Name des Vereins]` -
     * square brackets that are not the text of a link.
     *
     * @return list<string> each distinct placeholder once, in order
     */
    public static function platzhalter(string $text): array
    {
        $ohneLinks = preg_replace(self::LINK, '', $text) ?? $text;
        preg_match_all('/\[([^\[\]\n]{1,80})\]/u', $ohneLinks, $treffer);

        return array_values(array_unique(array_map(
            static fn(string $p): string => '[' . $p . ']',
            $treffer[1],
        )));
    }

    private static function inline(string $text): string
    {
        $teile = preg_split(self::LINK, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($teile === false) {
            return self::fett(e($text));
        }

        $html = '';
        // preg_split with two capture groups: text, link text, target, text, ...
        for ($i = 0, $n = count($teile); $i < $n; $i += 3) {
            $html .= self::fett(e($teile[$i]));
            if (!isset($teile[$i + 2])) {
                break;
            }
            $linktext = $teile[$i + 1];
            $ziel = $teile[$i + 2];
            $html .= self::zielErlaubt($ziel)
                ? '<a href="' . e($ziel) . '">' . self::fett(e($linktext)) . '</a>'
                : self::fett(e('[' . $linktext . '](' . $ziel . ')'));
        }

        return $html;
    }

    /** Applied to already escaped text: `*` is never escaped, the tags are ours. */
    private static function fett(string $escaped): string
    {
        return preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
    }

    private static function zielErlaubt(string $ziel): bool
    {
        return preg_match('~^(https?://[^/\s]|mailto:[^\s]|tel:\+?[0-9/-]+$|/(?![/\\\\]))~i', $ziel) === 1;
    }
}
