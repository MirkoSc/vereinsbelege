<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\InvoiceDirection;
use App\Domain\RecurringSeriesRecord;
use App\Domain\SeriesPeriod;
use App\Domain\SeriesStatus;

/**
 * The tables `recurring_series` and `recurring_series_invoice` (issue
 * #57/M8-1, migrations/036_recurring_series.sql). SQL only - encrypting and
 * the rules live in App\Service\Invoice\Serien. Nothing here can read an
 * amount.
 *
 * The links never touch `invoice`: a locked receipt stays as it is
 * (docs/spec/01-sicherheit.md section 7).
 */
final readonly class RecurringSeriesRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $id): ?RecurringSeriesRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recurring_series WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : RecurringSeriesRecord::fromRow($row);
    }

    /** The row under a row lock until the transaction ends. */
    public function sperre(int $id): ?RecurringSeriesRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recurring_series WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : RecurringSeriesRecord::fromRow($row);
    }

    /**
     * The series of a supplier and direction, oldest first - with
     * $sperren under row locks until the transaction ends, so the detection
     * job and a person confirming a suggestion do not overwrite each other.
     *
     * @return list<RecurringSeriesRecord>
     */
    public function fuerLieferant(int $supplierId, InvoiceDirection $richtung, bool $sperren = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recurring_series WHERE supplier_id = ? AND direction = ? ORDER BY id' . ($sperren ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $richtung->value]);

        return array_map(RecurringSeriesRecord::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Every series in one of $status - suggestions and confirmed series
     * for the list page: next expected date first, then oldest.
     *
     * @param list<SeriesStatus> $status
     *
     * @return list<RecurringSeriesRecord>
     */
    public function liste(array $status): array
    {
        if ($status === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM recurring_series WHERE status IN (' . implode(', ', array_fill(0, count($status), '?')) . ')
             ORDER BY next_expected IS NULL, next_expected, id',
        );
        $stmt->execute(array_map(static fn(SeriesStatus $s): string => $s->value, $status));

        return array_map(RecurringSeriesRecord::fromRow(...), $stmt->fetchAll());
    }

    public function anzahl(SeriesStatus $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM recurring_series WHERE status = ?');
        $stmt->execute([$status->value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many confirmed series are overdue on $heute: the next expected
     * receipt plus the tolerance lies before today - the same rule as
     * App\Service\Invoice\SerienKennzahlen (dashboard, issue #72).
     * Structure only, nothing decrypted.
     */
    public function anzahlUeberfaellig(\DateTimeImmutable $heute): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM recurring_series
             WHERE status = ? AND next_expected IS NOT NULL AND DATE_ADD(next_expected, INTERVAL tolerance_days DAY) < ?',
        );
        $stmt->execute([SeriesStatus::Bestaetigt->value, $heute->format('Y-m-d')]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * First step of a new row: everything but the ciphertext, whose AAD
     * needs the id this returns. The caller writes setData() in the same
     * transaction.
     */
    public function insert(
        int $supplierId,
        InvoiceDirection $richtung,
        SeriesPeriod $periode,
        SeriesStatus $status,
        ?\DateTimeImmutable $naechste,
        int $toleranzTage,
        string $dekSealed,
        \DateTimeImmutable $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO recurring_series (supplier_id, direction, period, status, next_expected, tolerance_days, dek_sealed, data_enc, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $supplierId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $richtung->value);
        $stmt->bindValue(3, $periode->value);
        $stmt->bindValue(4, $status->value);
        $stmt->bindValue(5, $naechste?->format('Y-m-d'), $naechste === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(6, $toleranzTage, \PDO::PARAM_INT);
        $stmt->bindValue(7, $dekSealed, \PDO::PARAM_LOB);
        $stmt->bindValue(8, '', \PDO::PARAM_LOB);
        $stmt->bindValue(9, $now->format(self::FORMAT));
        $stmt->bindValue(10, $now->format(self::FORMAT));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function setData(int $id, string $dataEnc): void
    {
        $stmt = $this->pdo->prepare('UPDATE recurring_series SET data_enc = ? WHERE id = ?');
        $stmt->bindValue(1, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Rhythm, next date, tolerance and data of a series. $userId is who
     * changed it (null: the detection job).
     */
    public function update(
        int $id,
        SeriesPeriod $periode,
        ?\DateTimeImmutable $naechste,
        int $toleranzTage,
        string $dataEnc,
        ?int $userId,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE recurring_series SET period = ?, next_expected = ?, tolerance_days = ?, data_enc = ?, updated_by = ?, updated_at = ? WHERE id = ?',
        );
        $stmt->bindValue(1, $periode->value);
        $stmt->bindValue(2, $naechste?->format('Y-m-d'), $naechste === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(3, $toleranzTage, \PDO::PARAM_INT);
        $stmt->bindValue(4, $dataEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(6, $now->format(self::FORMAT));
        $stmt->bindValue(7, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /** A person confirmed or dismissed the series. */
    public function entscheide(int $id, SeriesStatus $status, ?int $userId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE recurring_series SET status = ?, decided_by = ?, decided_at = ?, updated_by = ?, updated_at = ? WHERE id = ?',
        );
        $stmt->bindValue(1, $status->value);
        $stmt->bindValue(2, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $now->format(self::FORMAT));
        $stmt->bindValue(4, $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(5, $now->format(self::FORMAT));
        $stmt->bindValue(6, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * The invoice ids of a series.
     *
     * @return list<int>
     */
    public function mitglieder(int $seriesId): array
    {
        $stmt = $this->pdo->prepare('SELECT invoice_id FROM recurring_series_invoice WHERE series_id = ? ORDER BY invoice_id');
        $stmt->execute([$seriesId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The invoice ids of each of these series (the overview, issue #58).
     *
     * @param list<int> $seriesIds
     *
     * @return array<int, list<int>> series id => invoice ids
     */
    public function alleMitglieder(array $seriesIds): array
    {
        $mitglieder = [];
        foreach (array_chunk(array_values(array_unique($seriesIds)), 500) as $teil) {
            $stmt = $this->pdo->prepare(
                'SELECT series_id, invoice_id FROM recurring_series_invoice WHERE series_id IN ('
                . implode(', ', array_fill(0, count($teil), '?')) . ') ORDER BY series_id, invoice_id',
            );
            $stmt->execute($teil);
            foreach ($stmt->fetchAll() as $zeile) {
                $mitglieder[(int) $zeile['series_id']][] = (int) $zeile['invoice_id'];
            }
        }

        return $mitglieder;
    }

    /**
     * Which series each of these invoices belongs to.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<int, int> invoice id => series id
     */
    public function serienVon(array $invoiceIds): array
    {
        $zuordnung = [];
        foreach (array_chunk(array_values(array_unique($invoiceIds)), 500) as $teil) {
            $stmt = $this->pdo->prepare(
                'SELECT invoice_id, series_id FROM recurring_series_invoice WHERE invoice_id IN (' . implode(', ', array_fill(0, count($teil), '?')) . ')',
            );
            $stmt->execute($teil);
            foreach ($stmt->fetchAll() as $zeile) {
                $zuordnung[(int) $zeile['invoice_id']] = (int) $zeile['series_id'];
            }
        }

        return $zuordnung;
    }

    /**
     * Replaces the receipts of a series. An invoice already in another
     * series is a constraint error - the caller only passes free ones.
     *
     * @param list<int> $invoiceIds
     */
    public function setzeMitglieder(int $seriesId, array $invoiceIds): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM recurring_series_invoice WHERE series_id = ?');
        $stmt->execute([$seriesId]);
        foreach (array_values(array_unique($invoiceIds)) as $invoiceId) {
            $this->verknuepfe($seriesId, $invoiceId);
        }
    }

    /** Deletes a series; its links go with it (ON DELETE CASCADE). */
    public function loesche(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM recurring_series WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Takes an invoice out of whatever series it is in. */
    public function loese(int $invoiceId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM recurring_series_invoice WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
    }

    public function verknuepfe(int $seriesId, int $invoiceId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO recurring_series_invoice (invoice_id, series_id) VALUES (?, ?)');
        $stmt->execute([$invoiceId, $seriesId]);
    }
}
