<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Repository\SubmissionRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Completes a `submission` row: seals its payload and gives it the next free
 * reference number "R-<Jahr>-<laufende Nummer>" (docs/spec/03-erfassung-und-ki.md
 * section 1). Shared by the public submission (App\Service\Submission\
 * SubmissionService, issue #24/M4-2) and the internal capture
 * (App\Service\Submission\InterneErfassung, issue #28/M4-6) - one numbering
 * for both, so every receipt in the inbox carries a reference.
 *
 * Runs inside the caller's transaction.
 */
final readonly class Referenzvergabe
{
    /** Reference candidates tried before giving up (mirrors App\Service\Audit\AuditLog::MAX_ATTEMPTS). */
    private const int MAX_REFERENZ_VERSUCHE = 5;

    private const string SUBMISSION_TABLE = 'submission';
    private const string PAYLOAD_COLUMN = 'payload_enc';

    public function __construct(private SubmissionRepository $submissions)
    {
    }

    /**
     * Reserves the row, seals the payload under a fresh data key and
     * returns [submission id, reference].
     *
     * @return array{0: int, 1: string}
     */
    public function anlegen(string $formHash, EinreichungsAngaben $angaben, Vault $vault, \DateTimeImmutable $now): array
    {
        $id = $this->submissions->insertDraft($formHash, $now);

        $key = DataKey::generate();
        $payloadEnc = FieldCipher::encrypt(
            $key,
            json_encode($angaben->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            new FieldContext(self::SUBMISSION_TABLE, $id, self::PAYLOAD_COLUMN),
        );

        return [$id, $this->reserviere($id, $vault->sealDataKey($key), $payloadEnc, $now)];
    }

    /**
     * Retries the reference candidate on a collision - the same shape as
     * App\Service\Audit\AuditLog::record(), just an UPDATE instead of an
     * INSERT: the row already exists (from insertDraft()), only its
     * reference_code needs a value nobody else has taken yet. A duplicate
     * key error does not abort the surrounding transaction on InnoDB, so the
     * next candidate is simply tried within the same one.
     */
    private function reserviere(int $id, string $dekSealed, string $payloadEnc, \DateTimeImmutable $now): string
    {
        $jahr = (int) $now->format('Y');

        for ($versuch = 1; ; $versuch++) {
            $referenz = sprintf('R-%d-%04d', $jahr, $this->submissions->hoechsteLaufnummer($jahr) + 1);

            try {
                $this->submissions->complete($id, $referenz, $dekSealed, $payloadEnc);

                return $referenz;
            } catch (\PDOException $e) {
                if ($versuch >= self::MAX_REFERENZ_VERSUCHE || !self::istDuplicateKey($e)) {
                    throw $e;
                }
            }
        }
    }

    private static function istDuplicateKey(\PDOException $e): bool
    {
        return $e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062;
    }
}
