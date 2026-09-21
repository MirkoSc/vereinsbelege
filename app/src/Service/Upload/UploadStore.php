<?php

declare(strict_types=1);

namespace App\Service\Upload;

use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Repository\SettingRepository;
use App\Repository\VaultRepository;
use App\Service\Storage\BlobService;

/**
 * Turns a finished upload into an encrypted blob - the only part of the
 * upload component that touches the database.
 *
 * It needs no unlocked vault and no logged-in user: the data key is sealed to
 * VK_pub, which is exactly the asymmetry the storage layer was built for
 * (CLAUDE.md section 4). Without a vault row there is no public key to seal
 * to, and guessing is not an option - that case becomes a 503 and the caller
 * may try again once the installation is finished (M3-2).
 */
final readonly class UploadStore
{
    public function __construct(
        private BlobService $blobs,
        private VaultRepository $vaults,
        private SettingRepository $settings,
    ) {
    }

    /**
     * @param iterable<string> $chunks the plaintext, in order and in pieces
     * @throws UploadException when no vault has been set up yet
     */
    public function store(iterable $chunks, BlobMeta $meta): Blob
    {
        $vault = $this->vaults->current() ?? throw new UploadException(UploadError::VaultMissing);

        return $this->blobs->store($chunks, $meta, $vault, BlobService::configuredStorage($this->settings));
    }
}
