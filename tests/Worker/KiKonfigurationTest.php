<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Service\Worker\WorkerKonfiguration;
use App\Worker\KiKonfiguration;
use PHPUnit\Framework\TestCase;

/**
 * The AI keys on the worker (docs/spec/07-worker.md sections 3 and 5): kept
 * in memory only, replaced as a whole - an empty list drops every key held
 * before at once (review of #52).
 */
final class KiKonfigurationTest extends TestCase
{
    private string $paar = '';

    protected function setUp(): void
    {
        $this->paar = sodium_crypto_box_keypair();
    }

    public function testTheSealedProfilesAreOpenedWithTheWorkersKey(): void
    {
        $ki = new KiKonfiguration();
        $ki->uebernehmen($this->config(3, [$this->profil(1, standard: true), $this->profil(2, fallback: true)]), $this->paar);

        self::assertSame(3, $ki->version());
        self::assertSame(2, $ki->anzahl());
        [$standard, $key] = $ki->standard() ?? self::fail('no default profile');
        self::assertSame(1, $standard->id);
        self::assertSame('sk-1', $key);
        self::assertTrue($standard->faehigkeiten->vision);
        self::assertSame(2, ($ki->fallback(1) ?? self::fail('no fallback'))[0]->id);
        self::assertNull($ki->fallback(2), 'never the profile that just failed');
    }

    public function testAnEmptyListDropsTheKeysHeldBefore(): void
    {
        $ki = new KiKonfiguration();
        $ki->uebernehmen($this->config(1, [$this->profil(1, standard: true)]), $this->paar);
        self::assertNotNull($ki->standard());

        $ki->uebernehmen($this->config(2, []), $this->paar);

        self::assertSame(0, $ki->anzahl());
        self::assertNull($ki->standard());
        self::assertSame(2, $ki->version());
    }

    public function testAnEnvelopeThatDoesNotOpenDropsTheKeysToo(): void
    {
        $ki = new KiKonfiguration();
        $ki->uebernehmen($this->config(1, [$this->profil(1, standard: true)]), $this->paar);

        $fremd = sodium_crypto_box_keypair();
        $ki->uebernehmen($this->config(2, [$this->profil(9, standard: true)], sodium_crypto_box_publickey($fremd)), $this->paar);

        self::assertNull($ki->standard());
    }

    public function testDroppedKeysAreAskedForAgain(): void
    {
        $ki = new KiKonfiguration();
        $ki->uebernehmen($this->config(4, [$this->profil(1, standard: true)]), $this->paar);

        $ki->verwerfen();

        self::assertSame(0, $ki->version(), 'the next heartbeat reports 0, the web host resends');
        self::assertNull($ki->standard());
    }

    public function testAnUnknownFormatIsNotTrusted(): void
    {
        $ki = new KiKonfiguration();
        $json = json_encode(['format' => 'worker-config-v9', 'ki_anbieter' => [$this->profil(1, standard: true)]], JSON_THROW_ON_ERROR);

        $ki->uebernehmen(['version' => 1, 'versiegelt' => base64_encode(sodium_crypto_box_seal($json, sodium_crypto_box_publickey($this->paar)))], $this->paar);

        self::assertSame(0, $ki->anzahl());
    }

    public function testAProfileWithoutKeyIsNotUsable(): void
    {
        $ki = new KiKonfiguration();
        $ki->uebernehmen($this->config(1, [$this->profil(1, standard: true, key: '')]), $this->paar);

        self::assertSame(1, $ki->anzahl());
        self::assertNull($ki->standard());
    }

    /**
     * @param list<array<string, mixed>> $profile
     *
     * @return array<string, mixed>
     */
    private function config(int $version, array $profile, ?string $publicKey = null): array
    {
        $json = json_encode(['format' => WorkerKonfiguration::FORMAT, 'ki_anbieter' => $profile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['version' => $version, 'versiegelt' => base64_encode(sodium_crypto_box_seal($json, $publicKey ?? sodium_crypto_box_publickey($this->paar)))];
    }

    /** @return array<string, mixed> */
    private function profil(int $id, bool $standard = false, bool $fallback = false, ?string $key = null): array
    {
        return [
            'id' => $id,
            'name' => 'Profil ' . $id,
            'base_url' => 'https://api.example.test/v1',
            'model' => 'modell-' . $id,
            'faehigkeiten' => ['vision' => true, 'json_schema' => true, 'max_images' => 4, 'max_tokens' => 4096],
            'timeout_s' => 90,
            'standard' => $standard,
            'fallback' => $fallback,
            'api_key' => $key ?? 'sk-' . $id,
        ];
    }
}
