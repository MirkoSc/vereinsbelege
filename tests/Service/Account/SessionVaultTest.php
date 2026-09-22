<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\SessionVault;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use PHPUnit\Framework\TestCase;

/**
 * The session unlock of docs/spec/01-sicherheit.md section 2, and with it
 * the required test "Session ohne Cookie `__Host-vk` kann nicht
 * entschlüsseln".
 *
 * $_SESSION is manipulated directly instead of starting a real session -
 * the same way tests/Http/SessionFlashTest.php does it. What is under test
 * is the split between the two halves, not PHP's session handler.
 */
final class SessionVaultTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheCookieKeyUnlocksTheStoredVault(): void
    {
        $vault = Vault::create();
        $session = new SessionVault();

        $cookie = $session->store($vault);
        $wieder = $session->unlock($cookie);

        self::assertNotNull($wieder);
        self::assertTrue($wieder->isUnlocked());
        self::assertSame($vault->publicKey(), $wieder->publicKey());
        self::assertSame($vault->version, $wieder->version);
    }

    /** The point of the unlock: the reopened vault really decrypts. */
    public function testTheUnlockedVaultOpensADataKeySealedBeforeTheLogin(): void
    {
        $vault = Vault::create();
        $key = DataKey::generate();
        // Sealing needs no secret - this is what the public submission does
        // long before anybody logs in.
        $versiegelt = Vault::locked($vault->publicKey(), $vault->version)->sealDataKey($key);

        $session = new SessionVault();
        $wieder = $session->unlock($session->store($vault));

        self::assertNotNull($wieder);
        self::assertTrue($key->equals($wieder->openDataKey($versiegelt)));
    }

    /**
     * The acceptance criterion of issue #16: without the `__Host-vk` cookie
     * nothing can be decrypted, even though the session is otherwise intact.
     */
    public function testWithoutTheCookieNothingCanBeDecrypted(): void
    {
        $session = new SessionVault();
        $session->store(Vault::create());

        self::assertTrue($session->isStored(), 'Die Sitzung hält den Tresor weiterhin.');
        self::assertNull($session->unlock(null), 'Ohne Cookie darf nichts entsperrt werden.');
        self::assertNull($session->unlock(''), 'Ein leeres Cookie ist kein Schlüssel.');
    }

    public function testAWrongCookieKeyDoesNotUnlock(): void
    {
        $session = new SessionVault();
        $session->store(Vault::create());

        $fremd = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        self::assertNull($session->unlock($fremd));
    }

    /** A cookie a client made up is a wrong key, not a crash. */
    public function testAMalformedCookieValueDoesNotUnlock(): void
    {
        $session = new SessionVault();
        $session->store(Vault::create());

        self::assertNull($session->unlock('kein base64 !!!'));
        self::assertNull($session->unlock(sodium_bin2base64('zu kurz', SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
    }

    /**
     * The whole security argument of the design: whoever reads the session
     * file finds the vault key only as ciphertext.
     */
    public function testTheSessionNeverHoldsThePrivateKeyInTheClear(): void
    {
        $vault = Vault::create();
        new SessionVault()->store($vault);

        $gespeichert = serialize($_SESSION);

        self::assertStringNotContainsString($vault->secretKey(), $gespeichert);
        self::assertStringContainsString($vault->publicKey(), $gespeichert, 'Der öffentliche Teil darf dort stehen.');
    }

    public function testAnUnknownFormatVersionIsAnErrorRatherThanNonsense(): void
    {
        $session = new SessionVault();
        $session->store(Vault::create());

        /** @var array{cipher: string} $stand */
        $stand = $_SESSION['vault'];
        $_SESSION['vault']['cipher'] = chr(SessionVault::VERSION + 1) . substr($stand['cipher'], 1);

        $this->expectException(CryptoException::class);
        $session->unlock('egal');
    }

    public function testATruncatedCipherIsAnError(): void
    {
        $session = new SessionVault();
        $session->store(Vault::create());
        $_SESSION['vault']['cipher'] = chr(SessionVault::VERSION) . 'zu kurz';

        $this->expectException(CryptoException::class);
        $session->unlock('egal');
    }

    public function testClearForgetsTheVault(): void
    {
        $session = new SessionVault();
        $cookie = $session->store(Vault::create());

        $session->clear();

        self::assertFalse($session->isStored());
        self::assertNull($session->unlock($cookie), 'Auch mit dem richtigen Cookie ist nichts mehr da.');
    }

    /** Nothing stored means nothing to unlock - and no exception either. */
    public function testAFreshSessionHasNoVault(): void
    {
        $session = new SessionVault();

        self::assertFalse($session->isStored());
        self::assertNull($session->unlock('egal'));
    }

    /** Two logins must not end up with the same session key. */
    public function testEveryStoreUsesAFreshSessionKey(): void
    {
        $vault = Vault::create();
        $session = new SessionVault();

        $ersteres = $session->store($vault);
        $zweiteres = $session->store($vault);

        self::assertNotSame($ersteres, $zweiteres);
        self::assertNull($session->unlock($ersteres), 'Das alte Cookie öffnet die neue Ablage nicht.');
    }
}
