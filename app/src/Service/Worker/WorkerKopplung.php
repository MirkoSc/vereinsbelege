<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Domain\AuditAction;
use App\Repository\JobRepository;
use App\Repository\WorkerRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\ServerCrypto;
use App\Service\RateLimiter;

/**
 * Pairing, confirming and unpairing the optional worker
 * (docs/spec/07-worker.md section 4, issue #51/M7b-1, E-28).
 *
 * 1. The admin asks for a code: 8 characters (Crockford base32, 40 bits),
 *    15 minutes, single use; only its HMAC under a server-key-derived key
 *    is stored, and a new code replaces any earlier unused one.
 * 2. The worker sends the code and its public key W_pub and gets a worker
 *    id plus a fresh 32-byte access key, which leaves the web host exactly
 *    once and is stored server-key encrypted (an HMAC cannot be checked
 *    against a hash of its key).
 * 3. The worker is `gekoppelt` - it can send heartbeats, but claims nothing
 *    until an admin compared the fingerprint of W_pub and confirmed it.
 *
 * Guessing a code is rate limited per IP and in total, so even an attacker
 * with many addresses gets a handful of tries out of 2^40 per window.
 */
final readonly class WorkerKopplung
{
    public const int CODE_LAENGE = 8;
    public const int CODE_GUELTIG_MINUTEN = 15;

    public const int FEHLER_LIMIT_PRO_IP = 10;
    public const int FEHLER_LIMIT_GESAMT = 50;
    /** The window of the RateLimiter passed in. */
    public const int FEHLER_FENSTER_SEKUNDEN = 900;

    /** Crockford base32: no I, L, O, U - nothing to confuse on a screen. */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const string ZWECK = 'worker_pair';
    private const string INDEX_ZWECK = 'worker.pairing';

    public function __construct(
        private WorkerRepository $workers,
        private JobRepository $jobs,
        private ServerCrypto $crypto,
        private RateLimiter $fehlversuche,
        private AuditLog $audit,
        private WorkerFreigaben $freigaben,
    ) {
    }

    /**
     * A new pairing code, or null while a worker is paired or active - v1
     * supports one, so the old one is unpaired first.
     */
    public function codeErzeugen(?int $userId, string $ip, \DateTimeImmutable $now): ?string
    {
        if ($this->workers->aktueller() !== null) {
            return null;
        }

        $code = '';
        for ($i = 0; $i < self::CODE_LAENGE; ++$i) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $this->workers->neuerCode(
            $this->codeHash($code),
            $now->setTimestamp($now->getTimestamp() + self::CODE_GUELTIG_MINUTEN * 60),
            $userId,
            $now,
        );
        $this->audit->record(AuditAction::WorkerKopplungscode, $userId, $ip, now: $now);

        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /**
     * Redeems a code (POST /api/worker/pair). Everything about the request
     * is checked before the code is used up, except the code itself.
     */
    public function koppeln(
        string $code,
        string $publicKeyB64,
        string $name,
        int $protokollVersion,
        string $appVersion,
        string $ip,
        \DateTimeImmutable $now,
    ): KopplungsErgebnis {
        if (
            $this->fehlversuche->isBlocked(self::ZWECK, $ip, self::FEHLER_LIMIT_PRO_IP, $now)
            || $this->fehlversuche->isBlocked(self::ZWECK, 'alle', self::FEHLER_LIMIT_GESAMT, $now)
        ) {
            return KopplungsErgebnis::abgelehnt('zu_viele_fehlversuche', 429);
        }
        if (!WorkerProtokoll::unterstuetzt($protokollVersion)) {
            return KopplungsErgebnis::abgelehnt('protokoll', 409);
        }

        $publicKey = base64_decode($publicKeyB64, true);
        if (
            !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
            || hash_equals(str_repeat("\0", SODIUM_CRYPTO_BOX_PUBLICKEYBYTES), $publicKey)
        ) {
            return KopplungsErgebnis::abgelehnt('schluessel_ungueltig', 422);
        }

        // Counted before the code is tried, atomically (N-09, issue #246):
        // parallel guesses cannot overshoot the limit. The read-only check
        // above only answers a blocked client early.
        if (
            !$this->fehlversuche->versuch(self::ZWECK, $ip, self::FEHLER_LIMIT_PRO_IP, $now)
            || !$this->fehlversuche->versuch(self::ZWECK, 'alle', self::FEHLER_LIMIT_GESAMT, $now)
        ) {
            return KopplungsErgebnis::abgelehnt('zu_viele_fehlversuche', 429);
        }

        $normiert = self::normiereCode($code);
        $pairingId = $normiert === null ? null : $this->workers->codeEinloesen($this->codeHash($normiert), $now);
        if ($pairingId === null) {
            return KopplungsErgebnis::abgelehnt('code_ungueltig', 403);
        }
        // A valid code was no failed attempt.
        $this->fehlversuche->erstatten(self::ZWECK, $ip, $now);
        $this->fehlversuche->erstatten(self::ZWECK, 'alle', $now);
        // Codes are only handed out while no worker exists; this is the net
        // for a worker paired with an older code in between.
        if ($this->workers->aktueller() !== null) {
            return KopplungsErgebnis::abgelehnt('bereits_gekoppelt', 409);
        }

        $schluessel = random_bytes(WorkerProtokoll::SCHLUESSEL_BYTES);
        $fingerabdruck = WorkerProtokoll::fingerabdruck($publicKey);
        $workerId = $this->workers->anlegen(
            self::normiereName($name),
            $publicKey,
            $fingerabdruck,
            $this->crypto->encrypt($schluessel),
            $protokollVersion,
            self::normiereVersion($appVersion),
            $now,
        );
        $this->workers->codeZuordnen($pairingId, $workerId);
        $this->audit->record(AuditAction::WorkerGekoppelt, null, $ip, $workerId, now: $now);

        return KopplungsErgebnis::gekoppelt($workerId, $schluessel, $fingerabdruck);
    }

    public function bestaetigen(int $workerId, ?int $userId, string $ip, \DateTimeImmutable $now): bool
    {
        if (!$this->workers->bestaetigen($workerId, $userId, $now)) {
            return false;
        }
        $this->audit->record(AuditAction::WorkerBestaetigt, $userId, $ip, $workerId, now: $now);

        return true;
    }

    /**
     * Unpairs: the access key is deleted and every job the worker holds is
     * given back at once, so nothing waits for its lock to run out. Its
     * grants, contexts and unfinished uploads go as well (issue #52) -
     * nothing sealed to its key stays. Routing its jobs back to the
     * browser/session (issue #53) hooks in here.
     */
    public function entkoppeln(int $workerId, ?int $userId, string $ip, \DateTimeImmutable $now): bool
    {
        $worker = $this->workers->find($workerId);
        if ($worker === null || !$this->workers->sperren($workerId, $userId, $now)) {
            return false;
        }
        $this->jobs->freigebenFuerPraefix($worker->lockPraefix(), $now);
        $this->freigaben->entkoppelt($workerId);
        $this->audit->record(AuditAction::WorkerEntkoppelt, $userId, $ip, $workerId, now: $now);

        return true;
    }

    /**
     * Upper case, without blanks and dashes, the Crockford look-alikes
     * folded (O -> 0, I/L -> 1); null for anything that cannot be a code.
     */
    public static function normiereCode(string $code): ?string
    {
        $code = strtr(strtoupper(str_replace([' ', '-'], '', trim($code))), ['O' => '0', 'I' => '1', 'L' => '1']);

        return preg_match('/^[' . self::ALPHABET . ']{' . self::CODE_LAENGE . '}$/', $code) === 1 ? $code : null;
    }

    private function codeHash(string $normiert): string
    {
        return $this->crypto->blindIndex()->forValue(self::INDEX_ZWECK, $normiert);
    }

    private static function normiereName(string $name): string
    {
        $name = trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', $name) ?? '');
        $name = mb_substr($name, 0, 64);

        return $name === '' ? 'Worker' : $name;
    }

    private static function normiereVersion(string $version): string
    {
        return preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $version) === 1 ? $version : '';
    }
}
