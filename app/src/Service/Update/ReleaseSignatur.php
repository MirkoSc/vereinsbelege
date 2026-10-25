<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Authenticity of a release (issue #241, N-02; docs/spec/06-betrieb.md
 * section 1): the release workflow signs checksums.txt with Ed25519
 * (libsodium, detached signature) and publishes the signature as the asset
 * checksums.txt.sig. The list names the SHA-256 of the release ZIP and of
 * every file inside it, so one signature covers the download AND the list
 * the code integrity check compares against later.
 *
 * Inlined into setup.php by bin/build_setup.php like ReleaseDownloader:
 * keep it dependency-free (no App\ imports, no autoloader).
 *
 * The public key is fixed here, in the code: an attacker who can only
 * change files in shared/ or answer with a forged release cannot swap it.
 * As long as PUBLIC_KEY is empty (no key pair created yet) a missing or
 * unverifiable signature is a WARNING, not a refusal - releases must keep
 * working before the maintainer has created the key. Once it is set, every
 * later release must carry a valid signature (docs/betrieb.md, section
 * "Release-Signatur").
 *
 * Formats (all base64, standard alphabet, surrounding whitespace ignored):
 * public key 32 bytes, secret key 64 bytes (sodium_crypto_sign_secretkey),
 * signature 64 bytes over the exact bytes of checksums.txt.
 */
final class ReleaseSignatur
{
    /**
     * Ed25519 public key of the release signing key, base64. Empty = no key
     * pair yet: signatures are not enforced. Set by the maintainer with
     * `php tools/release-signatur.php schluessel` (docs/betrieb.md).
     */
    public const string PUBLIC_KEY = '';

    /** Asset name of the signature next to checksums.txt. */
    public const string ASSET = 'checksums.txt.sig';

    /**
     * Checks $inhalt against $signatur.
     *
     * @param string|null $signatur base64 signature, null when the release
     *        carries none
     * @param string $publicKey base64 public key; empty = none fixed yet
     * @return string|null a German warning when nothing could be verified
     *         because no key is fixed yet, null when the signature is valid
     * @throws \RuntimeException when a key is fixed and the signature is
     *         missing, malformed or wrong
     */
    public static function pruefe(string $inhalt, ?string $signatur, string $publicKey = self::PUBLIC_KEY): ?string
    {
        if (trim($publicKey) === '') {
            return $signatur === null
                ? 'Hinweis: Das Release ist nicht signiert; seine Echtheit wurde nicht geprüft '
                    . '(noch kein Signaturschlüssel festgelegt).'
                : 'Hinweis: Das Release ist signiert, aber diese Version kennt noch keinen Signaturschlüssel; '
                    . 'die Echtheit wurde nicht geprüft.';
        }

        $schluessel = self::dekodiere($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($schluessel === null) {
            throw new \RuntimeException('Der festgelegte Signaturschlüssel ist ungültig.');
        }
        if ($signatur === null) {
            throw new \RuntimeException('Das Release ist nicht signiert – Echtheit nicht bestätigt, Abbruch.');
        }
        $roh = self::dekodiere($signatur, SODIUM_CRYPTO_SIGN_BYTES);
        if ($roh === null || !sodium_crypto_sign_verify_detached($roh, $inhalt, $schluessel)) {
            throw new \RuntimeException(
                'Die Signatur des Releases ist ungültig – das Release ist nicht echt oder wurde verändert, Abbruch.',
            );
        }

        return null;
    }

    /**
     * Whether a key is fixed, i.e. whether pruefe() enforces.
     */
    public static function erzwungen(string $publicKey = self::PUBLIC_KEY): bool
    {
        return trim($publicKey) !== '';
    }

    /**
     * Signs $inhalt (release workflow, via tools/release-signatur.php).
     *
     * @param string $secretKey base64 secret key (64 bytes)
     * @return string base64 signature
     */
    public static function signiere(string $inhalt, string $secretKey): string
    {
        $schluessel = self::dekodiere($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        if ($schluessel === null) {
            throw new \RuntimeException('RELEASE_SIGNING_KEY ist kein gültiger Ed25519-Schlüssel (64 Bytes, base64).');
        }
        $signatur = sodium_crypto_sign_detached($inhalt, $schluessel);
        sodium_memzero($schluessel);

        return base64_encode($signatur);
    }

    /**
     * The public key belonging to a secret key - lets the workflow refuse a
     * secret that does not match PUBLIC_KEY before it publishes a release
     * every installation would reject.
     *
     * @return string base64 public key
     */
    public static function publicKeyZu(string $secretKey): string
    {
        $schluessel = self::dekodiere($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        if ($schluessel === null) {
            throw new \RuntimeException('RELEASE_SIGNING_KEY ist kein gültiger Ed25519-Schlüssel (64 Bytes, base64).');
        }
        $public = sodium_crypto_sign_publickey_from_secretkey($schluessel);
        sodium_memzero($schluessel);

        return base64_encode($public);
    }

    /**
     * A fresh key pair (maintainer, once).
     *
     * @return array{public: string, secret: string} both base64
     */
    public static function erzeugeSchluesselpaar(): array
    {
        $paar = sodium_crypto_sign_keypair();
        $ergebnis = [
            'public' => base64_encode(sodium_crypto_sign_publickey($paar)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($paar)),
        ];
        sodium_memzero($paar);

        return $ergebnis;
    }

    private static function dekodiere(string $wert, int $laenge): ?string
    {
        $roh = base64_decode(trim($wert), true);

        return $roh !== false && strlen($roh) === $laenge ? $roh : null;
    }
}
