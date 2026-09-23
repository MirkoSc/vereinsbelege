<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Repository\AuditLogRepository;
use App\Repository\VaultRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\ServerCrypto;
use App\Service\Crypto\Vault;

/**
 * Writing and checking the audit log (docs/spec/01-sicherheit.md section 6,
 * issue #21/M3-8).
 *
 * Action, entity, time and user are plaintext - that is what the list
 * filters on. Everything else a caller wants to keep goes into `$details`,
 * which is sealed to the vault like any other club data: writing needs no
 * secret, reading needs an unlocked session. Details are small scalar
 * facts (a reason, role ids, the names of changed fields) - never a
 * password, token, code, address or amount, not even encrypted: the log
 * outlives every rotation and is copied into every backup.
 *
 * The client IP is kept only as an HMAC under the server key
 * ("audit.ip"): it tells "same address as that other row" without ever
 * naming it.
 *
 * Each row is ONE insert, never updated. Its id is the head's + 1 and is
 * known before the insert, because the details' AAD and the hash need it;
 * two requests that race for the same id collide on the primary key and
 * the loser re-reads the head (up to MAX_ATTEMPTS times).
 */
final readonly class AuditLog
{
    public const int MAX_ATTEMPTS = 5;

    /** Rows per request of the integrity check - the request stays short. */
    public const int PRUEF_SCHRITT = 2000;

    private const string TABLE = 'audit_log';
    private const string DETAILS_COLUMN = 'details_enc';
    private const string FORMAT = 'Y-m-d H:i:s';
    private const string IP_PURPOSE = 'audit.ip';

    public function __construct(
        private AuditLogRepository $repository,
        private VaultRepository $vaults,
        private ServerCrypto $crypto,
    ) {
    }

    /**
     * @param int|null $userId the acting account, null when there is none
     *        (a failed login)
     * @param string $ip the client address as the request saw it; '' for
     *        none
     * @param int|null $entityId the object the action concerns - its kind
     *        is AuditAction::entity()
     * @param array<string, scalar|null|list<scalar>> $details
     */
    public function record(
        AuditAction $action,
        ?int $userId,
        string $ip,
        ?int $entityId = null,
        array $details = [],
        ?\DateTimeImmutable $now = null,
    ): AuditEntry {
        $ts = ($now ?? new \DateTimeImmutable())->format(self::FORMAT);
        $ipHash = $ip === '' ? null : $this->crypto->blindIndex()->forValue(self::IP_PURPOSE, $ip);
        $vault = $details === [] ? null : $this->vaults->current();
        $klartext = $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        for ($versuch = 1; ; ++$versuch) {
            $head = $this->repository->head();
            $id = ($head?->id ?? 0) + 1;

            $detailsEnc = null;
            $dekSealed = null;
            if ($klartext !== null && $vault !== null) {
                // A fresh key per attempt: the AAD names the id, and the id
                // may have changed since the last one.
                $key = DataKey::generate();
                $detailsEnc = FieldCipher::encrypt($key, $klartext, new FieldContext(self::TABLE, $id, self::DETAILS_COLUMN));
                $dekSealed = $vault->sealDataKey($key);
            }

            $entry = new AuditEntry(
                id: $id,
                ts: $ts,
                userId: $userId,
                action: $action->value,
                entity: $entityId === null ? null : $action->entity(),
                entityId: $entityId,
                ipHash: $ipHash,
                detailsEnc: $detailsEnc,
                dekSealed: $dekSealed,
                prevHash: $head?->hash ?? AuditChain::GENESIS,
                hash: '',
            );
            $entry = $entry->mitHash(AuditChain::hash($entry->prevHash, $entry));

            try {
                $this->repository->insert($entry);

                return $entry;
            } catch (AuditConflict $e) {
                if ($versuch >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The details of a row, readable only with the unlocked vault. Null
     * when the row has none or they do not open (another vault version,
     * damaged ciphertext) - the list then says so instead of failing.
     *
     * @return array<string, mixed>|null
     */
    public function details(AuditEntry $entry, Vault $vault): ?array
    {
        if ($entry->detailsEnc === null || $entry->dekSealed === null) {
            return null;
        }

        try {
            $klartext = FieldCipher::decrypt(
                $vault->openDataKey($entry->dekSealed),
                $entry->detailsEnc,
                new FieldContext(self::TABLE, $entry->id, self::DETAILS_COLUMN),
            );
            $details = json_decode($klartext, true, 8, JSON_THROW_ON_ERROR);
        } catch (CryptoException | \JsonException) {
            return null;
        }

        return is_array($details) ? $details : null;
    }

    /**
     * One step of the integrity check: up to PRUEF_SCHRITT rows after the
     * row with id `$nachId` (0 = from the start). The step's starting hash
     * is read from that row itself - it held in the previous step, so its
     * stored hash is its computed one - which keeps every step stateless.
     */
    public function pruefeAbschnitt(int $nachId): AuditChainResult
    {
        $nachHash = AuditChain::GENESIS;
        if ($nachId > 0) {
            $anker = $this->repository->find($nachId);
            if ($anker === null) {
                // The row the last step ended on is gone now.
                return new AuditChainResult(0, $nachId, AuditChain::GENESIS, $nachId, AuditChainBreak::Luecke);
            }
            $nachHash = $anker->hash;
        }

        return AuditChain::verify($this->repository->after($nachId, self::PRUEF_SCHRITT), $nachId, $nachHash);
    }

    public function anzahl(): int
    {
        return $this->repository->count();
    }
}
