<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * The three WebDAV calls the Nextcloud filing needs (issue #95). Production
 * uses App\Service\Export\Nextcloud\CurlWebDav; the tests a WebDAV folder
 * rebuilt in memory, so no test talks to a real cloud.
 *
 * Paths are relative to the target folder, "/"-separated, unencoded
 * (NextcloudZiel::adresse() encodes them). Every error is a WebDavFehler
 * with a German message that names no path - paths carry supplier names
 * (CLAUDE.md section 4).
 */
interface WebDav
{
    /**
     * PROPFIND with depth 0: what is at $pfad, or null when nothing is.
     * '' asks for the target folder itself.
     *
     * @throws WebDavFehler
     */
    public function eigenschaften(NextcloudZiel $ziel, string $pfad): ?DavEintrag;

    /**
     * MKCOL. A folder that exists already is fine.
     *
     * @throws WebDavFehler
     */
    public function ordnerAnlegen(NextcloudZiel $ziel, string $pfad): void;

    /**
     * PUT of $groesse bytes, streamed from $inhalt, with the SHA-1 as
     * Nextcloud's `OC-Checksum` - Nextcloud keeps it and returns it with
     * eigenschaften(), which is how a second filing recognises the file.
     *
     * @param iterable<string> $inhalt
     *
     * @throws WebDavFehler
     */
    public function hochladen(NextcloudZiel $ziel, string $pfad, iterable $inhalt, int $groesse, string $sha1): void;
}
