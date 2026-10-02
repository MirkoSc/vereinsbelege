<?php

declare(strict_types=1);

namespace App\Service\Bank\Csv;

/**
 * Picks the profile for a file by its header ("Automatische Erkennung über
 * die Kopfzeilen-Signatur", docs/spec/04-bank-und-abgleich.md section 3):
 *
 * 1. a profile made from exactly this header (same signature);
 * 2. otherwise, among the profiles whose required columns the header has,
 *    the one that finds the most of its fields - so a bank's newer export
 *    with an extra column still lands on that bank's profile, while
 *    another bank's file that merely shares "Buchungstag" and "Betrag"
 *    scores lower. On a tie the shipped profile wins over a club's own.
 *
 * The header is looked for in the first lines like CsvParser does, with
 * each profile's own separator and character set.
 */
final class CsvProfilErkennung
{
    /**
     * @param list<CsvProfil> $profile
     */
    public function finde(string $inhalt, array $profile): ?CsvProfil
    {
        $bestes = null;
        $besteTreffer = -1;
        foreach ($profile as $profil) {
            $kopf = $this->kopf($inhalt, $profil);
            if ($kopf === null) {
                continue;
            }
            if ($profil->kopfSignatur !== null && hash_equals($profil->kopfSignatur, CsvProfil::signatur($kopf))) {
                return $profil;
            }
            $treffer = count($profil->aufloesen($kopf));
            if ($treffer > $besteTreffer || ($treffer === $besteTreffer && $profil->mitgeliefert && $bestes?->mitgeliefert === false)) {
                $bestes = $profil;
                $besteTreffer = $treffer;
            }
        }

        return $bestes;
    }

    /**
     * @return list<string>|null the first header line the profile fits
     */
    private function kopf(string $inhalt, CsvProfil $profil): ?array
    {
        try {
            $text = $profil->zeichensatz->zuUtf8($inhalt);
        } catch (CsvException) {
            return null;
        }
        foreach (CsvTabelle::zerlege($text, $profil->trennzeichen, CsvParser::KOPF_SUCHE) as $zeile) {
            $kopf = array_map(trim(...), $zeile->zellen);
            if ($profil->passtZu($kopf)) {
                return $kopf;
            }
        }

        return null;
    }
}
