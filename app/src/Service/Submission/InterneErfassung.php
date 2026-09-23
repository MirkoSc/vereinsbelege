<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\AuditAction;
use App\Domain\DocumentSource;
use App\Domain\Erstattungsart;
use App\Domain\Iban;
use App\Domain\JobExecutor;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\Vault;
use App\Service\Document\PdfErzeugung;
use App\Service\Mail\EinreichungBenachrichtigung;

/**
 * The internal capture `/app/belege/neu` (docs/spec/03-erfassung-und-ki.md
 * section 1, issue #28/M4-6): a logged-in account with
 * `document.submit_internal` captures several receipts in one go. Each
 * receipt becomes a `submission` row (reference number, sealed payload - the
 * name is the account's display name) and a `document` with source `intern`
 * and `created_by`, exactly the shape the inbox already reads.
 *
 * Differences to the public submission (App\Service\Submission\
 * SubmissionService): the reimbursement and the description are optional,
 * there is no e-mail, no privacy checkbox and no spam defence - the caller
 * is logged in and holds the right.
 *
 * Which pages may be attached: the page uploads them through `/api/upload`
 * with its capture id in `X-Erfassung` (App\Api\UploadController), which
 * records every finished blob in `submission_upload` under uploadHash() -
 * bound to the account AND to this page load. Only blobs recorded there can
 * be claimed, each once.
 *
 * Framework-free like SubmissionService: no Http, no Session - the caller
 * (App\App\ErfassungController) has checked the right and the CSRF token.
 * Sealing needs only the vault's public key, no unlocked vault
 * (CLAUDE.md section 4).
 */
