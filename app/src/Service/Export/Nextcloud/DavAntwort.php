<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

/**
 * Reads the 207 Multi-Status answer of a depth-0 PROPFIND
 * (App\Service\Export\Nextcloud\CurlWebDav): folder or file, the size and
 * Nextcloud's SHA-1 checksum. Pure, so it is tested without a server.
 *
 * The XML comes from outside: no network access, no entity substitution
 * (LIBXML_NONET, and LIBXML_NOENT deliberately not set), and a size limit.
 */
final class DavAntwort
{
    public const int MAX_BYTES = 1024 * 1024;

    private const string DAV = 'DAV:';

    private const string OC = 'http://owncloud.org/ns';

    /** The PROPFIND request body. */
    public const string PROPFIND = '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
        . '<d:resourcetype/><d:getcontentlength/><oc:checksums/>'
        . '</d:prop></d:propfind>';

    /**
     * @throws WebDavFehler when the answer is no readable Multi-Status
     */
    public static function lesen(string $xml): DavEintrag
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw WebDavFehler::antwort('zu groß');
        }
        $dom = new \DOMDocument();
        $vorher = libxml_use_internal_errors(true);
        try {
            $ok = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($vorher);
        }
        if (!$ok || $dom->doctype !== null) {
            throw WebDavFehler::antwort('kein gültiges XML');
        }

        $antwort = $dom->getElementsByTagNameNS(self::DAV, 'response')->item(0);
        if (!$antwort instanceof \DOMElement) {
            throw WebDavFehler::antwort('keine Eigenschaften');
        }

        // Only properties of a propstat with status 200 count: the others
        // list what the server does not have (e.g. a folder's length).
        $ordner = false;
        $groesse = null;
        $sha1 = null;
        foreach ($antwort->getElementsByTagNameNS(self::DAV, 'propstat') as $propstat) {
            $status = $propstat->getElementsByTagNameNS(self::DAV, 'status')->item(0)?->textContent ?? '';
            if (preg_match('#^HTTP/\S+\s+200\b#', trim($status)) !== 1) {
                continue;
            }
            foreach ($propstat->getElementsByTagNameNS(self::DAV, 'resourcetype') as $typ) {
                if ($typ->getElementsByTagNameNS(self::DAV, 'collection')->length > 0) {
                    $ordner = true;
                }
            }
            $laenge = $propstat->getElementsByTagNameNS(self::DAV, 'getcontentlength')->item(0)?->textContent;
            if ($laenge !== null && preg_match('/^\d{1,15}$/', trim($laenge)) === 1) {
                $groesse = (int) trim($laenge);
            }
            foreach ($propstat->getElementsByTagNameNS(self::OC, 'checksum') as $pruefsumme) {
                // "SHA1:… MD5:… ADLER32:…", space separated.
                if (preg_match('/(?:^|\s)SHA1:([0-9a-fA-F]{40})(?:\s|$)/', $pruefsumme->textContent, $treffer) === 1) {
                    $sha1 = strtolower($treffer[1]);
                }
            }
        }

        return new DavEintrag($ordner, $ordner ? null : $groesse, $ordner ? null : $sha1);
    }
}
