<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

use App\Repository\NextcloudTargetRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Keeps the club's Nextcloud target in the vault (issue #95): one row of
 * `nextcloud_target`, `data_enc` = JSON {url, benutzer, passwort} under the
 * row's own data key, AAD "nextcloud_target|1|data_enc". The app password
 * opens the club's cloud, so - unlike the AI keys, which the server key
 * protects - only an unlocked session can read it: the filing runs in the
 * session anyway (CLAUDE.md section 4).
 */
final readonly class NextcloudZielSpeicher
{
    private const string TABELLE = 'nextcloud_target';

    private const string SPALTE = 'data_enc';

    public function __construct(private NextcloudTargetRepository $ziele)
    {
    }

    /** Whether a target is stored - needs no vault. */
    public function eingerichtet(): bool
    {
        return $this->ziele->find() !== null;
    }

    /**
     * The stored target, or null when none is stored.
     *
     * @throws \App\Service\Crypto\CryptoException when the vault is locked
     *         or the row does not decrypt
     */
    public function lesen(Vault $vault): ?NextcloudZiel
    {
        $zeile = $this->ziele->find();
        if ($zeile === null) {
            return null;
        }
        $json = FieldCipher::decrypt($vault->openDataKey($zeile['dek_sealed']), $zeile['data_enc'], self::kontext());
        $daten = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        if (!is_array($daten)) {
            return null;
        }

        try {
            return NextcloudZiel::pruefen(
                (string) ($daten['url'] ?? ''),
                (string) ($daten['benutzer'] ?? ''),
                (string) ($daten['passwort'] ?? ''),
            );
        } catch (NextcloudZielFehler) {
            return null;
        }
    }

    /** Writes the target with a fresh data key - only the public key is needed. */
    public function speichern(Vault $vault, NextcloudZiel $ziel, ?int $userId, \DateTimeImmutable $now): void
    {
        $schluessel = DataKey::generate();
        $this->ziele->speichern(
            $vault->sealDataKey($schluessel),
            FieldCipher::encrypt($schluessel, json_encode($ziel->alsDaten(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::kontext()),
            $userId,
            $now,
        );
    }

    /** @return bool whether a target was stored */
    public function loeschen(): bool
    {
        return $this->ziele->loeschen();
    }

    private static function kontext(): FieldContext
    {
        return new FieldContext(self::TABELLE, NextcloudTargetRepository::ID, self::SPALTE);
    }
}
