<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Bank\Csv\CsvDatumsformat;
use App\Service\Bank\Csv\CsvDezimaltrenner;
use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvTrennzeichen;
use App\Service\Bank\Csv\CsvZeichensatz;

/**
 * The `csv_profile` table (migrations/022_csv_profile.sql, docs/spec/
 * 04-bank-und-abgleich.md section 3). SQL only.
 *
 * Plaintext: column names and formats, no club data (CLAUDE.md section 5).
 * The shipped rows (`builtin` = 1) are never changed or deleted here -
 * update() and delete() leave them alone in SQL, not only in the UI.
 */
final readonly class CsvProfileRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<CsvProfil> shipped profiles first, then by name
     */
    public function all(): array
    {
        return array_map(
            self::hydrate(...),
            $this->pdo->query('SELECT * FROM csv_profile ORDER BY builtin DESC, name')->fetchAll(),
        );
    }

    public function find(int $id): ?CsvProfil
    {
        $stmt = $this->pdo->prepare('SELECT * FROM csv_profile WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function nameExists(string $name, ?int $ausser = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM csv_profile WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $ausser ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(CsvProfil $profil, \DateTimeImmutable $jetzt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO csv_profile (name, builtin, header_signature, mapping, delimiter, encoding, date_format, decimal_sep, created_at, updated_at)'
            . ' VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $zeit = $jetzt->format('Y-m-d H:i:s');
        $stmt->execute([...self::spalten($profil), $zeit, $zeit]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return bool false when there is no such club profile (unknown or
     *         shipped)
     */
    public function update(int $id, CsvProfil $profil, \DateTimeImmutable $jetzt): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE csv_profile SET name = ?, header_signature = ?, mapping = ?, delimiter = ?, encoding = ?, date_format = ?, decimal_sep = ?,'
            . ' updated_at = ? WHERE id = ? AND builtin = 0',
        );
        $stmt->execute([...self::spalten($profil), $jetzt->format('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0 || $this->find($id)?->mitgeliefert === false;
    }

    /**
     * @return bool whether a club profile was deleted
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM csv_profile WHERE id = ? AND builtin = 0');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<string|null>
     */
    private static function spalten(CsvProfil $profil): array
    {
        return [
            $profil->name,
            $profil->kopfSignatur,
            json_encode($profil->zuordnung, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            $profil->trennzeichen->value,
            $profil->zeichensatz->value,
            $profil->datumsformat->value,
            $profil->dezimaltrenner->value,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): CsvProfil
    {
        $zuordnung = [];
        $json = json_decode((string) $row['mapping'], true, 8, \JSON_THROW_ON_ERROR);
        foreach (is_array($json) ? $json : [] as $feld => $spalten) {
            if (is_string($feld) && is_array($spalten)) {
                $zuordnung[$feld] = array_values(array_filter($spalten, is_string(...)));
            }
        }

        return new CsvProfil(
            (int) $row['id'],
            (string) $row['name'],
            (bool) $row['builtin'],
            CsvTrennzeichen::from((string) $row['delimiter']),
            CsvZeichensatz::from((string) $row['encoding']),
            CsvDatumsformat::from((string) $row['date_format']),
            CsvDezimaltrenner::from((string) $row['decimal_sep']),
            $zuordnung,
            $row['header_signature'] === null ? null : (string) $row['header_signature'],
        );
    }
}
