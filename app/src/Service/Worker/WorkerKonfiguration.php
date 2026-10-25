<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\Worker;
use App\Repository\AiProviderRepository;
use App\Repository\WorkerRepository;
use App\Service\Crypto\ServerCrypto;

/**
 * The worker's configuration: the AI provider profiles including their API
 * keys, sealed to W_pub (docs/spec/07-worker.md section 3
 * "KI-Zugangsdaten", issue #52/M7b-2). Configured in one place only, the
 * web host's admin; the worker fetches the envelope with its heartbeat and
 * keeps it in memory.
 *
 * API keys are encrypted under the server key, so this needs no session.
 * Instead of hooking every admin action that changes a provider, the
 * heartbeat compares a keyed hash of what it would seal with the hash of
 * what it sealed last, and seals anew (with a higher `config_version`) only
 * when they differ - a changed key reaches the worker within one polling
 * interval, and the plaintext is never stored.
 */
final readonly class WorkerKonfiguration
{
    public const string FORMAT = 'worker-config-v1';

    private const string HASH_ZWECK = 'worker.config';

    public function __construct(
        private WorkerRepository $workers,
        private AiProviderRepository $anbieter,
        private ServerCrypto $crypto,
    ) {
    }

    /**
     * Seals the current configuration to the worker if it changed; returns
     * the worker as stored afterwards. Only for an active worker - an
     * unconfirmed one gets no API key.
     */
    public function aktualisieren(Worker $worker, bool $kiSchluesselErlaubt): Worker
    {
        $profile = [];
        // Without the release (module off, no `ai` capability - issue #53,
        // WorkerRouting::kiSchluesselErlaubt()) the worker gets no profile
        // at all; the changed hash makes it replace keys it got before.
        foreach ($kiSchluesselErlaubt ? $this->anbieter->all() : [] as $a) {
            if (!$a->active) {
                continue;
            }
            $profile[] = [
                'id' => $a->id,
                'name' => $a->name,
                'base_url' => $a->baseUrl,
                'model' => $a->model,
                'faehigkeiten' => json_decode($a->faehigkeiten->toJson(), true),
                'timeout_s' => $a->timeoutS,
                'standard' => $a->isDefault,
                'fallback' => $a->isFallback,
                'api_key' => $this->anbieter->apiKey($a->id),
            ];
        }
        $json = json_encode(['format' => self::FORMAT, 'ki_anbieter' => $profile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // Bound to the worker's key as well: a re-paired worker gets its own
        // envelope. The keyed hash goes over a hex digest: forValue()
        // normalises case and whitespace, which must not hide a changed key.
        $hash = $this->crypto->blindIndex()->forValue(self::HASH_ZWECK, hash('sha256', $worker->publicKey . $json));

        $bisher = $this->workers->konfigHash($worker->id);
        if ($bisher !== null && hash_equals($bisher, $hash) && $worker->configSealed !== null) {
            sodium_memzero($json);

            return $worker;
        }

        $versiegelt = sodium_crypto_box_seal($json, $worker->publicKey);
        sodium_memzero($json);
        $this->workers->setzeKonfiguration($worker->id, $versiegelt, $worker->configVersion, $hash);

        return $this->workers->find($worker->id) ?? $worker;
    }
}
