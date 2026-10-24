<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * What PROPFIND found at a path (App\Service\Export\Nextcloud\WebDav).
 */
final readonly class DavEintrag
{
    /**
     * @param int|null $groesse bytes of a file (`getcontentlength`)
     * @param string|null $sha1 lowercase hex from Nextcloud's `oc:checksums`
     *        - only for files uploaded with an `OC-Checksum`
     */
    public function __construct(
        public bool $ordner,
        public ?int $groesse = null,
        public ?string $sha1 = null,
    ) {
    }

    /**
     * Whether this is the file we would upload: same SHA-1 when the cloud
     * knows one, else (a file put there by hand or by the desktop client)
     * the same size.
     */
    public function gleicheDatei(int $groesse, string $sha1): bool
    {
        if ($this->ordner) {
            return false;
        }
        if ($this->sha1 !== null) {
            return hash_equals($this->sha1, $sha1);
        }

        return $this->groesse === $groesse;
    }
}
