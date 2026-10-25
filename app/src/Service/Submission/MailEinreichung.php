<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\AuditAction;
use App\Domain\DocumentSource;
use App\Domain\OcrStatus;
use App\Repository\BlobRepository;
use App\Repository\DocumentRepository;
use App\Repository\JobRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Repository\VaultRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Mail\EinreichungBenachrichtigung;
use App\Service\Worker\MailAbsenderListe;

/**
 * A receipt that arrived by mail, handed in by the worker (issue #87,
 * E-54, docs/spec/07-worker.md section 9): the same rows and jobs as the
 * public submission (App\Service\Submission\SubmissionService) - a
 * `submission` with the next reference, a `document` (`source =
 * einreichung`, payload `kanal = mail`), the session jobs, the notice to
 * the inbox - just without form, spam defence and confirmation mail.
 *
 * Idempotent per mail: the worker sends a key (hex SHA-256 over the
 * Message-ID, else over the whole mail); `submission.form_hash` =
 * SHA-256("mail|" . key) is unique, so a mail handed in again after a
 * restart gives the existing reference instead of a second receipt.
 *
 * Sender and subject are business data: they only ever land in the
 * sealed payload (VK_pub) - not in a log, an audit detail or an error.
 * Framework-free like SubmissionService.
 */
final readonly class MailEinreichung
{
    public const string KANAL = 'mail';

    /** Description when the mail has no subject. */
    public const string OHNE_BETREFF = '(E-Mail ohne Betreff)';

    private const string SCHLUESSEL_MUSTER = '/^[0-9a-f]{64}$/';

    private Referenzvergabe $referenzen;

    public function __construct(
        private \PDO $pdo,
        private SubmissionRepository $submissions,
        private DocumentRepository $documents,
        private SubmissionUploadRepository $submissionUploads,
        private BlobRepository $blobs,
        private VaultRepository $vaults,
        private EinreichungsEinstellungen $einstellungen,
        private JobRepository $jobs,
        private AuditLog $audit,
        private ?EinreichungBenachrichtigung $benachrichtigung = null,
    ) {
        $this->referenzen = new Referenzvergabe($submissions);
    }

    public static function schluesselGueltig(string $schluessel): bool
    {
        return preg_match(self::SCHLUESSEL_MUSTER, $schluessel) === 1;
    }

    /**
     * The binary `form_hash` of the mail with this key - also the binding of
     * its uploaded attachments in `submission_upload` (WorkerMailController
     * via SubmissionUploadStore); einreichen() takes only blobs bound here.
     */
    public static function formHash(string $schluessel): string
    {
        return hash('sha256', 'mail|' . $schluessel, true);
    }

    /** The reference of the receipt made from this mail before, if any. */
    public function vorhanden(string $schluessel): ?string
    {
        return $this->submissions->findReferenzByFormHash(self::formHash($schluessel));
    }

    /**
     * @param list<mixed> $blobIds the attachments, in mail order
     */
    public function einreichen(
        string $schluessel,
        array $blobIds,
        string $absenderName,
        string $absenderAdresse,
        string $betreff,
        MailAbsenderListe $erlaubt,
        \DateTimeImmutable $now,
        ?string $linkBasis = null,
    ): MailEinreichungErgebnis {
        if (!self::schluesselGueltig($schluessel)) {
            return MailEinreichungErgebnis::abgelehnt('schluessel_ungueltig');
        }
        $formHash = self::formHash($schluessel);
        $bestehend = $this->submissions->findReferenzByFormHash($formHash);
        if ($bestehend !== null) {
            return MailEinreichungErgebnis::vorhanden($bestehend);
        }

        $adresse = self::text($absenderAdresse, SubmissionService::EMAIL_MAX);
        $adresse = filter_var($adresse, FILTER_VALIDATE_EMAIL) === false ? null : strtolower($adresse);
        // Checked again here, not only on the worker: the list is the
        // admin's, the worker only carries it.
        if (!$erlaubt->erlaubt($adresse)) {
            return MailEinreichungErgebnis::abgelehnt('absender_nicht_erlaubt');
        }

        $ids = [];
        foreach ($blobIds as $id) {
            if (!is_int($id) || $id < 1 || in_array($id, $ids, true)) {
                return MailEinreichungErgebnis::abgelehnt('anhaenge_ungueltig');
            }
            $ids[] = $id;
        }
        if ($ids === []) {
            return MailEinreichungErgebnis::abgelehnt('keine_anhaenge');
        }
        if (count($ids) > $this->einstellungen->maxSeiten) {
            return MailEinreichungErgebnis::abgelehnt('zu_viele_anhaenge');
        }
        if (array_diff($ids, $this->submissionUploads->blobIdsForFormHash($formHash)) !== []) {
            return MailEinreichungErgebnis::abgelehnt('anhang_unbekannt');
        }
        if ($this->blobs->totalSize($ids) > $this->einstellungen->maxEinreichungBytes()) {
            return MailEinreichungErgebnis::abgelehnt('zu_gross');
        }
        $vault = $this->vaults->current();
        if ($vault === null) {
            return MailEinreichungErgebnis::abgelehnt('kein_tresor');
        }

        $name = self::text($absenderName, SubmissionService::NAME_MAX);
        $freitext = self::text($betreff, SubmissionService::FREITEXT_MAX);
        $angaben = new EinreichungsAngaben(
            name: $name !== '' ? $name : ($adresse ?? 'Unbekannter Absender'),
            email: $adresse,
            erstattung: null,
            iban: null,
            kontoinhaber: null,
            freitext: $freitext !== '' ? $freitext : self::OHNE_BETREFF,
            kostenstelleId: null,
            kanal: self::KANAL,
        );

        $this->pdo->beginTransaction();
        try {
            [$id, $referenz] = $this->referenzen->anlegen($formHash, $angaben, $vault, $now);
            $documentId = $this->documents->insert(
                DocumentSource::Einreichung,
                $id,
                $ids,
                $vault->sealDataKey(DataKey::generate()),
                $now,
                ocrStatus: OcrStatus::Ausstehend,
            );
            Eingangsjobs::einreihen($this->jobs, $documentId, $now);
            $this->submissionUploads->deleteBlobIds($ids);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // The same mail handed in twice at once: the unique form_hash
            // let one through, this is the other.
            $bestehend = $this->submissions->findReferenzByFormHash($formHash);
            if ($bestehend !== null) {
                return MailEinreichungErgebnis::vorhanden($bestehend);
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        // No confirmation to the sender: an automatic answer to any address
        // in a From header would be backscatter (E-54). The inbox is told.
        $this->benachrichtigung?->senden($referenz, $documentId, $linkBasis, $now);
        $this->audit->record(AuditAction::EinreichungEingegangen, null, '', $documentId, ['kanal' => self::KANAL], $now);

        return MailEinreichungErgebnis::eingereicht($referenz);
    }

    /** Trimmed, without control characters, at most $max characters. */
    private static function text(string $wert, int $max): string
    {
        $wert = mb_check_encoding($wert, 'UTF-8') ? $wert : mb_convert_encoding($wert, 'UTF-8', 'UTF-8');
        $sauber = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $wert) ?? '';

        return trim(mb_substr(trim($sauber), 0, $max));
    }
}
