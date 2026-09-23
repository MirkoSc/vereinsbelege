<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Service\Submission\FormToken;
use PHPUnit\Framework\TestCase;

/**
 * The public submission's stateless credential (docs/spec/01-sicherheit.md
 * section 5, issue #24/M4-2).
 */
final class FormTokenTest extends TestCase
{
    public function testARoundtrippedTokenReturnsItsIssueTime(): void
    {
        $token = new FormToken(str_repeat('k', 32));
        $now = new \DateTimeImmutable('2026-03-01 10:00:00');

        $ausgestellt = $token->ausstellen($now);
        $geprueft = $token->pruefen($ausgestellt, $now);

        self::assertNotNull($geprueft);
        self::assertSame($now->getTimestamp(), $geprueft->issuedAt->getTimestamp());
    }

    public function testTwoTokensFromTheSameMomentHaveDifferentHashes(): void
    {
        $token = new FormToken(str_repeat('k', 32));
        $now = new \DateTimeImmutable();

        $a = $token->pruefen($token->ausstellen($now), $now);
        $b = $token->pruefen($token->ausstellen($now), $now);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a->hash(), $b->hash());
    }

    public function testAManipulatedTokenIsRejected(): void
    {
        $token = new FormToken(str_repeat('k', 32));
        $ausgestellt = $token->ausstellen();

        $verfaelscht = substr($ausgestellt, 0, -1) . ($ausgestellt[-1] === 'A' ? 'B' : 'A');

        self::assertNull($token->pruefen($verfaelscht));
    }

    public function testATokenFromAnotherKeyIsRejected(): void
    {
        $eins = new FormToken(str_repeat('k', 32));
        $zwei = new FormToken(str_repeat('x', 32));

        self::assertNull($zwei->pruefen($eins->ausstellen()));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = new FormToken(str_repeat('k', 32));
        $ausgestellt = new \DateTimeImmutable('2026-01-01 00:00:00');
        $wert = $token->ausstellen($ausgestellt);

        $kurzVorAblauf = $ausgestellt->modify('+' . FormToken::TTL_SECONDS . ' seconds');
        self::assertNotNull($token->pruefen($wert, $kurzVorAblauf));

        $nachAblauf = $ausgestellt->modify('+' . (FormToken::TTL_SECONDS + 1) . ' seconds');
        self::assertNull($token->pruefen($wert, $nachAblauf));
    }

    public function testATokenIssuedNoticeablyInTheFutureIsRejected(): void
    {
        $token = new FormToken(str_repeat('k', 32));
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $wert = $token->ausstellen($now->modify('+5 minutes'));

        self::assertNull($token->pruefen($wert, $now));
    }

    public function testGarbageIsRejectedWithoutThrowing(): void
    {
        $token = new FormToken(str_repeat('k', 32));

        self::assertNull($token->pruefen(''));
        self::assertNull($token->pruefen('not-a-token'));
        self::assertNull($token->pruefen(base64_encode('zu kurz')));
    }

    public function testTheServerKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FormToken('zu kurz');
    }
}
