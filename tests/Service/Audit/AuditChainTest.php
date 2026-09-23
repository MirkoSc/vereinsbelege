<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Domain\AuditEntry;
use App\Service\Audit\AuditChain;
use App\Service\Audit\AuditChainBreak;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hash chain of the audit log, without a database (docs/spec/
 * 01-sicherheit.md section 6 and "Pflicht-Tests": "Audit-Hash-Kette inkl.
 * Manipulationserkennung", issue #21/M3-8).
 */
final class AuditChainTest extends TestCase
{
    public function testGenesisIsThirtyTwoZeroBytes(): void
    {
        self::assertSame(str_repeat("\0", 32), AuditChain::GENESIS);
    }

    public function testAnUntouchedChainHolds(): void
    {
        $kette = self::kette(5);

        $ergebnis = AuditChain::verify($kette);

        self::assertTrue($ergebnis->intakt());
        self::assertSame(5, $ergebnis->geprueft);
        self::assertSame(5, $ergebnis->letzteId);
        self::assertSame($kette[4]->hash, $ergebnis->letzterHash);
    }

    public function testAnEmptyChainHoldsAndEndsAtGenesis(): void
    {
        $ergebnis = AuditChain::verify([]);

        self::assertTrue($ergebnis->intakt());
        self::assertSame(0, $ergebnis->geprueft);
        self::assertSame(AuditChain::GENESIS, $ergebnis->letzterHash);
    }

    /**
     * Every stored field is covered by the hash: changing any one of them
     * on row 3 breaks the chain exactly there.
     *
     * @return iterable<string, array{0: \Closure(AuditEntry): AuditEntry}>
     */
    public static function manipulationen(): iterable
    {
        $mit = static fn(AuditEntry $e, array $felder): AuditEntry => new AuditEntry(...[...get_object_vars($e), ...$felder]);

        yield 'Aktion' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['action' => 'logout'])];
        yield 'Zeitpunkt' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['ts' => '2026-01-01 00:00:00'])];
        yield 'Benutzer' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['userId' => 99])];
        yield 'Benutzer entfernt' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['userId' => null])];
        yield 'Objekt' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['entityId' => 99])];
        yield 'Entität' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['entity' => 'role'])];
        yield 'IP-Hash' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['ipHash' => str_repeat("\1", 32)])];
        yield 'Details-Chiffrat' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['detailsEnc' => $e->detailsEnc . 'x'])];
        yield 'Datenschlüssel' => [static fn(AuditEntry $e): AuditEntry => $mit($e, ['dekSealed' => null])];
    }

    #[DataProvider('manipulationen')]
    public function testAChangedFieldBreaksTheChainAtThatRow(\Closure $manipulation): void
    {
        $kette = self::kette(5);
        $kette[2] = $manipulation($kette[2]);

        $ergebnis = AuditChain::verify($kette);

        self::assertFalse($ergebnis->intakt());
        self::assertSame(3, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Inhalt, $ergebnis->bruch);
        self::assertSame(2, $ergebnis->geprueft, 'the rows before the break still hold');
    }

    /**
     * Recomputing the changed row's own hash does not help: the next row
     * still points at the old one.
     */
    public function testARehashedRowBreaksTheLinkToItsSuccessor(): void
    {
        $kette = self::kette(5);
        $gefaelscht = new AuditEntry(...[...get_object_vars($kette[2]), 'action' => 'logout']);
        $kette[2] = $gefaelscht->mitHash(AuditChain::hash($gefaelscht->prevHash, $gefaelscht));

        $ergebnis = AuditChain::verify($kette);

        self::assertSame(4, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Verkettung, $ergebnis->bruch);
    }

    public function testADeletedRowIsAGap(): void
    {
        $kette = self::kette(5);
        unset($kette[2]);

        $ergebnis = AuditChain::verify(array_values($kette));

        self::assertSame(4, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Luecke, $ergebnis->bruch);
    }

    /**
     * Deleting a row AND renumbering the rest to hide the gap: the ids
     * then follow each other, but the renumbered row no longer gives its
     * hash (the id is part of the hashed row).
     */
    public function testADeletedRowWithRenumberedSuccessorsIsStillFound(): void
    {
        $kette = self::kette(5);
        $neu = [$kette[0], $kette[1]];
        foreach ([3, 4] as $i) {
            $neu[] = new AuditEntry(...[...get_object_vars($kette[$i]), 'id' => $kette[$i]->id - 1]);
        }

        $ergebnis = AuditChain::verify($neu);

        self::assertSame(3, $ergebnis->bruchId);
        self::assertFalse($ergebnis->intakt());
    }

    public function testSwappedRowsAreFound(): void
    {
        $kette = self::kette(5);
        [$kette[1], $kette[2]] = [$kette[2], $kette[1]];

        $ergebnis = AuditChain::verify($kette);

        self::assertSame(3, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Luecke, $ergebnis->bruch);
    }

    public function testARowInsertedWithTheRightIdButNoLinkIsFound(): void
    {
        $kette = self::kette(3);
        $fremd = new AuditEntry(4, '2026-09-23 12:00:00', 1, 'login.erfolg', 'user', 1, null, null, null, str_repeat("\7", 32), '');
        $kette[] = $fremd->mitHash(AuditChain::hash($fremd->prevHash, $fremd));

        $ergebnis = AuditChain::verify($kette);

        self::assertSame(4, $ergebnis->bruchId);
        self::assertSame(AuditChainBreak::Verkettung, $ergebnis->bruch);
    }

    public function testTheCheckContinuesFromAnEarlierStep(): void
    {
        $kette = self::kette(6);

        $ersterSchritt = AuditChain::verify(array_slice($kette, 0, 3));
        $zweiterSchritt = AuditChain::verify(array_slice($kette, 3), $ersterSchritt->letzteId, $ersterSchritt->letzterHash);

        self::assertTrue($zweiterSchritt->intakt());
        self::assertSame(6, $zweiterSchritt->letzteId);
        self::assertSame($kette[5]->hash, $zweiterSchritt->letzterHash);
    }

    /**
     * The canonical form is a storage format: every existing chain depends
     * on it staying byte for byte the same.
     */
    public function testTheCanonicalFormIsFixed(): void
    {
        $eintrag = new AuditEntry(
            id: 7,
            ts: '2026-09-23 10:15:00',
            userId: 3,
            action: 'rolle.geaendert',
            entity: 'role',
            entityId: 12,
            ipHash: "\xAB" . str_repeat("\0", 31),
            detailsEnc: "\x01\x02/+",
            dekSealed: 'ä',
            prevHash: AuditChain::GENESIS,
            hash: '',
        );

        self::assertSame(
            '{"id":7,"ts":"2026-09-23 10:15:00","user_id":3,"action":"rolle.geaendert","entity":"role","entity_id":12,'
            . '"ip_hash":"ab' . str_repeat('00', 31) . '","details_enc":"AQIvKw==","dek_sealed":"w6Q="}',
            AuditChain::canonicalJson($eintrag),
        );
        self::assertSame(
            hash('sha256', AuditChain::GENESIS . AuditChain::canonicalJson($eintrag), true),
            AuditChain::hash(AuditChain::GENESIS, $eintrag),
        );
    }

    /**
     * @return list<AuditEntry>
     */
    private static function kette(int $anzahl): array
    {
        $kette = [];
        $prev = AuditChain::GENESIS;
        for ($id = 1; $id <= $anzahl; ++$id) {
            $eintrag = new AuditEntry(
                id: $id,
                ts: sprintf('2026-09-23 10:%02d:00', $id),
                userId: $id % 2 === 0 ? null : 1,
                action: 'login.erfolg',
                entity: 'user',
                entityId: 1,
                ipHash: str_repeat(chr($id), 32),
                detailsEnc: 'chiffrat-' . $id,
                dekSealed: 'dek-' . $id,
                prevHash: $prev,
                hash: '',
            );
            $eintrag = $eintrag->mitHash(AuditChain::hash($prev, $eintrag));
            $kette[] = $eintrag;
            $prev = $eintrag->hash;
        }

        return $kette;
    }
}
