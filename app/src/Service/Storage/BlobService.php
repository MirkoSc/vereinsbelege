<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\BlobStorage;
use App\Repository\BlobRepository;
use App\Repository\SettingRepository;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Encrypted file storage (issue M2-3, docs/spec/02-datenmodell.md "Dateien",
 * CLAUDE.md section 5): original photos, generated PDFs, bank statement files.
 *
 * The asymmetry of the vault is the point:
 *
 *   - **Writing works without a secret.** The data key is sealed to the vault
 *     public key, so the public submission can store a receipt photo it can
 *     never read back.
 *   - **Reading needs an unlocked vault**, that is: a logged-in session. The
 *     cron cannot decrypt a single blob, by construction.
 *
 * Both directions work in pieces. Nothing here builds a plaintext temporary
 * file, and nothing holds a whole file in memory - a 20 MB scan has to fit
 * through a shared host's memory limit and its 30 seconds.
 */
final readonly class BlobService
{
    /** Admin setting for the storage backend; switching it is M2-5. */
    public const string SETTING_BACKEND = 'speicher_backend';

    private const string TABLE = 'file_blob';

    private const string META_COLUMN = 'meta_enc';

    public function __construct(
        private BlobRepository $repository,
        private DbBlobBackend $dbBackend,
        private FsBlobBackend $fsBackend,
        private BlobStorage $defaultStorage = BlobStorage::Fs,
    ) {
    }

    /** The backend an installation writes new blobs to (decision E-06). */
    public static function configuredStorage(SettingRepository $settings): BlobStorage
    {
        return BlobStorage::fromSetting($settings->get(self::SETTING_BACKEND, BlobStorage::default()->value));
    }

    /**
     * @param iterable<string> $plaintextChunks pieces of any size
     */
    public function store(
        iterable $plaintextChunks,
        BlobMeta $meta,
        Vault $vault,
        ?BlobStorage $storage = null,
    ): Blob {
        $storage ??= $this->defaultStorage;
        $key = DataKey::generate();
        $writer = BlobCipher::writer($key);

        $now = new \DateTimeImmutable();
        $id = $this->repository->insertDraft(
            $storage,
            $storage === BlobStorage::Fs ? FsBlobBackend::newName() : null,
            $vault->sealDataKey($key),
            $writer->header(),
            $now,
        );
        // Read back instead of assembling the row here: the file name is the
        // backend's key, and the stored value is the one that counts.
        $draft = $this->repository->find($id) ?? throw new BlobException('Blob row disappeared.');

        $backend = $this->backendFor($draft->storage);
        $sink = $backend->open($draft);
        $hash = hash_init('sha256');
        $size = 0;

        try {
            foreach ($plaintextChunks as $piece) {
                $size += strlen($piece);
                $this->emit($sink, $hash, $writer->write($piece));
            }
            $this->emit($sink, $hash, $writer->finish());
            $sink->commit();

            $cipherSha256 = hash_final($hash, true);
            $metaEnc = FieldCipher::encrypt(
                $key,
                $meta->toJson(),
                new FieldContext(self::TABLE, $draft->id, self::META_COLUMN),
            );
            $this->repository->complete($draft->id, $size, $cipherSha256, $metaEnc);
        } catch (\Throwable $e) {
            // Whatever went wrong, nothing half-written stays: the temporary
            // file, the committed content and the draft row all go.
            $sink->discard();
            $backend->delete($draft);
            $this->repository->delete($draft->id);

            throw $e;
        }

        return new Blob(
            id: $draft->id,
            storage: $draft->storage,
            fsName: $draft->fsName,
            size: $size,
            cipherSha256: $cipherSha256,
            dekSealed: $draft->dekSealed,
            header: $draft->header,
            metaEnc: $metaEnc,
            createdAt: $draft->createdAt,
        );
    }

    /**
     * @param resource $stream an open, readable stream - stays the caller's to close
     */
    public function storeStream(
        $stream,
        BlobMeta $meta,
        Vault $vault,
        ?BlobStorage $storage = null,
    ): Blob {
        return $this->store(self::fromStream($stream), $meta, $vault, $storage);
    }

    public function storeString(
        string $content,
        BlobMeta $meta,
        Vault $vault,
        ?BlobStorage $storage = null,
    ): Blob {
        return $this->store([$content], $meta, $vault, $storage);
    }

    public function find(int $id): ?Blob
    {
        return $this->repository->find($id);
    }

    /**
     * Plaintext in pieces, for a download, a preview or a processing step.
     * Needs an unlocked vault; a locked one throws before a single byte is
     * read.
     *
     * @return \Generator<string>
     */
    public function openRead(Blob $blob, Vault $vault): \Generator
    {
        if (!$blob->isComplete()) {
            throw new BlobException(sprintf('Blob %d was never finished.', $blob->id));
        }

        $reader = BlobCipher::reader($vault->openDataKey($blob->dekSealed), $blob->header);

        foreach ($this->backendFor($blob->storage)->read($blob) as $piece) {
            $plaintext = $reader->read($piece);
            if ($plaintext !== '') {
                yield $plaintext;
            }
        }

        $last = $reader->finish();
        if ($last !== '') {
            yield $last;
        }
    }

    /**
     * The raw ciphertext, without the vault. This is what the worker gets
     * (docs/spec/07-worker.md section 5) and what a backup copies (M2-6).
     *
     * @return iterable<string>
     */
    public function readCipher(Blob $blob): iterable
    {
        return $this->backendFor($blob->storage)->read($blob);
    }

    public function meta(Blob $blob, Vault $vault): ?BlobMeta
    {
        if ($blob->metaEnc === null) {
            return null;
        }

        return BlobMeta::fromJson(FieldCipher::decrypt(
            $vault->openDataKey($blob->dekSealed),
            $blob->metaEnc,
            new FieldContext(self::TABLE, $blob->id, self::META_COLUMN),
        ));
    }

    /**
     * Compares the stored ciphertext against its checksum - the integrity
     * check after switching backends (M2-5) and for the admin page. Needs no
     * vault: it verifies bytes, it does not read a receipt.
     */
    public function verify(Blob $blob): bool
    {
        if (!$blob->isComplete()) {
            return false;
        }

        $hash = hash_init('sha256');
        try {
            foreach ($this->readCipher($blob) as $piece) {
                hash_update($hash, $piece);
            }
        } catch (BlobException) {
            // Gone from the shelf entirely - for the check that is the same
            // answer as a wrong checksum: this blob is not intact.
            return false;
        }

        return hash_equals((string) $blob->cipherSha256, hash_final($hash, true));
    }

    public function delete(Blob $blob): void
    {
        $this->backendFor($blob->storage)->delete($blob);
        $this->repository->delete($blob->id);
    }

    public function exists(Blob $blob): bool
    {
        return $this->backendFor($blob->storage)->exists($blob);
    }

    public function backendFor(BlobStorage $storage): BlobBackend
    {
        return match ($storage) {
            BlobStorage::Db => $this->dbBackend,
            BlobStorage::Fs => $this->fsBackend,
        };
    }

    /**
     * @param resource $stream
     *
     * @return \Generator<string>
     */
    private static function fromStream($stream): \Generator
    {
        while (!feof($stream)) {
            $piece = fread($stream, BlobCipher::CHUNK_BYTES);
            if ($piece === false) {
                throw new BlobException('Upload stream could not be read.');
            }
            if ($piece !== '') {
                yield $piece;
            }
        }
    }

    private function emit(BlobSink $sink, \HashContext $hash, string $ciphertext): void
    {
        if ($ciphertext === '') {
            return;
        }

        hash_update($hash, $ciphertext);
        $sink->write($ciphertext);
    }
}
