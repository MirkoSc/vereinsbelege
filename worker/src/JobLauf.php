<?php

declare(strict_types=1);

namespace App\Worker;

use App\Domain\ArtifactKind;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Storage\BlobCipher;
use App\Service\Worker\WorkerKrypto;

/**
 * One claimed job on the worker: its context, its inputs and its results
 * (docs/spec/07-worker.md sections 3 and 5).
 *
 * In: blob() fetches the ciphertext and opens it with the data key the web
 * host sealed to W_pub for exactly this document (worker_grant). The
 * plaintext stays in memory; a tool gets it as a file in the job's tmpfs
 * directory only.
 *
 * Out: every result is encrypted under a FRESH data key, which is sealed
 * to VK_pub (the vault) at once - the worker cannot read its own results
 * afterwards, and never holds the vault's private key. Upload in the
 * three steps of App\Service\Worker\WorkerArtefakte: announce, chunks,
 * commit; an announcement answered `vorhanden` (a repeat) is done.
 *
 * puls() is called between steps and while tools run: it stops the job on
 * a shutdown request and extends the lock in time for a long OCR/AI run.
 */
final class JobLauf
{
    /** Extend the 30-minute lock after this long. */
    public const int VERLAENGERN_NACH_SEKUNDEN = 600;

    private const string TABELLE = 'document_artifact';

    private const string SPALTE = 'data_enc';

    private ?JobKontext $kontext = null;

    private int $verlaengert;

    /**
     * @param \Closure(): int $uhr
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly Identitaet $identitaet,
        private readonly Abbruch $abbruch,
        private readonly \Closure $uhr,
        public readonly int $id,
        public readonly string $typ,
        public readonly string $lock,
        public readonly int $versuch,
    ) {
        $this->verlaengert = ($this->uhr)();
    }

    /**
     * @param array<string, mixed> $job one entry of the claim answer
     * @param \Closure(): int $uhr
     */
    public static function ausClaim(ApiClient $api, Identitaet $identitaet, Abbruch $abbruch, \Closure $uhr, array $job): self
    {
        if (!is_int($job['id'] ?? null) || !is_string($job['typ'] ?? null) || !is_string($job['lock'] ?? null) || !is_int($job['versuch'] ?? null)) {
            throw new ApiFehler(200, 'claim_ungueltig', 'Die Job-Daten sind unvollständig.');
        }

        return new self($api, $identitaet, $abbruch, $uhr, $job['id'], $job['typ'], $job['lock'], $job['versuch']);
    }

    public function kontext(): JobKontext
    {
        return $this->kontext ??= JobKontext::aus($this->api->json('GET', '/jobs/' . $this->id . '/context'));
    }

    /**
     * Stops on a shutdown request; extends the lock when it is due.
     *
     * @throws Abgebrochen|ApiFehler
     */
    public function puls(): void
    {
        $this->abbruch->pruefen();
        if (($this->uhr)() - $this->verlaengert >= self::VERLAENGERN_NACH_SEKUNDEN) {
            $this->api->json('POST', '/jobs/' . $this->id . '/extend', ['lock' => $this->lock]);
            $this->verlaengert = ($this->uhr)();
        }
    }

    /** The puls as closure, for the tools. */
    public function pulsGeber(): \Closure
    {
        return $this->puls(...);
    }

    /**
     * The plaintext of a granted blob.
     *
     * @throws ApiFehler|JobFehler
     */
    public function blob(int $blobId): string
    {
        $this->puls();
        // A blob without grant (an original that was incomplete when the
        // job was routed) never opens: no use asking the web host again.
        if (!in_array($blobId, $this->kontext()->blobs, true)) {
            throw new JobFehler('KeinGrant', true);
        }
        $antwort = $this->api->datei('/blobs/' . $blobId);
        $grant = base64_decode((string) $antwort->kopf('x-worker-dek'), true);
        $header = base64_decode((string) $antwort->kopf('x-blob-header'), true);
        $groesse = $antwort->kopf('x-blob-groesse');
        if (!is_string($grant) || !is_string($header) || $groesse === null || preg_match('/^\d+$/', $groesse) !== 1) {
            throw new JobFehler('BlobAntwortUngueltig', false);
        }
        try {
            $dek = WorkerKrypto::grantOeffnen($grant, $this->identitaet->schluesselpaar);
            $leser = BlobCipher::reader($dek, $header);
            $klartext = $leser->read($antwort->body) . $leser->finish();
        } catch (CryptoException | \SodiumException) {
            throw new JobFehler('BlobNichtLesbar', false);
        }
        if (strlen($klartext) !== (int) $groesse) {
            sodium_memzero($klartext);
            throw new JobFehler('BlobNichtLesbar', false);
        }

        return $klartext;
    }

