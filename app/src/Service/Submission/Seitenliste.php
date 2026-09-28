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

    /**
     * Reads the optional list of processed pages (issue #34/M5-4): parallel
     * to the originals $originale, one entry per page - the blob id of the
     * scanner's processed version, or null where the page came without one.
     * A missing or null field means no page was processed (a browser that
     * could not, or a client from before the scanner). A processed id may
     * neither repeat nor be one of the originals.
     *
     * @param list<int> $originale
     * @return array{0: list<int|null>, 1: ?string} one entry per original,
     *         or an empty list and the German message saying what is wrong
     */
    public static function aufbereitungLesen(mixed $eingabe, array $originale): array
    {
        if ($eingabe === null) {
            return [array_fill(0, count($originale), null), null];
        }

        if (!is_array($eingabe) || !array_is_list($eingabe) || count($eingabe) !== count($originale)) {
            return [[], 'Die aufbereiteten Seiten passen nicht zu den Seiten. Bitte erneut hochladen.'];
        }

        $blobIds = [];
        foreach ($eingabe as $wert) {
            if ($wert === null) {
                $blobIds[] = null;
                continue;
            }
            $blobId = is_int($wert) ? $wert : (is_string($wert) && ctype_digit($wert) ? (int) $wert : null);
            if ($blobId === null || $blobId < 1) {
                return [[], 'Eine Seite ist ungültig. Bitte erneut hochladen.'];
            }

            $blobIds[] = $blobId;
        }

        $gesetzt = array_values(array_filter($blobIds, is_int(...)));
        if (count(array_unique($gesetzt)) !== count($gesetzt) || array_intersect($gesetzt, $originale) !== []) {
            return [[], 'Eine Seite wurde doppelt eingereicht.'];
        }

        return [$blobIds, null];
    }
}
