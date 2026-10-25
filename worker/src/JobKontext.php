<?php

declare(strict_types=1);

namespace App\Worker;

use App\Domain\Category;
use App\Domain\CategoryDirection;

/**
 * The answer of `GET /api/worker/jobs/{id}/context` (docs/spec/07-worker.md
 * section 5 "Stand M7b-2"): which blobs the worker may fetch and which of
 * them are the originals, the AI context sealed to W_pub, VK_pub to seal
 * the results to, and the non-sensitive master data for the prompt.
 */
final readonly class JobKontext
{
    /**
     * @param list<int> $blobs every granted blob
     * @param list<int> $originale the originals in page order
     * @param list<int|null> $verarbeitet parallel to $originale: the processed scan, if granted
     * @param list<Category> $kategorien
     * @param array<int, string> $kostenstellen
     * @param list<string> $artefakte the kinds the job may deliver
     */
    public function __construct(
        public int $dokumentId,
        public array $blobs,
        public array $originale,
        public array $verarbeitet,
        public ?string $kontextVersiegelt,
        public int $tresorVersion,
        public string $tresorPublicKey,
        public array $kategorien,
        public array $kostenstellen,
        public array $artefakte,
    ) {
    }

    /**
     * @param array<string, mixed> $daten
     */
    public static function aus(array $daten): self
    {
        $dokument = is_array($daten['dokument'] ?? null) ? $daten['dokument'] : null;
        $tresor = is_array($daten['tresor'] ?? null) ? $daten['tresor'] : [];
        $publicKey = is_string($tresor['public_key'] ?? null) ? base64_decode($tresor['public_key'], true) : false;
        if ($dokument === null || !is_int($dokument['id'] ?? null) || !is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
            || !is_int($tresor['version'] ?? null)) {
            throw new ApiFehler(200, 'kontext_ungueltig', 'Der Job-Kontext ist unvollständig.');
        }
        $kontext = is_string($dokument['kontext'] ?? null) ? base64_decode($dokument['kontext'], true) : null;

        $stamm = is_array($daten['stammdaten'] ?? null) ? $daten['stammdaten'] : [];
        $kategorien = [];
        foreach (is_array($stamm['kategorien'] ?? null) ? $stamm['kategorien'] : [] as $k) {
            $richtung = is_array($k) && is_string($k['richtung'] ?? null) ? CategoryDirection::tryFrom($k['richtung']) : null;
            if ($richtung !== null && is_int($k['id'] ?? null) && is_string($k['name'] ?? null)) {
                $kategorien[] = new Category($k['id'], $k['name'], $richtung, null, null, 0, true, is_string($k['ki_hinweis'] ?? null) ? $k['ki_hinweis'] : '');
            }
        }
        $kostenstellen = [];
        foreach (is_array($stamm['kostenstellen'] ?? null) ? $stamm['kostenstellen'] : [] as $k) {
            if (is_array($k) && is_int($k['id'] ?? null) && is_string($k['name'] ?? null)) {
                $kostenstellen[$k['id']] = $k['name'];
            }
        }

        return new self(
            $dokument['id'],
            self::ids($dokument['blobs'] ?? null),
            self::ids($dokument['originale'] ?? null),
            array_values(array_map(static fn(mixed $id): ?int => is_int($id) ? $id : null, is_array($dokument['verarbeitet'] ?? null) ? $dokument['verarbeitet'] : [])),
            is_string($kontext) ? $kontext : null,
            $tresor['version'],
            $publicKey,
            $kategorien,
            $kostenstellen,
            array_values(array_filter(is_array($daten['artefakte'] ?? null) ? $daten['artefakte'] : [], is_string(...))),
        );
    }

    /** The processed scan of original $index, if one was granted. */
    public function verarbeitet(int $index): ?int
    {
        $id = $this->verarbeitet[$index] ?? null;

        return $id !== null && in_array($id, $this->blobs, true) ? $id : null;
    }

    /** @return list<int> */
    private static function ids(mixed $liste): array
    {
        return is_array($liste) ? array_values(array_filter($liste, is_int(...))) : [];
    }
}
