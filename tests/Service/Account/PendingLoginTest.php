<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Service\Account\MfaMethod;
use App\Service\Account\PendingLogin;
use App\Service\Account\VaultAccess;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use PHPUnit\Framework\TestCase;

/**
 * The gap between "password correct" and "second factor confirmed" (issue
 * #17/M3-4, docs/spec/01-sicherheit.md section 3), the same shape as
 * App\Service\Account\SessionVault and tested the same way -
 * $_SESSION manipulated directly (tests/Http/SessionFlashTest.php's
 * technique), because what is under test is the split between the cookie
 * key and the session file, not PHP's session handler.
 */
final class PendingLoginTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testDerCookieSchluesselOeffnetDenGespeichertenZustand(): void
    {
        $vault = Vault::create();
        $pending = new PendingLogin();

        $cookie = $pending->store(42, $vault, VaultAccess::Entsperrt, MfaMethod::Totp, '/admin/mail');
        $wieder = $pending->open($cookie);

        self::assertNotNull($wieder);
        self::assertSame(42, $wieder->userId);
        self::assertSame(VaultAccess::Entsperrt, $wieder->vaultAccess);
        self::assertSame(MfaMethod::Totp, $wieder->mfaMethod);
        self::assertSame('/admin/mail', $wieder->weiter);
        self::assertNotNull($wieder->vault);
        self::assertTrue($wieder->vault->isUnlocked());
    }

    public function testDerEntsperrteTresorOeffnetEinenVorherVersiegeltenDatenschluessel(): void
    {
        $vault = Vault::create();
        $key = DataKey::generate();
        $versiegelt = Vault::locked($vault->publicKey(), $vault->version)->sealDataKey($key);

        $pending = new PendingLogin();
        $wieder = $pending->open($pending->store(1, $vault, VaultAccess::Entsperrt, MfaMethod::EMail, null));

        self::assertNotNull($wieder?->vault);
        self::assertTrue($key->equals($wieder->vault->openDataKey($versiegelt)));
    }

    public function testOhneTresorBleibtDerTresorLeer(): void
    {
        $pending = new PendingLogin();
        $cookie = $pending->store(7, null, VaultAccess::KeineFreigabe, MfaMethod::Totp, null);

        $wieder = $pending->open($cookie);

        self::assertNotNull($wieder);
        self::assertNull($wieder->vault);
        self::assertSame(VaultAccess::KeineFreigabe, $wieder->vaultAccess);
    }

    public function testOhneCookieGibtEsNichtsZuOeffnen(): void
    {
        $pending = new PendingLogin();
        $pending->store(1, null, VaultAccess::KeineFreigabe, MfaMethod::Totp, null);

        self::assertTrue($pending->isPending());
        self::assertNull($pending->open(null));
        self::assertNull($pending->open(''));
    }

    public function testEinFalschesCookieOeffnetNichts(): void
    {
        $pending = new PendingLogin();
        $pending->store(1, Vault::create(), VaultAccess::Entsperrt, MfaMethod::Totp, null);

        $fremd = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        self::assertNull($pending->open($fremd));
    }

    public function testEinAbgelaufenerEintragOeffnetNichtMehr(): void
    {
        $pending = new PendingLogin();
        $jetzt = new \DateTimeImmutable('2026-01-01 12:00:00');
        $cookie = $pending->store(1, null, VaultAccess::KeineFreigabe, MfaMethod::Totp, null, $jetzt);

        $spaeter = $jetzt->modify('+' . PendingLogin::TTL_SECONDS . ' seconds');

        self::assertNull($pending->open($cookie, $spaeter));
    }

    public function testKurzVorAblaufOeffnetEsNochEinmal(): void
    {
        $pending = new PendingLogin();
        $jetzt = new \DateTimeImmutable('2026-01-01 12:00:00');
        $cookie = $pending->store(1, null, VaultAccess::KeineFreigabe, MfaMethod::Totp, null, $jetzt);

        $kurzVorher = $jetzt->modify('+' . (PendingLogin::TTL_SECONDS - 1) . ' seconds');

        self::assertNotNull($pending->open($cookie, $kurzVorher));
    }

    public function testClearVergisstDenZustand(): void
    {
        $pending = new PendingLogin();
        $cookie = $pending->store(1, Vault::create(), VaultAccess::Entsperrt, MfaMethod::Totp, null);

        $pending->clear();

        self::assertFalse($pending->isPending());
        self::assertNull($pending->open($cookie));
    }

    /** The whole security argument: the private key never sits in the session file as plaintext. */
    public function testDieSitzungHaeltDenGeheimenTresorschluesselNieImKlartext(): void
    {
        $vault = Vault::create();
        new PendingLogin()->store(1, $vault, VaultAccess::Entsperrt, MfaMethod::Totp, null);

        $gespeichert = serialize($_SESSION);

        self::assertStringNotContainsString($vault->secretKey(), $gespeichert);
        self::assertStringContainsString($vault->publicKey(), $gespeichert, 'Der öffentliche Teil darf dort stehen.');
    }

    public function testEinUnbekanntesFormatIstEinFehlerStattUnsinn(): void
    {
        $pending = new PendingLogin();
        $cookie = $pending->store(1, Vault::create(), VaultAccess::Entsperrt, MfaMethod::Totp, null);

        /** @var array{vault: array{cipher: string}} $stand */
        $stand = $_SESSION['mfa_pending'];
        $_SESSION['mfa_pending']['vault']['cipher'] = chr(PendingLogin::VERSION + 1) . substr($stand['vault']['cipher'], 1);

        $this->expectException(CryptoException::class);
        $pending->open($cookie);
    }

    public function testFehlendeMfaMethodeIstUngueltig(): void
    {
        $pending = new PendingLogin();
        $cookie = $pending->store(1, null, VaultAccess::KeineFreigabe, MfaMethod::Totp, null);

        unset($_SESSION['mfa_pending']['mfa_method']);

        self::assertNull($pending->open($cookie));
    }

    public function testEineFrischeSitzungHatNichtsAusstehendes(): void
    {
        $pending = new PendingLogin();

        self::assertFalse($pending->isPending());
        self::assertNull($pending->open('egal'));
    }
}