    /**
     * Uploads a file result (`page_image`, `pdfa`) as a blob for the vault.
     */
    public function blobHochladen(ArtifactKind $kind, int $seq, string $klartext): void
    {
        $this->mitAktuellemTresor(function () use ($kind, $seq, $klartext): void {
            $kontext = $this->kontext();
            $dek = DataKey::generate();
            $schreiber = BlobCipher::writer($dek);
            $chiffrat = $schreiber->write($klartext) . $schreiber->finish();
            $antwort = $this->anmelden([
                'kind' => $kind->value,
                'seq' => $seq,
                'dek_sealed' => base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $kontext->tresorPublicKey, $kontext->tresorVersion)),
                'header' => base64_encode($schreiber->header()),
                'groesse' => strlen($chiffrat),
                'sha256' => hash('sha256', $chiffrat),
            ]);
            if ($antwort !== null) {
                $this->hochladen($antwort, $chiffrat);
            }
        });
    }

    /**
     * Uploads a JSON result (`text`, `extraction`) as `data_enc` for the
     * vault. Its AAD names the row id the announcement hands out, so the
     * ciphertext is made after it - its length is known before.
     */
    public function datenHochladen(ArtifactKind $kind, int $seq, string $json): void
    {
        $this->mitAktuellemTresor(function () use ($kind, $seq, $json): void {
            $kontext = $this->kontext();
            $dek = DataKey::generate();
            $groesse = strlen($json) + 1 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
            $antwort = $this->anmelden([
                'kind' => $kind->value,
                'seq' => $seq,
                'dek_sealed' => base64_encode(WorkerKrypto::fuerTresorVersiegeln($dek, $kontext->tresorPublicKey, $kontext->tresorVersion)),
                'groesse' => $groesse,
            ]);
            if ($antwort === null) {
                return;
            }
            $id = is_int($antwort['artefakt_id'] ?? null) ? $antwort['artefakt_id'] : 0;
            $feld = new FieldContext(self::TABELLE, max(1, $id), self::SPALTE);
            // The web host names the AAD as well; both must agree, else the
            // vault could not open what we seal.
            if ($id < 1 || ($antwort['aad'] ?? null) !== $feld->aad()) {
                throw new JobFehler('ArtefaktAntwortUngueltig', false);
            }
            $chiffrat = FieldCipher::encrypt($dek, $json, $feld);
            if (strlen($chiffrat) !== $groesse) {
                throw new \LogicException('Field ciphertext length differs from the announced one.');
            }
            $this->hochladen($antwort, $chiffrat);
        });
    }

    /**
     * A key rotation on the web host (issue #90) can make the VK_pub of our
     * context stale between announcing and committing: fetch the context
     * again and seal anew - once.
     *
     * @param \Closure(): void $arbeit
     */
    private function mitAktuellemTresor(\Closure $arbeit): void
    {
        try {
            $arbeit();
        } catch (ApiFehler $e) {
            if (!in_array($e->fehler, ['dek_veraltet', 'dek_ungueltig'], true)) {
                throw $e;
            }
            $this->kontext = null;
            $arbeit();
        }
    }

    /**
     * @param array<string, mixed> $daten
     *
     * @return ?array<string, mixed> the open upload, or null when the artifact is there already
     */
    private function anmelden(array $daten): ?array
    {
        $this->puls();
        $antwort = $this->api->json('POST', '/jobs/' . $this->id . '/artifacts', ['lock' => $this->lock, 'versuch' => $this->versuch, ...$daten]);

        return ($antwort['status'] ?? null) === 'vorhanden' ? null : $antwort;
    }

    /**
     * @param array<string, mixed> $antwort the announcement's answer
     */
    private function hochladen(array $antwort, string $chiffrat): void
    {
        $id = is_int($antwort['artefakt_id'] ?? null) ? $antwort['artefakt_id'] : 0;
        $stueck = is_int($antwort['chunk_bytes'] ?? null) && $antwort['chunk_bytes'] > 0 ? $antwort['chunk_bytes'] : 0;
        $fehlend = is_array($antwort['fehlend'] ?? null) ? array_values(array_filter($antwort['fehlend'], is_int(...))) : [];
        if ($id < 1 || $stueck === 0) {
            throw new JobFehler('ArtefaktAntwortUngueltig', false);
        }
        foreach ($fehlend as $index) {
            $this->puls();
            $this->api->roh(sprintf('/jobs/%d/artifacts/%d/chunks/%d', $this->id, $id, $index), substr($chiffrat, $index * $stueck, $stueck));
        }
        $this->puls();
        $this->api->json('POST', sprintf('/jobs/%d/artifacts/%d/commit', $this->id, $id), ['lock' => $this->lock]);
    }
}
