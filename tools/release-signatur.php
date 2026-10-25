<?php

/**
 * Release signing (issue #241, docs/betrieb.md "Release-Signatur"). Not part
 * of a release: the maintainer runs it once to create the key pair, the
 * release workflow runs it to sign checksums.txt.
 *
 *   php tools/release-signatur.php schluessel <datei-fuer-geheimen-schluessel>
 *       Creates an Ed25519 key pair. The SECRET key goes into the given file
 *       (mode 0600) - never into the repository; its content becomes the
 *       GitHub secret RELEASE_SIGNING_KEY. The PUBLIC key is printed; it
 *       goes into ReleaseSignatur::PUBLIC_KEY and is committed.
 *
 *   php tools/release-signatur.php signieren <checksums.txt>
 *       Release workflow: signs with the secret from the environment
 *       variable RELEASE_SIGNING_KEY and writes <checksums.txt>.sig.
 *       Without the secret: a warning and exit 0 as long as no public key is
 *       fixed in the code (releases keep working); an error once one is -
 *       every installation would refuse an unsigned release.
 *
 *   php tools/release-signatur.php pruefen <checksums.txt> [<signatur>]
 *       Checks a downloaded list against the public key in the code.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/app/src/Service/Update/ReleaseSignatur.php';

use App\Service\Update\ReleaseSignatur;

/**
 * @param list<string> $argv
 */
function release_signatur_main(array $argv): int
{
    $befehl = $argv[1] ?? '';

    try {
        return match ($befehl) {
            'schluessel' => release_signatur_schluessel($argv[2] ?? ''),
            'signieren' => release_signatur_signieren($argv[2] ?? ''),
            'pruefen' => release_signatur_pruefen($argv[2] ?? '', $argv[3] ?? null),
            default => release_signatur_hilfe(),
        };
    } catch (\RuntimeException $e) {
        fwrite(STDERR, '::error::' . $e->getMessage() . "\n");

        return 1;
    }
}

function release_signatur_hilfe(): int
{
    fwrite(STDERR, "Aufruf: php tools/release-signatur.php schluessel <datei> | signieren <checksums.txt>"
        . " | pruefen <checksums.txt> [<signatur>]\n");

    return 2;
}

function release_signatur_schluessel(string $datei): int
{
    if ($datei === '') {
        return release_signatur_hilfe();
    }
    if (file_exists($datei)) {
        throw new \RuntimeException('Datei existiert schon, wird nicht überschrieben: ' . $datei);
    }

    $paar = ReleaseSignatur::erzeugeSchluesselpaar();
    $alt = umask(0o077);
    try {
        if (file_put_contents($datei, $paar['secret'] . "\n") === false) {
            throw new \RuntimeException('Datei nicht beschreibbar: ' . $datei);
        }
    } finally {
        umask($alt);
    }
    chmod($datei, 0o600);

    echo "Geheimer Schlüssel gespeichert in: {$datei}\n";
    echo "  -> Inhalt als GitHub-Secret RELEASE_SIGNING_KEY anlegen, Datei sicher verwahren (nie committen).\n";
    echo "Öffentlicher Schlüssel (in ReleaseSignatur::PUBLIC_KEY eintragen und committen):\n";
    echo $paar['public'] . "\n";

    return 0;
}

function release_signatur_signieren(string $liste): int
{
    if ($liste === '' || !is_file($liste)) {
        throw new \RuntimeException('Prüfsummenliste nicht gefunden: ' . $liste);
    }

    $geheim = trim((string) getenv('RELEASE_SIGNING_KEY'));
    if ($geheim === '') {
        if (ReleaseSignatur::erzwungen()) {
            throw new \RuntimeException('ReleaseSignatur::PUBLIC_KEY ist gesetzt, aber das Secret RELEASE_SIGNING_KEY '
                . 'fehlt - ein unsigniertes Release würde jede Installation ablehnen.');
        }
        echo "::warning::RELEASE_SIGNING_KEY ist nicht gesetzt - das Release wird ohne Signatur veröffentlicht.\n";

        return 0;
    }

    $public = ReleaseSignatur::publicKeyZu($geheim);
    if (ReleaseSignatur::erzwungen() && !hash_equals(ReleaseSignatur::PUBLIC_KEY, $public)) {
        throw new \RuntimeException('RELEASE_SIGNING_KEY passt nicht zu ReleaseSignatur::PUBLIC_KEY.');
    }

    $inhalt = (string) file_get_contents($liste);
    $signatur = ReleaseSignatur::signiere($inhalt, $geheim);
    // Self-check before anything is published.
    ReleaseSignatur::pruefe($inhalt, $signatur, $public);
    if (file_put_contents($liste . '.sig', $signatur . "\n") === false) {
        throw new \RuntimeException('Signatur nicht schreibbar: ' . $liste . '.sig');
    }

    if (!ReleaseSignatur::erzwungen()) {
        echo "::warning::Release signiert, aber ReleaseSignatur::PUBLIC_KEY ist noch leer - Installationen prüfen "
            . "die Signatur erst, wenn der öffentliche Schlüssel {$public} im Code steht.\n";
    } else {
        echo "Signatur geschrieben: {$liste}.sig\n";
    }

    return 0;
}

function release_signatur_pruefen(string $liste, ?string $signaturDatei): int
{
    if ($liste === '' || !is_file($liste)) {
        throw new \RuntimeException('Prüfsummenliste nicht gefunden: ' . $liste);
    }
    $signaturDatei ??= $liste . '.sig';
    $signatur = is_file($signaturDatei) ? (string) file_get_contents($signaturDatei) : null;

    $hinweis = ReleaseSignatur::pruefe((string) file_get_contents($liste), $signatur);
    echo ($hinweis ?? 'Signatur gültig.') . "\n";

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(release_signatur_main($argv));
}
