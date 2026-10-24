<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Ki\KiAufruf;

/**
 * The table `ai_call_log` (migrations/032_ai_call_log.sql, M7-8, issue #50,
 * docs/spec/03-erfassung-und-ki.md section 6). SQL only.
 *
 * Operating data: only what the call log entry (App\Service\Ki\KiAufruf) - never the
 * raw answer or any text of a receipt.
 */
final readonly class AiCallLogRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(KiAufruf $aufruf, \DateTimeImmutable $zeitpunkt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_call_log (called_at, provider_id, provider_name, model, prompt_version, is_repair, is_fallback,'
            . ' status, prompt_tokens, answer_tokens, total_tokens, duration_ms, http_status)'
            . ' VALUES (?, (SELECT id FROM ai_provider WHERE id = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $zeitpunkt->format(self::FORMAT),
            $aufruf->anbieterId,
            mb_substr($aufruf->anbieterName, 0, 100),
            mb_substr($aufruf->modell, 0, 100),
            mb_substr($aufruf->promptVersion, 0, 40),
            (int) $aufruf->reparatur,
            (int) $aufruf->fallback,
            $aufruf->status->value,
            $aufruf->promptTokens,
            $aufruf->antwortTokens,
            $aufruf->tokensGesamt,
            $aufruf->dauerMs,
            $aufruf->httpStatus,
        ]);
    }

    /**
     * Totals per provider since $seit, the busiest first.
     *
     * @return list<array{providerId: ?int, name: string, aufrufe: int, gueltig: int, fehler: int, tokens: int, dauerMs: ?int}>
     */
    public function summen(\DateTimeImmutable $seit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT provider_id, provider_name, COUNT(*) AS aufrufe, SUM(status = ?) AS gueltig,'
            . ' SUM(total_tokens) AS tokens, AVG(duration_ms) AS dauer'
            . ' FROM ai_call_log WHERE called_at >= ? GROUP BY provider_id, provider_name'
            . ' ORDER BY aufrufe DESC, provider_name',
        );
        $stmt->execute(['gueltig', $seit->format(self::FORMAT)]);

        $summen = [];
        foreach ($stmt->fetchAll() as $row) {
            $aufrufe = (int) $row['aufrufe'];
            $gueltig = (int) $row['gueltig'];
            $summen[] = [
                'providerId' => $row['provider_id'] === null ? null : (int) $row['provider_id'],
                'name' => (string) $row['provider_name'],
                'aufrufe' => $aufrufe,
                'gueltig' => $gueltig,
                'fehler' => $aufrufe - $gueltig,
                'tokens' => (int) $row['tokens'],
                'dauerMs' => $row['dauer'] === null ? null : (int) round((float) $row['dauer']),
            ];
        }

        return $summen;
    }

    /**
     * The newest calls since $seit of one group of summen() (provider and
     * name; a null provider = profiles deleted since), newest first.
     *
     * @return list<array{zeitpunkt: \DateTimeImmutable, modell: string, promptVersion: string, reparatur: bool, fallback: bool, status: string, promptTokens: ?int, antwortTokens: ?int, tokens: ?int, dauerMs: ?int, httpStatus: ?int}>
     */
    public function neueste(?int $providerId, string $name, \DateTimeImmutable $seit, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ai_call_log WHERE ' . ($providerId === null ? 'provider_id IS NULL' : 'provider_id = ?')
            . ' AND provider_name = ? AND called_at >= ?'
            . ' ORDER BY called_at DESC, id DESC LIMIT ' . max(1, $limit),
        );
        $stmt->execute([...($providerId === null ? [] : [$providerId]), $name, $seit->format(self::FORMAT)]);

        $int = static fn(mixed $wert): ?int => $wert === null ? null : (int) $wert;

        return array_map(static fn(array $row): array => [
            'zeitpunkt' => new \DateTimeImmutable((string) $row['called_at']),
            'modell' => (string) $row['model'],
            'promptVersion' => (string) $row['prompt_version'],
            'reparatur' => (bool) $row['is_repair'],
            'fallback' => (bool) $row['is_fallback'],
            'status' => (string) $row['status'],
            'promptTokens' => $int($row['prompt_tokens']),
            'antwortTokens' => $int($row['answer_tokens']),
            'tokens' => $int($row['total_tokens']),
            'dauerMs' => $int($row['duration_ms']),
            'httpStatus' => $int($row['http_status']),
        ], $stmt->fetchAll());
    }
}
