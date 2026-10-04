<?php

declare(strict_types=1);

namespace App\Tests\Service\Processing;

use App\Service\Crypto\BlindIndex;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Processing\Inhaltsindex;
use App\Service\Processing\ProcessingException;
use PHPUnit\Framework\TestCase;

/**
 * The content index of duplicate detection (issue #40/M6-6,
 * docs/spec/03-erfassung-und-ki.md "Pflicht-Tests": Duplikaterkennung):
 * equal for the same original files in the same order, and nothing anyone
 * without the vault could compute or confirm.
 */
final class InhaltsindexTest extends TestCase
{
    public function testTheSameBytesGiveTheSameIndexHoweverTheyArrive(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertSame(
            Inhaltsindex::datei($index, ['Rechnung 4711, Seite eins']),
            Inhaltsindex::datei($index, ['Rech', 'nung 4711', ', Seite ', '', 'eins']),
        );
        self::assertSame(BlindIndex::BYTES, strlen(Inhaltsindex::datei($index, ['x'])));
    }

    public function testDifferentBytesGiveADifferentIndex(): void
    {
        $index = Vault::create()->blindIndex();

        self::assertNotSame(
            Inhaltsindex::datei($index, ['Rechnung 4711']),
            Inhaltsindex::datei($index, ['Rechnung 4712']),
        );
        // Only the bytes count: the blind index's own normalisation (case,
        // spaces) must not make two different files equal.
        self::assertNotSame(
            Inhaltsindex::datei($index, ['RECHNUNG']),
            Inhaltsindex::datei($index, ['rechnung']),
        );
    }

    public function testTheDocumentIndexFollowsThePageOrder(): void
    {
        $index = Vault::create()->blindIndex();
        $eins = Inhaltsindex::datei($index, ['Seite eins']);
        $zwei = Inhaltsindex::datei($index, ['Seite zwei']);

        self::assertSame(Inhaltsindex::beleg($index, [$eins, $zwei]), Inhaltsindex::beleg($index, [$eins, $zwei]));
        self::assertNotSame(Inhaltsindex::beleg($index, [$eins, $zwei]), Inhaltsindex::beleg($index, [$zwei, $eins]));
        self::assertNotSame(Inhaltsindex::beleg($index, [$eins]), Inhaltsindex::beleg($index, [$eins, $zwei]));
        self::assertSame(BlindIndex::BYTES, strlen(Inhaltsindex::beleg($index, [$eins])));
    }

    /**
     * A plain hash could be confirmed against the database by anyone holding
     * a candidate file - the index is keyed with the vault.
     */
    public function testTheIndexIsNoPlainHash(): void
    {
        $inhalt = 'Rechnung 4711';
        $index = Vault::create()->blindIndex();
        $datei = Inhaltsindex::datei($index, [$inhalt]);

        self::assertNotSame(hash('sha256', $inhalt, true), $datei);
        self::assertNotSame(hash('sha256', $inhalt), bin2hex($datei));
        self::assertNotSame(hash('sha256', $inhalt, true), Inhaltsindex::beleg($index, [$datei]));
    }

    public function testAnotherVaultGivesAnotherIndex(): void
    {
        self::assertNotSame(
            Inhaltsindex::datei(Vault::create()->blindIndex(), ['Rechnung 4711']),
            Inhaltsindex::datei(Vault::create()->blindIndex(), ['Rechnung 4711']),
        );
    }

    public function testFileAndDocumentIndexAreDifferentPurposes(): void
    {
        $index = Vault::create()->blindIndex();
        $datei = Inhaltsindex::datei($index, ['Seite']);

        self::assertNotSame($datei, Inhaltsindex::beleg($index, [$datei]));
    }

    public function testALockedVaultComputesNothing(): void
    {
        $this->expectException(CryptoException::class);

        Vault::locked(Vault::create()->publicKey())->blindIndex();
    }

    public function testAFileIndexOfTheWrongLengthIsRefused(): void
    {
        $this->expectException(ProcessingException::class);

        Inhaltsindex::beleg(Vault::create()->blindIndex(), ['zu kurz']);
    }
}
