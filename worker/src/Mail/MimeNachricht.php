<?php

declare(strict_types=1);

namespace App\Worker\Mail;

/**
 * Just enough of RFC 5322/2045-2047/2231 to take a receipt mail apart
 * (issue #87, E-54): sender, subject, Message-ID, spam mark and the leaf
 * parts that may be attachments, decoded. Tolerant by design - a broken
 * mail gives fewer attachments, never an exception - and bounded (depth,
 * number of parts).
 *
 * Forwarded mails (`message/rfc822`) are opened: "als Anhang weiterleiten"
 * is how a member passes a supplier's invoice on.
 */
final readonly class MimeNachricht
{
    private const int MAX_TIEFE = 8;

    private const int MAX_TEILE = 200;

    /**
     * @param list<MimeAnhang> $anhaenge
     */
    private function __construct(
        public string $absenderName,
        public string $absenderAdresse,
        public string $betreff,
        public ?string $messageId,
        public bool $spam,
        public array $anhaenge,
    ) {
    }

    public static function lesen(string $roh): self
    {
        [$kopf, $rumpf] = self::trennen($roh);
        $felder = self::felder($kopf);
        [$name, $adresse] = self::absender($felder['from'][0] ?? '');
        $messageId = null;
        if (preg_match('/<([^<>\s]{1,995})>/', $felder['message-id'][0] ?? '', $t) === 1) {
            $messageId = $t[1];
        }

        $anhaenge = [];
        $zaehler = 0;
        self::teil($felder, $rumpf, 0, $anhaenge, $zaehler);

        return new self(
            absenderName: $name,
            absenderAdresse: $adresse,
            betreff: self::woerter($felder['subject'][0] ?? ''),
            messageId: $messageId,
            spam: self::spam($felder),
            anhaenge: $anhaenge,
        );
    }

    /**
     * @param array<string, list<string>> $felder
     * @param list<MimeAnhang> $anhaenge
     */
    private static function teil(array $felder, string $rumpf, int $tiefe, array &$anhaenge, int &$zaehler): void
    {
        if (++$zaehler > self::MAX_TEILE || $tiefe > self::MAX_TIEFE) {
            return;
        }
        [$typ, $parameter] = self::wertMitParametern($felder['content-type'][0] ?? 'text/plain');
        $typ = strtolower($typ);

        if (str_starts_with($typ, 'multipart/')) {
            $grenze = $parameter['boundary'] ?? '';
            if ($grenze === '') {
                return;
            }
            foreach (self::teile($rumpf, $grenze) as $roh) {
                [$k, $r] = self::trennen($roh);
                self::teil(self::felder($k), $r, $tiefe + 1, $anhaenge, $zaehler);
            }

            return;
        }

        $inhalt = self::dekodieren($rumpf, strtolower(trim($felder['content-transfer-encoding'][0] ?? '')));
        if ($typ === 'message/rfc822') {
            [$k, $r] = self::trennen($inhalt);
            self::teil(self::felder($k), $r, $tiefe + 1, $anhaenge, $zaehler);

            return;
        }

        [$disposition, $dispParameter] = self::wertMitParametern($felder['content-disposition'][0] ?? '');
        $disposition = strtolower($disposition);
        $name = $dispParameter['filename'] ?? $parameter['name'] ?? '';
        // The text of the mail itself is no attachment.
        if (str_starts_with($typ, 'text/') && $name === '' && $disposition !== 'attachment') {
            return;
        }
        $anhaenge[] = new MimeAnhang(
            name: self::dateiname($name),
            inhalt: $inhalt,
            typ: $typ,
            inline: $disposition !== 'attachment' && isset($felder['content-id']),
        );
    }

    /** @return array{0: string, 1: string} header block and body */
    private static function trennen(string $roh): array
    {
        $roh = ltrim($roh, "\r\n");
        foreach (["\r\n\r\n", "\n\n"] as $trenner) {
            $pos = strpos($roh, $trenner);
            if ($pos !== false) {
                return [substr($roh, 0, $pos), substr($roh, $pos + strlen($trenner))];
            }
        }

        return [$roh, ''];
    }

    /**
     * Header fields, unfolded, by lower-case name.
     *
     * @return array<string, list<string>>
     */
    private static function felder(string $kopf): array
    {
        $felder = [];
        $kopf = preg_replace('/\r?\n[ \t]+/', ' ', $kopf) ?? '';
        foreach (preg_split('/\r?\n/', $kopf) ?: [] as $zeile) {
            $doppelpunkt = strpos($zeile, ':');
            if ($doppelpunkt === false || $doppelpunkt === 0) {
                continue;
            }
            $felder[strtolower(trim(substr($zeile, 0, $doppelpunkt)))][] = trim(substr($zeile, $doppelpunkt + 1));
        }

        return $felder;
    }

    /**
     * @return list<string> the raw parts between the boundaries
     */
    private static function teile(string $rumpf, string $grenze): array
    {
        $teile = [];
        $aktuell = null;
        foreach (preg_split('/(?<=\n)/', $rumpf) ?: [] as $zeile) {
            $ohne = rtrim($zeile, "\r\n");
            if ($ohne === '--' . $grenze || rtrim($ohne) === '--' . $grenze) {
                if ($aktuell !== null) {
                    $teile[] = $aktuell;
                }
                $aktuell = '';
                continue;
            }
            if (rtrim($ohne) === '--' . $grenze . '--') {
                if ($aktuell !== null) {
                    $teile[] = $aktuell;
                }
                $aktuell = null;
                break;
            }
            if ($aktuell !== null) {
                $aktuell .= $zeile;
            }
        }
        if ($aktuell !== null && $aktuell !== '') {
            $teile[] = $aktuell;
        }

        // The CRLF before a boundary belongs to the boundary.
        return array_map(static fn(string $t): string => preg_replace('/\r?\n$/', '', $t) ?? $t, array_slice($teile, 0, self::MAX_TEILE));
    }

    private static function dekodieren(string $inhalt, string $kodierung): string
    {
        return match ($kodierung) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $inhalt) ?? '', false),
            'quoted-printable' => quoted_printable_decode($inhalt),
            default => $inhalt,
        };
    }

    /**
     * `type/sub; a=b; c="d"` with RFC 2231 (`name*=utf-8''…`, `name*0*=`).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function wertMitParametern(string $feld): array
    {
        $teile = [];
        $aktuell = '';
        $inZitat = false;
        $laenge = strlen($feld);
        for ($i = 0; $i < $laenge; ++$i) {
            $z = $feld[$i];
            if ($z === '\\' && $inZitat && $i + 1 < $laenge) {
                $aktuell .= $feld[++$i];
                continue;
            }
            if ($z === '"') {
                $inZitat = !$inZitat;
                continue;
            }
            if ($z === ';' && !$inZitat) {
                $teile[] = $aktuell;
                $aktuell = '';
                continue;
            }
            $aktuell .= $z;
        }
        $teile[] = $aktuell;
        $wert = trim(array_shift($teile));

        $parameter = [];
        $fortsetzungen = [];
        foreach ($teile as $teil) {
            $gleich = strpos($teil, '=');
            if ($gleich === false) {
                continue;
            }
            $name = strtolower(trim(substr($teil, 0, $gleich)));
            $inhalt = trim(substr($teil, $gleich + 1));
            if (preg_match('/^([a-z0-9_-]+)\*(\d+)?(\*)?$/', $name, $t) === 1) {
                $kodiert = isset($t[3]) || (!isset($t[2]) || $t[2] === '') && str_ends_with($name, '*');
                $fortsetzungen[$t[1]][(int) ($t[2] ?? 0)] = [$inhalt, $kodiert];
                continue;
            }
            $parameter[$name] = self::woerter($inhalt);
        }
        foreach ($fortsetzungen as $name => $stuecke) {
            ksort($stuecke);
            $zeichensatz = 'UTF-8';
            $text = '';
            foreach ($stuecke as $nr => [$inhalt, $kodiert]) {
                if ($kodiert) {
                    if ($nr === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $inhalt, $t) === 1) {
                        $zeichensatz = $t[1] !== '' ? $t[1] : 'UTF-8';
                        $inhalt = $t[2];
                    }
                    $inhalt = rawurldecode($inhalt);
                }
                $text .= $inhalt;
            }
            $parameter[$name] = self::zuUtf8($text, $zeichensatz);
        }

        return [$wert, $parameter];
    }

    /** RFC 2047 encoded words, whitespace between two of them dropped. */
    private static function woerter(string $wert): string
    {
        $wert = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $wert) ?? $wert;
        $ergebnis = preg_replace_callback(
            '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/',
            static function (array $t): string {
                $zeichensatz = explode('*', $t[1])[0];
                $daten = strtoupper($t[2]) === 'B'
                    ? (string) base64_decode($t[3], false)
                    : quoted_printable_decode(str_replace('_', ' ', $t[3]));

                return self::zuUtf8($daten, $zeichensatz);
            },
            $wert,
        ) ?? $wert;

        return self::zuUtf8($ergebnis, 'UTF-8');
    }

    private static function zuUtf8(string $text, string $zeichensatz): string
    {
        $zeichensatz = strtoupper(trim($zeichensatz));
        if ($zeichensatz === 'UTF-8' || $zeichensatz === 'US-ASCII' || $zeichensatz === '') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        try {
            $umgewandelt = mb_convert_encoding($text, 'UTF-8', $zeichensatz);
        } catch (\ValueError) {
            $umgewandelt = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        return $umgewandelt;
    }

    /** @return array{0: string, 1: string} display name and address (lower case) */
    private static function absender(string $from): array
    {
        $from = self::woerter($from);
        if (preg_match('/^(.*)<([^<>]+)>\s*$/s', $from, $t) === 1) {
            $name = trim(trim($t[1]), " \t\"'");

            return [$name, strtolower(trim($t[2]))];
        }
        $adresse = trim(preg_replace('/\([^)]*\)/', '', $from) ?? $from);

        return ['', strtolower($adresse)];
    }

    /** @param array<string, list<string>> $felder */
    private static function spam(array $felder): bool
    {
        foreach ($felder['x-spam-flag'] ?? [] as $wert) {
            if (strtolower(trim($wert)) === 'yes') {
                return true;
            }
        }
        foreach ($felder['x-spam-status'] ?? [] as $wert) {
            if (preg_match('/^\s*yes\b/i', $wert) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function dateiname(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?? '';

        return mb_substr(trim($name), 0, 200);
    }
}
