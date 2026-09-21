<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\CronController;
use App\Config\Config;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Service\Cron\CronRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The token check of the cron endpoint. It has to hold WITHOUT a database:
 * a wrong token must not even build the runner (which opens the connection).
 */
final class CronTokenTest extends TestCase
{
    private int $built = 0;

    private function controller(): CronController
    {
        $config = Config::fromArray([
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'cron_token' => 'richtiges-token',
        ]);

        return new CronController($config, function (): CronRunner {
            $this->built++;
            throw new \LogicException('The runner must not be built for a rejected request.');
        });
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectedQueries(): iterable
    {
        yield 'no token' => [[]];
        yield 'empty token' => [['token' => '']];
        yield 'wrong token' => [['token' => 'falsch']];
        yield 'prefix of the token' => [['token' => 'richtiges']];
        yield 'token as array' => [['token' => ['richtiges-token']]];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('rejectedQueries')]
    public function testRejectsWithoutTouchingTheRunner(array $query): void
    {
        $response = $this->controller()->run(new Request(HttpMethod::Get, '/cron', query: $query));

        self::assertSame(403, $response->status);
        self::assertSame(0, $this->built);
        self::assertStringNotContainsString('richtiges-token', $response->body);
    }

    public function testRightTokenBuildsTheRunner(): void
    {
        $this->expectException(\LogicException::class);

        try {
            $this->controller()->run(new Request(HttpMethod::Get, '/cron', query: ['token' => 'richtiges-token']));
        } finally {
            self::assertSame(1, $this->built);
        }
    }
}
