<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\ServerCrypto;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiFaehigkeiten;

/**
 * The `ai_provider` table (migrations/024_ai_provider.sql, docs/spec/
 * 02-datenmodell.md). SQL only - what may be changed is decided in
 * App\Service\Ki\KiAnbieterService.
 *
 * The API key is operating data and goes through the server key
 * (docs/spec/01-sicherheit.md section 2): it is written encrypted and read
 * back only through apiKey(), never as part of a KiAnbieter.
 */
final readonly class AiProviderRepository
{
    public function __construct(
        private \PDO $pdo,
        private ServerCrypto $crypto,
    ) {
    }

    /**
     * @return list<KiAnbieter> the default first, then by name
     */
    public function all(): array
    {
        return array_map(
            self::hydrate(...),
            $this->pdo->query('SELECT * FROM ai_provider ORDER BY is_default DESC, name')->fetchAll(),
        );
    }

    public function find(int $id): ?KiAnbieter
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_provider WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function findByName(string $name): ?KiAnbieter
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ai_provider WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function standard(): ?KiAnbieter
    {
        $row = $this->pdo->query('SELECT * FROM ai_provider WHERE is_default = 1 ORDER BY id LIMIT 1')->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The decrypted API key, or null when none is stored - or when the
     * stored value cannot be decrypted (a different server key after a
     * restore, a damaged row): like the SMTP password
     * (App\Service\Mail\MailSettingsRepository), that must not break the
     * page, the admin simply enters the key again.
     */
    public function apiKey(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT api_key_enc FROM ai_provider WHERE id = ?');
        $stmt->execute([$id]);
        $gespeichert = $stmt->fetchColumn();
        if (!is_string($gespeichert) || $gespeichert === '') {
            return null;
        }

        try {
            return $this->crypto->decrypt($gespeichert);
        } catch (CryptoException) {
            return null;
        }
    }

    public function insert(
        string $name,
        string $baseUrl,
        string $model,
        KiFaehigkeiten $faehigkeiten,
        int $timeoutS,
        bool $active,
        #[\SensitiveParameter] ?string $apiKey,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO ai_provider (name, base_url, api_key_enc, model, caps, timeout_s, active, is_default, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
        )->execute([
            $name,
            $baseUrl,
            $apiKey === null || $apiKey === '' ? null : $this->crypto->encrypt($apiKey),
            $model,
            $faehigkeiten->toJson(),
            $timeoutS,
            $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param ?string $neuerKey null keeps the stored key, '' removes it,
     *        anything else replaces it - the form never carries the stored
     *        key back, so an empty field has to mean "unchanged"
     */
    public function update(
        int $id,
        string $name,
        string $baseUrl,
        string $model,
        KiFaehigkeiten $faehigkeiten,
        int $timeoutS,
        bool $active,
        #[\SensitiveParameter] ?string $neuerKey,
    ): void {
        $this->pdo->prepare(
            'UPDATE ai_provider SET name = ?, base_url = ?, model = ?, caps = ?, timeout_s = ?, active = ?, updated_at = NOW() WHERE id = ?',
        )->execute([$name, $baseUrl, $model, $faehigkeiten->toJson(), $timeoutS, $active ? 1 : 0, $id]);

        if ($neuerKey !== null) {
            $this->pdo->prepare('UPDATE ai_provider SET api_key_enc = ? WHERE id = ?')
                ->execute([$neuerKey === '' ? null : $this->crypto->encrypt($neuerKey), $id]);
        }
    }

    /**
     * Makes $id the one default profile - one statement, so there is no
     * moment with none or two. `updated_at` comes first: MariaDB assigns
     * left to right and would otherwise compare against the new value.
     */
    public function setDefault(int $id): void
    {
        $this->pdo->prepare('UPDATE ai_provider SET updated_at = IF(is_default <> (id = ?), NOW(), updated_at), is_default = (id = ?)')
            ->execute([$id, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ai_provider WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): KiAnbieter
    {
        return new KiAnbieter(
            id: (int) $row['id'],
            name: (string) $row['name'],
            baseUrl: (string) $row['base_url'],
            model: (string) $row['model'],
            faehigkeiten: KiFaehigkeiten::fromJson((string) $row['caps']),
            timeoutS: (int) $row['timeout_s'],
            active: (int) $row['active'] === 1,
            isDefault: (int) $row['is_default'] === 1,
            keyGesetzt: $row['api_key_enc'] !== null && $row['api_key_enc'] !== '',
        );
    }
}
