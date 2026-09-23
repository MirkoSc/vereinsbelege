<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Service\Submission\FormTokenData;
use App\Service\Submission\ProofOfWork;
use PHPUnit\Framework\TestCase;

/**
 * The public submission's invisible proof of work (docs/spec/
 * 01-sicherheit.md section 5, issue #25/M4-3).
 */
final class ProofOfWorkTest extends TestCase
{
    public function testTheSolutionDerivedFromTheChallengeIsAccepted(): void
    {
        $powLoesung = new ProofOfWork(str_repeat('k', 32));
        $token = self::tokenData();

        $challenge = $powLoesung->challenge($token);
        $loesung = self::bruteForce($challenge);

        self::assertTrue($powLoesung->pruefen($token, $loesung));
    }

    public function testTheSameTokenAlwaysGetsTheSameChallenge(): void
    {
        $powLoesung = new ProofOfWork(str_repeat('k', 32));
        $token = self::tokenData();

        self::assertSame($powLoesung->challenge($token), $powLoesung->challenge($token));
    }

    public function testTwoTokensGetDifferentChallenges(): void
    {
        $powLoesung = new ProofOfWork(str_repeat('k', 32));

        $a = $powLoesung->challenge(self::tokenData('a'));
        $b = $powLoesung->challenge(self::tokenData('b'));

        self::assertNotSame($a['challenge'], $b['challenge']);
    }

    public function testAWrongNumberIsRejected(): void
    {
        $powLoesung = new ProofOfWork(str_repeat('k', 32));
        $token = self::tokenData();
        $challenge = $powLoesung->challenge($token);
        $richtig = self::bruteForce($challenge);

        self::assertFalse($powLoesung->pruefen($token, (string) ((int) $richtig + 1)));
    }

    public function testTheSolutionOfOneTokenDoesNotSolveAnother(): void
    {
        $powLoesung = new ProofOfWork(str_repeat('k', 32));
        $loesungA = self::bruteForce($powLoesung->challenge(self::tokenData('a')));

        self::assertFalse($powLoesung->pruefen(self::tokenData('b'), $loesungA));
    }

    public function testGarbageIsRejectedWithoutThrowing(): void
    {
        $powLoesung = new ProofOfWork(str_repeat('k', 32));
        $token = self::tokenData();

        self::assertFalse($powLoesung->pruefen($token, null));
        self::assertFalse($powLoesung->pruefen($token, ''));
        self::assertFalse($powLoesung->pruefen($token, 'nicht-numerisch'));
        self::assertFalse($powLoesung->pruefen($token, '-1'));
    }

    public function testTheServerKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProofOfWork('zu kurz');
    }

    private static function tokenData(string $nonce = 'ein-nonce-------'): FormTokenData
    {
        return new FormTokenData($nonce, new \DateTimeImmutable());
    }

    /**
     * The same brute force public/js/einreichen.js does, in PHP: proof that
     * challenge()/pruefen() actually agree on a solution a real client can
     * find, not only that they are internally consistent.
     *
     * @param array{salt: string, challenge: string, max: int} $challenge
     */
    private static function bruteForce(array $challenge): string
    {
        for ($zahl = 0; $zahl <= $challenge['max']; $zahl++) {
            if (hash('sha256', $challenge['salt'] . $zahl) === $challenge['challenge']) {
                return (string) $zahl;
            }
        }

        self::fail('No proof-of-work solution found within the configured range.');
    }
}
