<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Domain\DocumentArtifact;
use App\Domain\JobExecutor;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * The decrypted JSON payload (`data_enc`) of a document artifact - shared by
 * the readers of text layer, e-invoice and AI extraction.
 *
 * An artifact the optional worker wrote (issue #52) is input from outside
 * the web host: one that does not open or is not JSON counts as "nothing
 * there" (null) instead of an exception, so it can neither break the AI
 * extraction of its document nor stall the full-text index of everyone
 * (review of #52, issue #53). App\Service\Worker\WorkerUebernahme marks
 * such an artifact defective and hides it. The session's own artifacts
 * keep failing loudly - a broken one of those is a bug, not input.
 */
final class ArtefaktJson
{
    private const string TABELLE = 'document_artifact';

    private const string SPALTE = 'data_enc';

    /**
     * @return array<mixed>|null null while the artifact has no payload, and
     *         for a worker artifact that does not open or is no JSON object
     */
    public static function lesen(DocumentArtifact $artefakt, Vault $vault): ?array
    {
        if ($artefakt->dataEnc === null) {
            return null;
        }
        if ($artefakt->producer !== JobExecutor::Worker) {
            return self::entschluesseln($artefakt, $vault);
        }
        try {
            return self::entschluesseln($artefakt, $vault);
        } catch (CryptoException | \SodiumException | \JsonException) {
            return null;
        }
    }

    /**
     * @return array<mixed>|null
     *
     * @throws CryptoException|\SodiumException|\JsonException
     */
    private static function entschluesseln(DocumentArtifact $artefakt, Vault $vault): ?array
    {
        $json = FieldCipher::decrypt(
            $vault->openDataKey($artefakt->dekSealed),
            (string) $artefakt->dataEnc,
            new FieldContext(self::TABELLE, $artefakt->id, self::SPALTE),
        );
        $daten = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($daten) ? $daten : null;
    }
}
