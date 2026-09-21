<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\RecoveryKey;
use App\Service\Crypto\Vault;
use PHPUnit\Framework\TestCase;

/**
 * The recovery key (docs/spec/01-sicherheit.md section 2): the last way into
 * the vault, written on paper. Its job is to survive being copied by hand and
 * typed back in - and to say clearly when it was not.
 */
final class RecoveryKeyTest extends TestCase
{
    public function testFormattedKeyIsSevenGroupsOfEight(): void
    {
        $formatted = RecoveryKey::forVault(Vault::create())->formatted();

        self::assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{8}( [0-9A-HJKMNP-TV-Z]{8}){6}$/',
            $formatted,
            'Crockford base32 leaves out I, L, O and U.',
        );
        self::assertCount(7, explode(' ', $formatted));
    }

    public function testRoundTripOpensTheSameVault(): void
    {
        $vault = Vault::create();
        $sealed = $vault->sealDataKey($key = DataKey::generate());

        $recovered = RecoveryKey::parse(RecoveryKey::forVault($vault)->formatted())
            ->openVault($vault->publicKey());

        self::assertTrue($recovered->isUnlocked());
        self::assertTrue($recovered->openDataKey($sealed)->equals($key));
    }

    /**
     * Typed back in the way people actually type: lower case, no groups, and
     * with the characters Crockford's alphabet avoids because they get
     * confused on paper.
     */
    public function testParsingToleratesCaseSeparatorsAndConfusableCharacters(): void
    {
        $vault = Vault::create();
        $formatted = RecoveryKey::forVault($vault)->formatted();

        $sloppy = strtolower(str_replace(' ', '', $formatted));
        $withDashes = strtr($formatted, [' ' => '-']);
        $confused = strtr($formatted, ['0' => 'O', '1' => 'l']);

        foreach ([$sloppy, $withDashes, $confused, " $formatted\n"] as $input) {
            self::assertSame(
                $vault->secretKey(),
                RecoveryKey::parse($input)->openVault($vault->publicKey())->secretKey(),
                sprintf('Input "%s" should be accepted.', $input),
            );
        }
    }

    public function testASingleWrongCharacterIsCaughtByTheChecksum(): void
    {
        $typed = self::unformatted(RecoveryKey::forVault(Vault::create()));
        $typed[30] = $typed[30] === 'A' ? 'B' : 'A';

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('mistyped');

        RecoveryKey::parse($typed);
    }

    /**
     * Two swapped characters are the other typical transcription error.
     */
    public function testSwappedCharactersAreCaughtByTheChecksum(): void
    {
        $typed = self::unformatted(RecoveryKey::forVault(Vault::create()));

        if ($typed[30] === $typed[31]) {
            self::markTestSkipped('The two characters happen to be equal, swapping them changes nothing.');
        }

        $swapped = $typed[30];
        $typed[30] = $typed[31];
        $typed[31] = $swapped;

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('mistyped');

        RecoveryKey::parse($typed);
    }

    public function testAMissingGroupIsRejected(): void
    {
        $groups = RecoveryKey::forVault(Vault::create())->groups();
        array_pop($groups);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('56 characters');

        RecoveryKey::parse(implode(' ', $groups));
    }

    public function testACharacterOutsideTheAlphabetIsRejected(): void
    {
        $formatted = RecoveryKey::forVault(Vault::create())->formatted();

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('not a recovery key character');

        RecoveryKey::parse(substr($formatted, 0, -1) . 'U');
    }

    public function testUnknownFormatVersionIsRejected(): void
    {
        $vault = Vault::create();
        $key = RecoveryKey::forVault($vault);

        // Rebuild the payload with a different version byte, checksum and all,
        // so that only the version can be what is complained about.
        $reflection = new \ReflectionClass(RecoveryKey::class);
        $encode = $reflection->getMethod('encode');
        $checksum = $reflection->getMethod('checksum');
        $body = chr(RecoveryKey::VERSION + 1) . $vault->secretKey();
        $foreign = $encode->invoke(null, $body . $checksum->invoke(null, $body));

        self::assertNotSame($foreign, str_replace(' ', '', $key->formatted()));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('unknown format version');

        RecoveryKey::parse($foreign);
    }

    /**
     * A key from another installation decodes cleanly - only the vault public
     * key can tell that it is the wrong one.
     */
    public function testKeyOfAnotherInstallationIsRejected(): void
    {
        $foreign = RecoveryKey::forVault(Vault::create());

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('another vault');

        $foreign->openVault(Vault::create()->publicKey());
    }

    /**
     * The installer asks for the last group before it finishes, as proof that
     * the key was written down.
     */
    public function testLastGroupIsTheLastGroupOfTheFormattedKey(): void
    {
        $key = RecoveryKey::forVault(Vault::create());

        $groups = explode(' ', $key->formatted());

        self::assertSame(end($groups), $key->lastGroup());
        self::assertSame(RecoveryKey::GROUP_LENGTH, strlen($key->lastGroup()));
    }

    public function testALockedVaultHasNoRecoveryKey(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('locked');

        RecoveryKey::forVault(Vault::locked(Vault::create()->publicKey()));
    }

    public function testTheKeyOfAVaultIsStable(): void
    {
        $vault = Vault::create();

        self::assertSame(
            RecoveryKey::forVault($vault)->formatted(),
            RecoveryKey::forVault($vault)->formatted(),
        );
    }

    public function testDebugOutputHidesTheSecretKey(): void
    {
        $vault = Vault::create();

        $dump = print_r(RecoveryKey::forVault($vault)->__debugInfo(), true);

        self::assertStringNotContainsString(bin2hex($vault->secretKey()), $dump);
    }

    /**
     * The 56 characters without the group separators - the form a test can
     * change a single character of.
     */
    private static function unformatted(RecoveryKey $key): string
    {
        return implode('', $key->groups());
    }
}
