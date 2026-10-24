<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of `audit_log` exactly as stored (migrations/011_audit_log.sql):
 * the plaintext structure fields, the ciphertext of the details and the two
 * chain hashes. Nothing here is decrypted - the chain is checked over what
 * is stored, so the check needs no vault (App\Service\Audit\AuditChain).
 *
 * `action` stays a string rather than an AuditAction: a row written by a
 * later version with an action this one does not know must still verify
 * and still show up.
 */
final readonly class AuditEntry
{
    public function __construct(
        public int $id,
        /** `Y-m-d H:i:s`, Europe/Berlin - the stored string, hashed as is. */
        public string $ts,
        public ?int $userId,
        public string $action,
        public ?string $entity,
        public ?int $entityId,
        /** raw 32 bytes or null */
        public ?string $ipHash,
        public ?string $detailsEnc,
        public ?string $dekSealed,
        /** raw 32 bytes */
        public string $prevHash,
        /** raw 32 bytes */
        public string $hash,
        /**
         * The data key re-sealed to a newer vault generation by a key
         * rotation (issue #90, migration 039) - OUTSIDE the hash chain, which
         * covers `dekSealed` as originally written. Null until a rotation
         * happened after this row was written.
         */
        public ?string $dekResealed = null,
    ) {
    }

    /**
     * The sealed data key a reader opens: the re-sealed one when a rotation
     * wrote it, else the original.
     */
    public function dekZumOeffnen(): ?string
    {
        return $this->dekResealed ?? $this->dekSealed;
    }

    /** The same row with its hash filled in - the hash covers everything else. */
    public function mitHash(string $hash): self
    {
        return new self(
            $this->id,
            $this->ts,
            $this->userId,
            $this->action,
            $this->entity,
            $this->entityId,
            $this->ipHash,
            $this->detailsEnc,
            $this->dekSealed,
            $this->prevHash,
            $hash,
            $this->dekResealed,
        );
    }

    public function aktion(): ?AuditAction
    {
        return AuditAction::tryFrom($this->action);
    }
}
