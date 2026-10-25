<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Security review (issue #78): the dummy hash behind verifyDummy() must cost
 * exactly what a real hash costs, and verifyDummy() must not hash anything
 * itself - otherwise an unknown address answers measurably slower than a
 * wrong password (user enumeration through latency).
 */
final class PasswordHasherTest extends TestCase
{
    public function testTheDummyMatchesTheCostOfARealHash(): void
    {
        $hasher = new PasswordHasher();
        $echt = $hasher->hash('ein-langes-passwort');

        self::assertFalse($hasher->needsRehash(PasswordHasher::dummyHash()), 'PHP raised a default cost: regenerate the DUMMY_* constants');
        self::assertSame(password_get_info($echt), password_get_info(PasswordHasher::dummyHash()));
    }

    public function testBothDummiesAreValidHashesOfTheirAlgorithm(): void
    {
        self::assertSame('argon2id', password_get_info(PasswordHasher::DUMMY_ARGON2ID)['algoName']);
        self::assertSame('bcrypt', password_get_info(PasswordHasher::DUMMY_BCRYPT)['algoName']);
    }

    public function testTheDummyNeverMatches(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verifyDummy(''));
        self::assertFalse($hasher->verifyDummy('ein-langes-passwort'));
    }

    public function testVerifyDummyDoesNotHash(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 3) . '/app/src/Service/Account/PasswordHasher.php');
        preg_match('/function verifyDummy\(.*?\n    \}/s', $quelle, $m);

        self::assertNotEmpty($m);
        self::assertStringNotContainsString('hash(', str_replace('dummyHash(', '', $m[0]));
    }
}
