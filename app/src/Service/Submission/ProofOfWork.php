<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Invisible proof of work for `/einreichen` (docs/spec/01-sicherheit.md
 * section 5, issue #25/M4-3): the ALTCHA principle, self-hosted, no
 * third party, no cookie banner.
 *
 * Stateless like App\Service\Submission\FormToken: the challenge a visitor
 * has to solve is derived from their form token's nonce and a key of its
 * own under the server key (level 1, CLAUDE.md section 4), so nothing is
 * stored server side and nothing is looked up to verify a solution.
 *
 * The idea: pick a secret number the browser cannot simply read, but can
 * only find by brute force - hashing `salt . candidate` for every candidate
 * from 0 up until the result matches the published `challenge`. Finding it
 * costs real CPU time (the point of the exercise); checking it back costs
 * nothing, because the server already knows the number - it derived it the
 * same deterministic way the browser eventually rediscovers it, just
 * without the brute force.
 *
 * `salt` is the token's own nonce as hex - not secret (App\Service\
 * Submission\FormToken's docblock), so reusing it here costs nothing and
 * ties the challenge to the same visit the token already identifies.
 */
final readonly class ProofOfWork
{
    /**
     * Upper bound of the secret number. High enough that solving it takes
     * roughly a second of hashing on an ordinary phone - the "unsichtbar"
     * of docs/spec/01-sicherheit.md section 5 - low enough that it never
     * turns into a real wait.
     */
    public const int MAX_ZAHL = 100_000;

    private const int SERVER_KEY_BYTES = 32;
    private const int KEY_BYTES = 32;

    private string $key;

    public function __construct(string $serverKey)
    {
        if (strlen($serverKey) !== self::SERVER_KEY_BYTES) {
            throw new \InvalidArgumentException(sprintf('The server key is %d bytes long.', self::SERVER_KEY_BYTES));
        }

        $this->key = sodium_crypto_generichash('einreichung-pow-v1', $serverKey, self::KEY_BYTES);
    }

    /**
     * @return array{salt: string, challenge: string, max: int} everything
     *         the page needs to render the challenge; none of it is a
     *         secret - the secret number itself never appears here.
     */
    public function challenge(FormTokenData $token): array
    {
        $salt = bin2hex($token->nonce);

        return [
            'salt' => $salt,
            'challenge' => hash('sha256', $salt . $this->zahlFuer($token)),
            'max' => self::MAX_ZAHL,
        ];
    }

    /**
     * Whether $loesung is the secret number belonging to this token's
     * challenge - the same check for every caller, upload and final submit
     * alike (docs/spec/01-sicherheit.md section 5).
     */
    public function pruefen(FormTokenData $token, ?string $loesung): bool
    {
        if ($loesung === null || $loesung === '' || !ctype_digit($loesung)) {
            return false;
        }

        return hash_equals((string) $this->zahlFuer($token), $loesung);
    }

    /**
     * The secret number: deterministic from the token's nonce, so both
     * challenge() and pruefen() always land on the same value for the same
     * token without either one storing it anywhere.
     */
    private function zahlFuer(FormTokenData $token): int
    {
        $digest = hash_hmac('sha256', $token->nonce, $this->key, true);
        $unpacked = unpack('N', substr($digest, 0, 4));

        return ($unpacked === false ? 0 : $unpacked[1]) % (self::MAX_ZAHL + 1);
    }
}