final readonly class InterneErfassung
{
    /** Receipts per pass - generous for a club, small enough for one short request. */
    public const int MAX_BELEGE = 50;

    /** Pages per receipt. */
    public const int MAX_SEITEN = 50;

    private Referenzvergabe $referenzen;

    public function __construct(
        private \PDO $pdo,
        private SubmissionRepository $submissions,
        private DocumentRepository $documents,
        private SubmissionUploadRepository $submissionUploads,
        private CostCenterRepository $kostenstellen,
        private AuditLog $audit,
        private JobRepository $jobs,
        private ?EinreichungBenachrichtigung $benachrichtigung = null,
    ) {
        $this->referenzen = new Referenzvergabe($submissions);
    }

    /**
     * Whether a capture id from the page has the shape GET /app/belege/neu
     * hands out: 16 random bytes as hex.
     */
    public static function istErfassungsId(string $erfassung): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $erfassung) === 1;
    }

    public static function neueErfassungsId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The `submission_upload.form_hash` of an internal upload: the account
     * and the page load together, so neither another account nor another
     * visit of the same account can claim these blobs.
     */
    public static function uploadHash(int $userId, string $erfassung): string
    {
        return hash('sha256', 'intern|' . $userId . '|' . $erfassung, true);
    }

    /**
     * @param array<string, mixed> $eingabe the decoded JSON body
     *        `{belege: [{blobs, freitext?, kostenstelle?, erstattung?, iban?, kontoinhaber?}]}`
     * @param string $anzeigename the capturing account's display name - the
     *        "Name" the inbox shows
     * @param string|null $linkBasis checked base URL for the link in the
     *        notice to the inbox (App\Service\Mail\PublicUrl::resolve())
     */
    public function erfassen(
        array $eingabe,
        int $userId,
        string $anzeigename,
        string $erfassung,
        Vault $vault,
        string $ip = '',
        ?\DateTimeImmutable $now = null,
        ?string $linkBasis = null,
    ): InterneErfassungErgebnis {
        $now ??= new \DateTimeImmutable();

        if (!self::istErfassungsId($erfassung)) {
            return InterneErfassungErgebnis::fehler(['belege' => 'Die Seite ist abgelaufen – bitte neu laden.']);
        }

        $belege = $eingabe['belege'] ?? null;
        if (!is_array($belege) || $belege === [] || !array_is_list($belege)) {
            return InterneErfassungErgebnis::fehler(['belege' => 'Bitte mindestens einen Beleg hinzufügen.']);
        }
        if (count($belege) > self::MAX_BELEGE) {
            return InterneErfassungErgebnis::fehler(['belege' => sprintf('Höchstens %d Belege auf einmal.', self::MAX_BELEGE)]);
        }

        $uploadHash = self::uploadHash($userId, $erfassung);
        $fehler = [];
        $seiten = [];
        foreach ($belege as $index => $beleg) {
            [$blobIds, $problem] = Seitenliste::lesen(is_array($beleg) ? ($beleg['blobs'] ?? null) : null, self::MAX_SEITEN);
            if ($problem !== null) {
                $fehler[$index . '.seiten'] = $problem;
            }
            $seiten[$index] = $blobIds;
        }

        // A retried request (the browser resent it after a lost response) -
        // the same answer, not a second set of rows. Checked before the page
        // ownership below: the claimed pages are no longer in
        // submission_upload once the first attempt committed.
        if ($fehler === []) {
            $bestehend = $this->bestehendeReferenzen($uploadHash, $seiten);
            if ($bestehend !== null) {
                return InterneErfassungErgebnis::erfolg($bestehend);
            }
        }

        $aktiveKostenstellen = $this->kostenstellen->active();
        $angaben = [];
        foreach ($belege as $index => $beleg) {
            $angaben[$index] = $this->pruefeAngaben(is_array($beleg) ? $beleg : [], $anzeigename, $aktiveKostenstellen, $index, $fehler);
        }

        $this->pruefeHerkunft($seiten, $uploadHash, $fehler);

        if ($fehler !== []) {
            return InterneErfassungErgebnis::fehler($fehler);
        }

        $erfasst = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($angaben as $index => $beleg) {
                \assert($beleg instanceof EinreichungsAngaben);
                [$submissionId, $referenz] = $this->referenzen->anlegen(
                    self::belegHash($uploadHash, $seiten[$index]),
                    $beleg,
                    $vault,
                    $now,
                );

                $documentId = $this->documents->insert(
                    DocumentSource::Intern,
                    $submissionId,
                    $seiten[$index],
                    $vault->sealDataKey(DataKey::generate()),
                    $now,
                    costCenterId: $beleg->kostenstelleId,
                    createdBy: $userId,
                );

                // The PDF working copy is a session job, the same as for the
                // public submission (issue #26/M4-4).
                $this->jobs->enqueue(PdfErzeugung::JOB_TYP, JobExecutor::Session, 'document', $documentId, now: $now);

                $erfasst[] = [$referenz, $documentId];
            }

            $this->submissionUploads->deleteBlobIds(array_merge(...array_values($seiten)));

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        foreach ($erfasst as [$referenz, $documentId]) {
            $this->benachrichtigung?->senden($referenz, $documentId, $linkBasis, $now, ausser: $userId);
            $this->audit->record(AuditAction::BelegErfasst, $userId, $ip, $documentId, [], $now);
        }

        return InterneErfassungErgebnis::erfolg(array_column($erfasst, 0));
    }

    /**
     * The references of an earlier, identical request - null unless every
     * receipt of this one already exists.
     *
     * @param array<int, list<int>> $seiten
     * @return list<string>|null
     */
    private function bestehendeReferenzen(string $uploadHash, array $seiten): ?array
    {
        $referenzen = [];
        foreach ($seiten as $blobIds) {
            $referenz = $this->submissions->findReferenzByFormHash(self::belegHash($uploadHash, $blobIds));
            if ($referenz === null) {
                return null;
            }
            $referenzen[] = $referenz;
        }

        return $referenzen;
    }

    /**
     * `submission.form_hash` of one receipt: unique per receipt (its first
     * page is claimed only once) and the same again on a retried request.
     *
     * @param list<int> $blobIds
     */
    private static function belegHash(string $uploadHash, array $blobIds): string
    {
        return hash('sha256', 'intern-beleg|' . $uploadHash . '|' . $blobIds[0], true);
    }

    /**
     * @param array<string, mixed> $beleg
     * @param array<int, string> $aktiveKostenstellen
     * @param array<string, string> $fehler filled in by reference, keys
     *        "<index>.<field>"
     */
    private function pruefeAngaben(array $beleg, string $anzeigename, array $aktiveKostenstellen, int $index, array &$fehler): ?EinreichungsAngaben
    {
        $vorher = count($fehler);

        $freitext = self::text($beleg['freitext'] ?? null);
        if (mb_strlen($freitext) > SubmissionService::FREITEXT_MAX) {
            $fehler[$index . '.freitext'] = 'Der Text ist zu lang.';
        }

        $erstattung = null;
        $wert = $beleg['erstattung'] ?? null;
        if ($wert !== null && $wert !== '') {
            $erstattung = is_string($wert) ? Erstattungsart::tryFrom($wert) : null;
            if ($erstattung === null) {
                $fehler[$index . '.erstattung'] = 'Unbekannte Erstattungsart.';
            }
        }

        $iban = self::text($beleg['iban'] ?? null);
        $kontoinhaber = self::text($beleg['kontoinhaber'] ?? null);
        if ($erstattung === Erstattungsart::Ueberweisung) {
            if ($iban === '') {
                $fehler[$index . '.iban'] = 'Bitte eine IBAN angeben.';
            } elseif (!Iban::istGueltig($iban)) {
                $fehler[$index . '.iban'] = 'Das ist keine gültige IBAN.';
            }

            if ($kontoinhaber === '') {
                $fehler[$index . '.kontoinhaber'] = 'Bitte den Namen des Kontoinhabers angeben.';
            } elseif (mb_strlen($kontoinhaber) > SubmissionService::KONTOINHABER_MAX) {
                $fehler[$index . '.kontoinhaber'] = 'Der Name ist zu lang.';
            }
        }

        $kostenstelleId = self::kostenstelleId($beleg['kostenstelle'] ?? null);
        if ($kostenstelleId !== null && !array_key_exists($kostenstelleId, $aktiveKostenstellen)) {
            $fehler[$index . '.kostenstelle'] = 'Unbekannte Mannschaft oder unbekannter Bereich.';
        }

        if (count($fehler) !== $vorher) {
            return null;
        }

        return new EinreichungsAngaben(
            name: mb_substr(trim($anzeigename), 0, SubmissionService::NAME_MAX),
            email: null,
            erstattung: $erstattung,
            iban: $erstattung === Erstattungsart::Ueberweisung ? Iban::normalisieren($iban) : null,
            kontoinhaber: $erstattung === Erstattungsart::Ueberweisung ? $kontoinhaber : null,
            freitext: $freitext,
            kostenstelleId: $kostenstelleId,
        );
    }

    /**
     * Every page must have been uploaded by this account on this page load
     * and not be claimed yet - and appear in one receipt only.
     *
     * @param array<int, list<int>> $seiten
     * @param array<string, string> $fehler
     */
    private function pruefeHerkunft(array $seiten, string $uploadHash, array &$fehler): void
    {
        $erlaubt = array_flip($this->submissionUploads->blobIdsForFormHash($uploadHash));
        $gesehen = [];

        foreach ($seiten as $index => $blobIds) {
            foreach ($blobIds as $blobId) {
                if (isset($gesehen[$blobId])) {
                    $fehler[$index . '.seiten'] = 'Eine Seite steckt in mehreren Belegen.';
                } elseif (!isset($erlaubt[$blobId])) {
                    $fehler[$index . '.seiten'] = 'Eine Seite ist nicht mehr vorhanden. Bitte erneut hochladen.';
                }
                $gesehen[$blobId] = true;
            }
        }
    }

    private static function kostenstelleId(mixed $wert): ?int
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        if (is_int($wert)) {
            return $wert;
        }

        return is_string($wert) && ctype_digit($wert) ? (int) $wert : -1;
    }

    private static function text(mixed $wert): string
    {
        return is_string($wert) ? trim($wert) : '';
    }
}
