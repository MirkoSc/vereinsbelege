<?php

declare(strict_types=1);

namespace App\Tests\Worker;

use App\Service\Worker\WorkerProtokoll;
use App\Worker\Identitaet;
use App\Worker\Log;
use PHPUnit\Framework\TestCase;

/**
 * No business data in the worker's log (CLAUDE.md section 4), and the one
 * file it keeps - its identity - is private.
 */
final class LogUndIdentitaetTest extends TestCase
{
    public function testTheLogKeepsIdentifiersAndNumbersOnly(): void
    {
        $zeilen = [];
        $log = new Log(static function (string $zeile) use (&$zeilen): void {
            $zeilen[] = $zeile;
        });

        $log->ereignis('job_fehler', [
            'job' => 12,
            'fehler' => 'PdfPasswortGeschuetzt',
            'endgueltig' => true,
            'meldung' => 'Sportartikel Müller GmbH, 119,00 EUR',
            'iban' => 'DE02 1203 0000 0000 2020 51',
        ]);

        $daten = json_decode($zeilen[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('job_fehler', $daten['ereignis']);
        self::assertSame(12, $daten['job']);
        self::assertSame('PdfPasswortGeschuetzt', $daten['fehler']);
        self::assertNull($daten['meldung']);
        self::assertNull($daten['iban']);
        self::assertStringNotContainsString('Müller', $zeilen[0]);
    }

    public function testTheIdentityIsStoredWithMode0600AndReadBack(): void
    {
        $verzeichnis = sys_get_temp_dir() . '/vb_ident_' . bin2hex(random_bytes(6));
        $datei = $verzeichnis . '/worker.json';
        $paar = sodium_crypto_box_keypair();
        $identitaet = new Identitaet(4, str_repeat('k', WorkerProtokoll::SCHLUESSEL_BYTES), $paar, 'https://belege.test', 'Pi');

        try {
            $identitaet->speichern($datei);

            self::assertSame(0600, fileperms($datei) & 0777);
            $geladen = Identitaet::laden($datei);
            self::assertNotNull($geladen);
            self::assertSame(4, $geladen->workerId);
            self::assertSame($paar, $geladen->schluesselpaar);
            self::assertSame('https://belege.test', $geladen->basisUrl);
            self::assertSame(WorkerProtokoll::fingerabdruckAnzeige(WorkerProtokoll::fingerabdruck(sodium_crypto_box_publickey($paar))), $geladen->fingerabdruck());

            chmod($datei, 0644);
            Identitaet::laden($datei);
            clearstatcache();
            self::assertSame(0600, fileperms($datei) & 0777, 'a loosened mode is tightened again');
        } finally {
            @unlink($datei);
            @rmdir($verzeichnis);
        }
    }

    public function testNoIdentityBeforePairing(): void
    {
        self::assertNull(Identitaet::laden(sys_get_temp_dir() . '/gibt-es-nicht-' . bin2hex(random_bytes(4)) . '.json'));
    }
}
