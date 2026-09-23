<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Reads the page list of one receipt from a decoded JSON body - the shape
 * check both the public submission (App\Service\Submission\
 * SubmissionService) and the internal capture (App\Service\Submission\
 * InterneErfassung, issue #28/M4-6) make before asking whether the pages
 * really belong to the caller: at least one, at most $maxSeiten, positive
 * integers, none twice.
 */
final class Seitenliste
{
    /**
     * @return array{0: list<int>, 1: ?string} the blob ids in page order, or
     *         an empty list and the German message saying what is wrong
     */
    public static function lesen(mixed $eingabe, int $maxSeiten): array
    {
        if (!is_array($eingabe) || $eingabe === []) {
            return [[], 'Bitte mindestens eine Seite hinzufügen.'];
        }

        if (count($eingabe) > $maxSeiten) {
            return [[], 'Zu viele Seiten in einer Einreichung.'];
        }

        $blobIds = [];
        foreach ($eingabe as $wert) {
            $blobId = is_int($wert) ? $wert : (is_string($wert) && ctype_digit($wert) ? (int) $wert : null);
            if ($blobId === null || $blobId < 1) {
                return [[], 'Eine Seite ist ungültig. Bitte erneut hochladen.'];
            }

            $blobIds[] = $blobId;
        }

        if (count(array_unique($blobIds)) !== count($blobIds)) {
            return [[], 'Eine Seite wurde doppelt eingereicht.'];
        }

        return [$blobIds, null];
    }
}
