<?php

declare(strict_types=1);

namespace App\Service\Storage;

/**
 * State of the integrity check that follows a backend switch (M2-5,
 * docs/spec/02-datenmodell.md "Dateien").
 *
 * It walks `file_blob` by id in short requests and compares the stored
 * ciphertext against `cipher_sha256`, so it survives a closed tab, a timeout
 * and a crash: the cursor is the only thing it has to remember.
 *
 * Persisted as JSON in the plaintext setting `speicher_pruefung`. What goes
 * in there are row ids and counters - never a file name, never anything from
 * a receipt (CLAUDE.md section 4), same rule as `job.state`.
 */
final readonly class StorageCheck
{
    /** Ids of damaged blobs kept in the setting; enough to act on, bounded. */
    public const int MAX_DAMAGED = 50;

    /**
     * @param list<int> $beschaedigt ids whose ciphertext does not match
     */
    public function __construct(
        public int $letzteId = 0,
        public int $geprueft = 0,
        public int $gesamt = 0,
        public array $beschaedigt = [],
        public bool $fertig = false,
        public ?\DateTimeImmutable $beendetAm = null,
    ) {
    }

    /** A check that has not run yet. */
    public static function leer(): self
    {
        return new self();
    }

    /**
     * @param list<int> $beschaedigt ids found damaged in this request
     */
    public function mitFortschritt(int $letzteId, int $geprueft, array $beschaedigt): self
    {
        return new self(
            letzteId: $letzteId,
            geprueft: $this->geprueft + $geprueft,
            gesamt: $this->gesamt,
            beschaedigt: array_slice([...$this->beschaedigt, ...$beschaedigt], 0, self::MAX_DAMAGED),
            fertig: false,
            beendetAm: null,
        );
    }

    public function abgeschlossen(\DateTimeImmutable $jetzt): self
    {
        return new self(
            letzteId: $this->letzteId,
            geprueft: $this->geprueft,
            // The table can grow while the check runs; what it actually
            // looked at is the honest total.
            gesamt: max($this->gesamt, $this->geprueft),
            beschaedigt: $this->beschaedigt,
            fertig: true,
            beendetAm: $jetzt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'letzte_id' => $this->letzteId,
            'geprueft' => $this->geprueft,
            'gesamt' => $this->gesamt,
            'beschaedigt' => $this->beschaedigt,
            'fertig' => $this->fertig,
            'beendet_am' => $this->beendetAm?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $beendet = isset($data['beendet_am']) ? (string) $data['beendet_am'] : '';

        return new self(
            letzteId: (int) ($data['letzte_id'] ?? 0),
            geprueft: (int) ($data['geprueft'] ?? 0),
            gesamt: (int) ($data['gesamt'] ?? 0),
            beschaedigt: array_values(array_map(intval(...), (array) ($data['beschaedigt'] ?? []))),
            fertig: (bool) ($data['fertig'] ?? false),
            beendetAm: $beendet === '' ? null : new \DateTimeImmutable($beendet),
        );
    }

    /**
     * A stored value that is not readable JSON means "never checked". It must
     * not throw: the storage page is also the place where an admin looks when
     * something is wrong.
     */
    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return self::leer();
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::leer();
        }

        return is_array($data) ? self::fromArray($data) : self::leer();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
