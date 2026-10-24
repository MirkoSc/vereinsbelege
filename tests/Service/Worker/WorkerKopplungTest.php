<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Domain\AuditAction;
use App\Domain\JobExecutor;
use App\Domain\JobStatus;
use App\Domain\WorkerStatus;
use App\Service\Worker\WorkerKopplung;
use App\Service\Worker\WorkerProtokoll;

/**
 * Pairing (docs/spec/07-worker.md section 4 and "Pflicht-Tests": expired
 * code, unconfirmed fingerprint -> inactive; protocol check; issue
 * #51/M7b-1).
 */
final class WorkerKopplungTest extends WorkerTestCase
{
    public function testPairingCreatesAnUnconfirmedWorkerWithAnEncryptedKey(): void
    {
        $now = new \DateTimeImmutable();
        $code = $this->kopplung->codeErzeugen(null, '', $now);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', (string) $code);
        $publicKey = sodium_crypto_box_publickey(sodium_crypto_box_keypair());

        $ergebnis = $this->kopplung->koppeln((string) $code, base64_encode($publicKey), 'Pi <b>4</b>', WorkerProtokoll::VERSION, '1.2.3', '203.0.113.5', $now);

        self::assertTrue($ergebnis->istGekoppelt());
        self::assertSame(WorkerProtokoll::SCHLUESSEL_BYTES, strlen((string) $ergebnis->schluessel));
        $worker = $this->workers->find((int) $ergebnis->workerId);
        self::assertNotNull($worker);
        self::assertSame(WorkerStatus::Gekoppelt, $worker->status);
        self::assertSame($publicKey, $worker->publicKey);
        self::assertSame(WorkerProtokoll::fingerabdruck($publicKey), $worker->fingerprint);
        self::assertSame('Pi b4b', $worker->name);
        // Stored only as server-key ciphertext, never as the key itself.
        self::assertNotSame($ergebnis->schluessel, $worker->secretEnc);
        self::assertSame($ergebnis->schluessel, $this->crypto->decrypt((string) $worker->secretEnc));
        $roh = $this->pdo()->query('SELECT code_hash FROM worker_pairing')->fetchColumn();
        self::assertIsString($roh);
        self::assertStringNotContainsString(str_replace('-', '', (string) $code), $roh);
    }

    public function testAnUnconfirmedWorkerBecomesActiveOnlyOnConfirmation(): void
    {
        [$id] = $this->koppeln(bestaetigen: false);
        self::assertSame(WorkerStatus::Gekoppelt, $this->workers->find($id)?->status);

        self::assertTrue($this->kopplung->bestaetigen($id, null, '', new \DateTimeImmutable()));
        self::assertSame(WorkerStatus::Aktiv, $this->workers->find($id)?->status);
        // Confirming twice changes nothing.
        self::assertFalse($this->kopplung->bestaetigen($id, null, '', new \DateTimeImmutable()));
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $now = new \DateTimeImmutable();
        $code = (string) $this->kopplung->codeErzeugen(null, '', $now);
        $spaeter = $now->modify(sprintf('+%d minutes', WorkerKopplung::CODE_GUELTIG_MINUTEN + 1));

        $ergebnis = $this->kopplung->koppeln($code, self::schluessel(), '', WorkerProtokoll::VERSION, '', '203.0.113.5', $spaeter);

        self::assertFalse($ergebnis->istGekoppelt());
        self::assertSame('code_ungueltig', $ergebnis->fehler);
        self::assertNull($this->workers->aktueller());
    }

    public function testACodeWorksOnlyOnceAndAnyWayItIsTyped(): void
    {
        $now = new \DateTimeImmutable();
        $code = (string) $this->kopplung->codeErzeugen(null, '', $now);

        $getippt = strtolower(str_replace('-', ' ', $code));
        self::assertTrue($this->kopplung->koppeln($getippt, self::schluessel(), '', WorkerProtokoll::VERSION, '', '203.0.113.5', $now)->istGekoppelt());
        self::assertSame('code_ungueltig', $this->kopplung->koppeln($code, self::schluessel(), '', WorkerProtokoll::VERSION, '', '203.0.113.5', $now)->fehler);
    }

    public function testANewCodeReplacesTheOldOne(): void
    {
        $now = new \DateTimeImmutable();
        $alt = (string) $this->kopplung->codeErzeugen(null, '', $now);
        $neu = (string) $this->kopplung->codeErzeugen(null, '', $now);

        self::assertSame('code_ungueltig', $this->kopplung->koppeln($alt, self::schluessel(), '', WorkerProtokoll::VERSION, '', '203.0.113.5', $now)->fehler);
        self::assertTrue($this->kopplung->koppeln($neu, self::schluessel(), '', WorkerProtokoll::VERSION, '', '203.0.113.5', $now)->istGekoppelt());
    }

