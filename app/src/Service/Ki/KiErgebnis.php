<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * The final word of KiAbfrage::ausfuehren(): one to three attempts, the last
 * decides (three: the default profile was out of reach, the fallback's
 * answer did not fit and was repaired). Not valid means "KI fehlgeschlagen"
 * (DocumentStatus::KiFehler, set by the job in M7-5) - the receipt is then
 * captured by hand, nothing is guessed.
 */
final readonly class KiErgebnis
{
    /**
     * @param non-empty-list<KiVersuch> $versuche
     */
    public function __construct(public array $versuche)
    {
        if ($versuche === [] || count($versuche) > 3) {
            throw new \LogicException('An AI result has one to three attempts.');
        }
    }

    public function letzter(): KiVersuch
    {
        return $this->versuche[array_key_last($this->versuche)];
    }

    public function gueltig(): bool
    {
        return $this->letzter()->gueltig();
    }

    /**
     * @return ?array<string, mixed>
     */
    public function daten(): ?array
    {
        return $this->letzter()->daten;
    }

    /**
     * German, without content of the answer - fit for the receipt.
     */
    public function meldung(): string
    {
        $letzter = $this->letzter();
        $fallback = $letzter->aufruf->fallback ? ' Das Fallback-Profil wurde verwendet.' : '';
        if ($letzter->gueltig()) {
            return ($letzter->reparatur ? 'KI-Antwort nach einem Korrekturversuch gültig.' : $letzter->status->meldung()) . $fallback;
        }

        return $letzter->status->meldung() . ($letzter->reparatur ? ' Auch der Korrekturversuch ist gescheitert.' : '') . $fallback;
    }

    /**
     * @return list<KiAufruf>
     */
    public function aufrufe(): array
    {
        return array_map(static fn(KiVersuch $versuch): KiAufruf => $versuch->aufruf, $this->versuche);
    }
}
