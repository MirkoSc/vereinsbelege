<?php

declare(strict_types=1);

namespace App\Service\Tresor;

use App\Domain\JobExecutor;
use App\Repository\JobRepository;
use App\Repository\VaultRotationRepository;
use App\Service\Crypto\Vault;
use App\Service\Document\Duplikatindex;

/**
 * `document.content_bi` (issue #90): the content index is an HMAC over the
 * ORIGINAL files - recomputing it means reading every file, which is far
 * more than a short request may do. It is cleared instead, and the
 * `detect_duplicate` job computes it again under the new key the next time
 * a session works the queue (the same way migration 023 indexed the stock).
 * A pending job of that type goes back to its first step: its intermediate
 * state holds file indexes under the old key.
 */
final readonly class DokumentIndexpflege implements Indexpflege
{
    private const string REF_TYPE = 'document';

    public function __construct(
        private JobRepository $jobs,
        private VaultRotationRepository $rotation,
    ) {
    }

    public function tabelle(): string
    {
        return 'document';
    }

    public function spalten(): array
    {
        return ['document.content_bi'];
    }

    public function pflege(int $id, array $zeile, Vault $tresor, int $alteVersion, \DateTimeImmutable $now): void
    {
        $this->jobs->setzeZurueck(Duplikatindex::JOB_TYP, self::REF_TYPE, $id, $now);
        if ($zeile['content_bi'] === null) {
            return;
        }

        $this->rotation->setzeSpalte($this->tabelle(), 'id', $id, 'content_bi', null);
        if (!$this->jobs->offenFuer([Duplikatindex::JOB_TYP], self::REF_TYPE, $id)) {
            $this->jobs->enqueue(Duplikatindex::JOB_TYP, JobExecutor::Session, self::REF_TYPE, $id, now: $now);
        }
    }
}
