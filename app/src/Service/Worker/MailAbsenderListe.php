<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * The optional sender allowlist of the mail intake (issue #87, E-54,
 * docs/spec/07-worker.md section 9): full addresses (`kasse@verein.de`) or
 * whole domains (`@stadtwerke.de`). Empty = every sender.
 *
 * Shared by the admin page (parsing what was typed), the worker (which
 * mail it hands in at all) and the web host's mail intake API (which
 * checks it again) - so no Http, no database.
 *
 * Matching is case-insensitive and exact: `@verein.de` does not cover
 * `sub.verein.de`. The From header can be forged; the list keeps out mass
 * mail and mistakes, not a determined sender (spec section 9).
 */
final readonly class MailAbsenderListe
{
    public const int MAX_EINTRAEGE = 100;

    /**
     * @param list<string> $eintraege normalised (lower case) and valid -
     *        anything typed or received goes through lesen()/ausListe()
     */
    public function __construct(public array $eintraege = [])
    {
    }

    /**
     * Reads one entry per line (commas and semicolons separate as well).
     *
     * @return array{0: ?self, 1: list<string>} the list, or null and the
     *         entries that are not an address or `@domain`
     */
    public static function lesen(string $text): array
    {
        $eintraege = [];
        $ungueltig = [];
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $teil) {
            $teil = strtolower(trim($teil));
            if ($teil === '') {
                continue;
            }
            if (!self::gueltig($teil)) {
                $ungueltig[] = $teil;
                continue;
            }
            if (!in_array($teil, $eintraege, true)) {
                $eintraege[] = $teil;
            }
        }
        if ($ungueltig !== [] || count($eintraege) > self::MAX_EINTRAEGE) {
            return [null, $ungueltig];
        }

        return [new self($eintraege), []];
    }

    /**
     * From what was stored or sealed (a list of strings); invalid entries
     * are dropped - a damaged list never lets more through than it says.
     *
     * @param array<mixed> $liste
     */
    public static function ausListe(array $liste): self
    {
        $eintraege = [];
        foreach ($liste as $eintrag) {
            if (is_string($eintrag) && self::gueltig(strtolower($eintrag)) && count($eintraege) < self::MAX_EINTRAEGE) {
                $eintraege[] = strtolower($eintrag);
            }
        }

        return new self(array_values(array_unique($eintraege)));
    }

    public function istLeer(): bool
    {
        return $this->eintraege === [];
    }

    /** Whether $adresse may hand in receipts - always with an empty list. */
    public function erlaubt(?string $adresse): bool
    {
        if ($this->eintraege === []) {
            return true;
        }
        if ($adresse === null) {
            return false;
        }
        $adresse = strtolower(trim($adresse));
        $at = strrpos($adresse, '@');
        if ($at === false || !self::gueltig($adresse)) {
            return false;
        }
        $domain = substr($adresse, $at);

        return in_array($adresse, $this->eintraege, true) || in_array($domain, $this->eintraege, true);
    }

    public function alsText(): string
    {
        return implode("\n", $this->eintraege);
    }

    private static function gueltig(string $eintrag): bool
    {
        if (str_starts_with($eintrag, '@')) {
            return preg_match('/^@(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $eintrag) === 1;
        }

        return strlen($eintrag) <= 254 && filter_var($eintrag, FILTER_VALIDATE_EMAIL) !== false;
    }
}
