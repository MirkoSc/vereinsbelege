<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * No worker grant from this session: no active worker (`kein_worker`), the
 * vault is locked (`tresor_gesperrt`), or a vault key rotation runs or the
 * session holds an older generation (`rotation`, issue #90). The message is
 * only that code - nothing about the document.
 */
final class WorkerFreigabeVerweigert extends \RuntimeException
{
    public function __construct(public readonly string $grund)
    {
        parent::__construct($grund);
    }
}
