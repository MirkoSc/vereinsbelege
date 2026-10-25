<?php

declare(strict_types=1);

namespace App\Service\MasterData;

use App\Repository\ClubIdentityRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Keeps the club's own VAT id and tax number in the vault (issue #245,
 * E-67): one row of `club_identity`, `data_enc` = JSON {ust_id,
 * steuernummer} under the row's own data key, AAD
 * "club_identity|1|data_enc". No blind index: compared after decrypting in
 * the session, so the key rotation (#90) has nothing to derive again.
 */
final readonly class Vereinskennungen
{
    private const string TABELLE = 'club_identity';

    private const string SPALTE = 'data_enc';

    public function __construct(private ClubIdentityRepository $zeilen)
    {
    }

    /** Whether an admin saved the club's ids (both may be empty) - needs no vault. */
    public function gepflegt(): bool
    {
        return $this->zeilen->find() !== null;
    }

    /**
     * The club's ids, or null while no admin saved them.
     *
     * @throws \App\Service\Crypto\CryptoException when the vault is locked
     *         or the row does not decrypt
     * @throws \JsonException
     */
    public function lesen(Vault $vault): ?Vereinskennung
    {
        $zeile = $this->zeilen->find();
        if ($zeile === null) {
            return null;
        }
        $json = FieldCipher::decrypt($vault->openDataKey($zeile['dek_sealed']), $zeile['data_enc'], self::kontext());
        $daten = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        $text = static fn(string $feld): string => is_array($daten) && is_string($daten[$feld] ?? null) ? $daten[$feld] : '';

        return new Vereinskennung($text('ust_id'), $text('steuernummer'));
    }

    /** Writes the ids with a fresh data key - only the public key is needed. */
    public function speichern(Vault $vault, Vereinskennung $kennung, ?int $userId, \DateTimeImmutable $now): void
    {
        $schluessel = DataKey::generate();
        $this->zeilen->speichern(
            $vault->sealDataKey($schluessel),
            FieldCipher::encrypt($schluessel, json_encode($kennung->alsDaten(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::kontext()),
            $userId,
            $now,
        );
    }

    private static function kontext(): FieldContext
    {
        return new FieldContext(self::TABELLE, ClubIdentityRepository::ID, self::SPALTE);
    }
}