    public function testNoCodeWhileAWorkerIsPaired(): void
    {
        $this->koppeln(bestaetigen: false);

        self::assertNull($this->kopplung->codeErzeugen(null, '', new \DateTimeImmutable()));
    }

    public function testAnIncompatibleProtocolIsRefusedWithoutUsingUpTheCode(): void
    {
        $now = new \DateTimeImmutable();
        $code = (string) $this->kopplung->codeErzeugen(null, '', $now);

        $ergebnis = $this->kopplung->koppeln($code, self::schluessel(), '', WorkerProtokoll::VERSION + 1, '', '203.0.113.5', $now);
        self::assertSame('protokoll', $ergebnis->fehler);
        self::assertSame(409, $ergebnis->status);

        self::assertTrue($this->kopplung->koppeln($code, self::schluessel(), '', WorkerProtokoll::VERSION, '', '203.0.113.5', $now)->istGekoppelt());
    }

    public function testAnInvalidPublicKeyIsRefused(): void
    {
        $now = new \DateTimeImmutable();
        $code = (string) $this->kopplung->codeErzeugen(null, '', $now);

        foreach (['', 'kein-base64!', base64_encode('zu kurz'), base64_encode(str_repeat("\0", 32))] as $schluessel) {
            self::assertSame('schluessel_ungueltig', $this->kopplung->koppeln($code, $schluessel, '', WorkerProtokoll::VERSION, '', '203.0.113.5', $now)->fehler);
        }
    }

    public function testGuessingIsRateLimitedPerIpAndInTotal(): void
    {
        $now = new \DateTimeImmutable();
        $code = (string) $this->kopplung->codeErzeugen(null, '', $now);
        for ($i = 0; $i < WorkerKopplung::FEHLER_LIMIT_PRO_IP; ++$i) {
            self::assertSame('code_ungueltig', $this->kopplung->koppeln('AAAA-AAAA', self::schluessel(), '', WorkerProtokoll::VERSION, '', '192.0.2.1', $now)->fehler);
        }
        // The right code from that address waits now.
        self::assertSame('zu_viele_fehlversuche', $this->kopplung->koppeln($code, self::schluessel(), '', WorkerProtokoll::VERSION, '', '192.0.2.1', $now)->fehler);

        // Many addresses together hit the total limit.
        for ($i = WorkerKopplung::FEHLER_LIMIT_PRO_IP; $i < WorkerKopplung::FEHLER_LIMIT_GESAMT; ++$i) {
            $this->kopplung->koppeln('AAAA-AAAA', self::schluessel(), '', WorkerProtokoll::VERSION, '', '192.0.2.' . (10 + $i), $now);
        }
        self::assertSame('zu_viele_fehlversuche', $this->kopplung->koppeln($code, self::schluessel(), '', WorkerProtokoll::VERSION, '', '198.51.100.1', $now)->fehler);
    }

    public function testUnpairingLocksTheWorkerOutAndReleasesItsJobs(): void
    {
        [$id] = $this->koppeln();
        $worker = $this->workers->find($id);
        self::assertNotNull($worker);
        $jobId = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 1);
        $fremd = $this->jobs->enqueue('ocr', JobExecutor::Worker, 'document', 2);
        self::assertNotNull($this->jobs->claim(JobExecutor::Worker, $worker->lockPraefix() . 'abc', 1800, typen: ['ocr']));
        self::assertNotNull($this->jobs->claim(JobExecutor::Worker, 'w' . ($id + 10) . '-abc', 1800, typen: ['ocr']));

        self::assertTrue($this->kopplung->entkoppeln($id, null, '', new \DateTimeImmutable()));

        $gesperrt = $this->workers->find($id);
        self::assertSame(WorkerStatus::Gesperrt, $gesperrt?->status);
        self::assertNull($gesperrt->secretEnc);
        self::assertNull($this->workers->aktueller());
        $job = $this->jobs->find($jobId);
        self::assertSame(JobStatus::Offen, $job?->status);
        self::assertNull($job->lockedBy);
        // Another worker's lock is left alone.
        self::assertSame(JobStatus::Laeuft, $this->jobs->find($fremd)?->status);
        // Now a new pairing is possible.
        self::assertNotNull($this->kopplung->codeErzeugen(null, '', new \DateTimeImmutable()));
    }

    public function testEveryStepIsAudited(): void
    {
        [$id] = $this->koppeln();
        $this->kopplung->entkoppeln($id, null, '', new \DateTimeImmutable());

        $aktionen = $this->pdo()->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([
            AuditAction::WorkerKopplungscode->value,
            AuditAction::WorkerGekoppelt->value,
            AuditAction::WorkerBestaetigt->value,
            AuditAction::WorkerEntkoppelt->value,
        ], $aktionen);
    }

    private static function schluessel(): string
    {
        return base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
    }
}
