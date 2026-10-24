<?php

declare(strict_types=1);

namespace App\Tests\Service\Export\Nextcloud;

use App\Service\Export\Nextcloud\DavAntwort;
use App\Service\Export\Nextcloud\DavEintrag;
use App\Service\Export\Nextcloud\WebDavFehler;
use PHPUnit\Framework\TestCase;

/**
 * Multi-Status answers as Nextcloud sends them for a depth-0 PROPFIND
 * (shortened, made-up paths).
 */
final class DavAntwortTest extends TestCase
{
    private const string SHA1 = 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3';

    public function testAFileWithChecksumAndSize(): void
    {
        $eintrag = DavAntwort::lesen(self::antwort(
            '<d:resourcetype/><d:getcontentlength>4711</d:getcontentlength>'
            . '<oc:checksums><oc:checksum>SHA1:' . strtoupper(self::SHA1) . ' MD5:00000000000000000000000000000000 ADLER32:01234567</oc:checksum></oc:checksums>',
        ));

        self::assertFalse($eintrag->ordner);
        self::assertSame(4711, $eintrag->groesse);
        self::assertSame(self::SHA1, $eintrag->sha1, 'lowercase');
    }

    public function testAFolderAndThePropertiesItLacks(): void
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:href>/dav/Belege/</d:href>'
            . '<d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            . '<d:propstat><d:prop><d:getcontentlength/><oc:checksums/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>'
            . '</d:response></d:multistatus>';

        self::assertEquals(new DavEintrag(true), DavAntwort::lesen($xml));
    }

    public function testAFileUploadedByHandHasNoChecksum(): void
    {
        $eintrag = DavAntwort::lesen(self::antwort('<d:resourcetype/><d:getcontentlength>12</d:getcontentlength><oc:checksums/>'));

        self::assertNull($eintrag->sha1);
        self::assertTrue($eintrag->gleicheDatei(12, self::SHA1), 'then the size decides');
        self::assertFalse($eintrag->gleicheDatei(13, self::SHA1));
    }

    public function testTheChecksumDecidesWhenThereIsOne(): void
    {
        $eintrag = new DavEintrag(false, 12, self::SHA1);

        self::assertTrue($eintrag->gleicheDatei(12, self::SHA1));
        self::assertFalse($eintrag->gleicheDatei(12, str_repeat('0', 40)), 'same size, other content');
        self::assertFalse(new DavEintrag(true)->gleicheDatei(0, self::SHA1), 'a folder is never the file');
    }

    public function testBrokenOrHostileAnswersAreRefused(): void
    {
        $falle = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:getcontentlength>&x;</d:getcontentlength></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

        foreach (['', 'kein xml', '<html><body>Login</body></html>', $falle, str_repeat(' ', DavAntwort::MAX_BYTES + 1)] as $xml) {
            try {
                DavAntwort::lesen($xml);
                self::fail('accepted: ' . substr($xml, 0, 40));
            } catch (WebDavFehler) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private static function antwort(string $eigenschaften): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response>'
            . '<d:href>/dav/Belege/a.pdf</d:href><d:propstat><d:prop>' . $eigenschaften . '</d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
    }
}
