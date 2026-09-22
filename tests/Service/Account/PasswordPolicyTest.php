<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * docs/spec/01-sicherheit.md section 3: minimum length plus a check against
 * common passwords, no composition rules.
 */
final class PasswordPolicyTest extends TestCase
{
    private function policy(): PasswordPolicy
    {
        return new PasswordPolicy(dirname(__DIR__, 3) . '/app/data/haeufige-passwoerter.txt');
    }

    public function testAGoodPasswordHasNoViolations(): void
    {
        self::assertSame([], $this->policy()->violations('ein-ausreichend-langes-testpasswort'));
        self::assertTrue($this->policy()->isAcceptable('ein-ausreichend-langes-testpasswort'));
    }

    public function testAPasswordBelowTheMinimumLengthIsRejected(): void
    {
        $violations = $this->policy()->violations('kurz1234567');

        self::assertNotSame([], $violations);
        self::assertStringContainsString('mindestens 12 Zeichen', $violations[0]);
        self::assertFalse($this->policy()->isAcceptable('kurz1234567'));
    }

    public function testExactlyTheMinimumLengthPasses(): void
    {
        $password = str_repeat('x', PasswordPolicy::MIN_LENGTH);

        self::assertSame(PasswordPolicy::MIN_LENGTH, mb_strlen($password));
        self::assertSame([], $this->policy()->violations($password));
    }

    /**
     * mb_strlen and not strlen: a password made of umlauts is not shorter
     * just because it is one byte per character over ASCII.
     */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $password = str_repeat('ä', PasswordPolicy::MIN_LENGTH);

        self::assertSame(PasswordPolicy::MIN_LENGTH, mb_strlen($password));
        self::assertNotSame(PasswordPolicy::MIN_LENGTH, strlen($password), 'sanity check: ä is two bytes in UTF-8');
        self::assertSame([], $this->policy()->violations($password));
    }

    public function testACommonPasswordIsRejectedEvenWhenLongEnough(): void
    {
        $violations = $this->policy()->violations('passwort1234');

        self::assertNotSame([], $violations);
        self::assertStringContainsString('zu bekannt', $violations[0]);
    }

    public function testTheCommonPasswordCheckIsCaseInsensitive(): void
    {
        self::assertFalse($this->policy()->isAcceptable('PASSWORT1234'));
    }

    public function testAMissingListDoesNotBreakTheLengthCheck(): void
    {
        $policy = new PasswordPolicy('/nicht/vorhanden.txt');

        self::assertSame([], $policy->violations('ein-ausreichend-langes-testpasswort'));
        self::assertNotSame([], $policy->violations('zu-kurz'));
    }

    public function testTheShippedListExistsAndIsNotEmpty(): void
    {
        $file = dirname(__DIR__, 3) . '/app/data/haeufige-passwoerter.txt';

        self::assertFileExists($file);
        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertGreaterThan(100, count($lines));
    }
}
