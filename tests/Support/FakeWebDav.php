<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Export\Nextcloud\DavEintrag;
use App\Service\Export\Nextcloud\NextcloudZiel;
use App\Service\Export\Nextcloud\WebDav;
use App\Service\Export\Nextcloud\WebDavFehler;

/**
 * A Nextcloud folder rebuilt in memory for the tests (issue #95) - no test
 * talks to a real cloud. It behaves like Nextcloud where the filing relies
 * on it:
 * - the target folder ('') exists, every other folder only after MKCOL;
 *   MKCOL below a missing folder and PUT into one fail with 409;
 * - a PUT keeps the `OC-Checksum` SHA-1 and returns it in PROPFIND, a file
 *   put there "by hand" (legen()) has none;
 * - a PUT whose body ends before its size fails like Nextcloud's 400.
 *
 * Failures can be armed: the next PUT, or every call, answers with a status.
 */
final class FakeWebDav implements WebDav
{
    /** @var array<string, array{inhalt: string, sha1: ?string}> */
    public array $dateien = [];

    /** @var array<string, true> */
    public array $ordner = ['' => true];

    /** @var list<string> "METHOD path", in order */
    public array $aufrufe = [];

    /** Credentials the fake accepts - anything else is a 401. */
    public string $benutzer = 'kasse';

    public string $passwort = 'app-pass-1234';

    /** Status for the next PUT (then reset), e.g. 507. */
    public ?int $naechsterPutStatus = null;

    /** Status for every call while set, e.g. 503. */
    public ?int $immerStatus = null;

    /** Puts a file there as somebody else would - without checksum. */
    public function legen(string $pfad, string $inhalt): void
    {
        $segmente = explode('/', $pfad);
        array_pop($segmente);
        $weg = '';
        foreach ($segmente as $segment) {
            $weg = $weg === '' ? $segment : $weg . '/' . $segment;
            $this->ordner[$weg] = true;
        }
        $this->dateien[$pfad] = ['inhalt' => $inhalt, 'sha1' => null];
    }

    public function eigenschaften(NextcloudZiel $ziel, string $pfad): ?DavEintrag
    {
        $this->pruefe($ziel, 'PROPFIND', $pfad);
        if (isset($this->ordner[$pfad])) {
            return new DavEintrag(true);
        }
        $datei = $this->dateien[$pfad] ?? null;

        return $datei === null ? null : new DavEintrag(false, strlen($datei['inhalt']), $datei['sha1']);
    }

    public function ordnerAnlegen(NextcloudZiel $ziel, string $pfad): void
    {
        $this->pruefe($ziel, 'MKCOL', $pfad);
        if (isset($this->ordner[$pfad]) || isset($this->dateien[$pfad])) {
            // 405 - exists already; the client takes that as fine.
            return;
        }
        if (!isset($this->ordner[self::eltern($pfad)])) {
            throw WebDavFehler::status(409, 'beim Anlegen eines Ordners');
        }
        $this->ordner[$pfad] = true;
    }

    public function hochladen(NextcloudZiel $ziel, string $pfad, iterable $inhalt, int $groesse, string $sha1): void
    {
        $this->pruefe($ziel, 'PUT', $pfad);
        if ($this->naechsterPutStatus !== null) {
            $status = $this->naechsterPutStatus;
            $this->naechsterPutStatus = null;
            throw WebDavFehler::status($status, 'beim Hochladen');
        }
        if (!isset($this->ordner[self::eltern($pfad)]) || isset($this->ordner[$pfad])) {
            throw WebDavFehler::status(409, 'beim Hochladen');
        }
        $bytes = '';
        foreach ($inhalt as $stueck) {
            $bytes .= $stueck;
        }
        if (strlen($bytes) !== $groesse) {
            throw WebDavFehler::status(400, 'beim Hochladen');
        }
        $this->dateien[$pfad] = ['inhalt' => $bytes, 'sha1' => $sha1];
    }

    /** @return list<string> the PUT paths, in order */
    public function hochgeladen(): array
    {
        return array_values(array_map(
            static fn(string $aufruf): string => substr($aufruf, 4),
            array_filter($this->aufrufe, static fn(string $aufruf): bool => str_starts_with($aufruf, 'PUT ')),
        ));
    }

    private function pruefe(NextcloudZiel $ziel, string $methode, string $pfad): void
    {
        $this->aufrufe[] = $methode . ' ' . $pfad;
        if ($this->immerStatus !== null) {
            throw WebDavFehler::status($this->immerStatus, 'beim Zugriff');
        }
        if ($ziel->benutzer !== $this->benutzer || $ziel->passwort !== $this->passwort) {
            throw WebDavFehler::status(401, 'beim Zugriff');
        }
    }

    private static function eltern(string $pfad): string
    {
        $schnitt = strrpos($pfad, '/');

        return $schnitt === false ? '' : substr($pfad, 0, $schnitt);
    }
}
