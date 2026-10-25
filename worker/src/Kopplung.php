<?php

declare(strict_types=1);

namespace App\Worker;

use App\Service\Worker\WorkerProtokoll;
use App\Worker\Http\UrlRegel;
use App\Worker\Http\WorkerHttp;

/**
 * `worker pair <url> <code>` (docs/spec/07-worker.md section 4 step 2): the
 * worker makes its key pair W_pub/W_priv - W_priv never leaves it - sends
 * W_pub with the pairing code and stores id and access key. The admin then
 * compares the fingerprint shown here with the one on the admin page and
 * confirms; until then the web host answers heartbeats only.
 */
final class Kopplung
{
    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException|ApiFehler|\App\Worker\Http\HttpFehler
     */
    public static function koppeln(WorkerHttp $http, string $url, string $code, string $name, string $version, string $datei, bool $httpLocalhostErlaubt = false): Identitaet
    {
        $basis = UrlRegel::basis($url, $httpLocalhostErlaubt);
        $schluesselpaar = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($schluesselpaar);

        $antwort = ApiClient::koppeln($http, $basis, [
            'code' => trim($code),
            'public_key' => base64_encode($publicKey),
            'name' => mb_substr(trim($name), 0, 64),
            'version' => $version,
            'protokoll_version' => WorkerProtokoll::VERSION,
        ]);

        $schluessel = is_string($antwort['schluessel'] ?? null) ? base64_decode($antwort['schluessel'], true) : false;
        $workerId = $antwort['worker_id'] ?? null;
        if (!is_int($workerId) || !is_string($schluessel)) {
            throw new ApiFehler(200, 'kopplung_ungueltig', 'Die Antwort des Webhosters ist unvollständig.');
        }
        $identitaet = new Identitaet($workerId, $schluessel, $schluesselpaar, $basis, $name);
        // Both sides compute the fingerprint of the same W_pub; a different
        // one means the key did not arrive as sent.
        if (($antwort['fingerabdruck'] ?? null) !== $identitaet->fingerabdruck()) {
            throw new ApiFehler(200, 'fingerabdruck_abweichend', 'Der Webhoster meldet einen anderen Fingerabdruck – Kopplung abgebrochen.');
        }
        $identitaet->speichern($datei);
        sodium_memzero($schluesselpaar);

        return $identitaet;
    }
}
