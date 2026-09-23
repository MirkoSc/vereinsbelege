<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\AuditAction;
use App\Domain\DocumentSource;
use App\Domain\Erstattungsart;
use App\Domain\Iban;
use App\Domain\JobExecutor;
use App\Repository\BlobRepository;
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
use App\Service\Mail\Mailer;

/**
 * The rules of the public submission (docs/spec/03-erfassung-und-ki.md
 * section 1, issue #24/M4-2): validates the form, checks the uploaded pages
 * belong to this visit, then writes `submission` and `document`, queues the
 * `pdf_erzeugen` job (issue #26/M4-4), all in one transaction, and hands back
 * a reference number. After the commit it queues the confirmation to the
 * submitter and the notice to everyone who works the inbox (issue #27/M4-5).
 *
 * Framework-free like the other Account/MasterData services: no Http, no
 * Session - the caller (App\PublicPages\EinreichungController) has already
 * verified the form token and resolved the vault.
 */
final readonly class SubmissionService
{
    public const int NAME_MAX = 200;
    public const int EMAIL_MAX = 254;
    public const int KONTOINHABER_MAX = 200;
    public const int FREITEXT_MAX = 1000;

    private Referenzvergabe $referenzen;

    public function __construct(
        private \PDO $pdo,
        private SubmissionRepository $submissions,
        private DocumentRepository $documents,
        private SubmissionUploadRepository $submissionUploads,
        private BlobRepository $blobs,
        private CostCenterRepository $kostenstellen,
        private Mailer $mailer,
        private AuditLog $audit,
        private EinreichungsEinstellungen $einstellungen,
        private JobRepository $jobs,
        private ?EinreichungBenachrichtigung $benachrichtigung = null,
    ) {
        $this->referenzen = new Referenzvergabe($submissions);
    }

    /**
     * @param array<string, mixed> $eingabe the decoded JSON body
     * @param string|null $linkBasis checked base URL for the link in the
     *        notice to the inbox (App\Service\Mail\PublicUrl::resolve())
     */
    public function einreichen(
        array $eingabe,
        string $formHash,
        Vault $vault,
        ?\DateTimeImmutable $now = null,
        ?string $linkBasis = null,
    ): SubmissionResult {
        $now ??= new \DateTimeImmutable();

        // A retried request (the browser resent it after a lost response) -
        // the same answer, not a second row.
        $bestehend = $this->submissions->findReferenzByFormHash($formHash);
        if ($bestehend !== null) {
            return SubmissionResult::erfolg($bestehend);
        }

        [$angaben, $fehler] = $this->pruefeAngaben($eingabe);
        $blobIds = $this->pruefeSeiten($eingabe['blobs'] ?? null, $formHash, $fehler);

        if ($fehler !== []) {
            return SubmissionResult::fehler($fehler);
        }
        \assert($angaben instanceof EinreichungsAngaben);

        $this->pdo->beginTransaction();
        try {
            [$id, $referenz] = $this->referenzen->anlegen($formHash, $angaben, $vault, $now);

            $documentId = $this->documents->insert(
                DocumentSource::Einreichung,
                $id,
                $blobIds,
                $vault->sealDataKey(DataKey::generate()),
                $now,
                costCenterId: $angaben->kostenstelleId,
            );

            // Building the PDF working copy needs an unlocked vault, so it
            // runs as a session job (issue #26/M4-4), never here where the
            // vault is locked (CLAUDE.md section 4).
            $this->jobs->enqueue(
                PdfErzeugung::JOB_TYP,
                JobExecutor::Session,
                'document',
                $documentId,
                now: $now,
            );

            $this->submissionUploads->deleteForFormHash($formHash);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        if ($angaben->email !== null) {
            $this->mailer->reiheEinreichungsbestaetigungEin($angaben->email, $referenz, $now);
        }
        $this->benachrichtigung?->senden($referenz, $documentId, $linkBasis, $now);
        $this->audit->record(AuditAction::EinreichungEingegangen, null, '', $documentId, [], $now);

        return SubmissionResult::erfolg($referenz);
    }

    /**
     * @param array<string, mixed> $eingabe
     * @return array{0: ?EinreichungsAngaben, 1: array<string, string>}
     */
    private function pruefeAngaben(array $eingabe): array
    {
        $fehler = [];

        $name = self::text($eingabe['name'] ?? null);
        if ($name === '') {
            $fehler['name'] = 'Bitte einen Namen angeben.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $fehler['name'] = 'Der Name ist zu lang.';
        }

        $email = self::text($eingabe['email'] ?? null);
        if ($email !== '' && (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $fehler['email'] = 'Das ist keine gültige E-Mail-Adresse.';
        }

        $erstattung = is_string($eingabe['erstattung'] ?? null) ? Erstattungsart::tryFrom($eingabe['erstattung']) : null;
        if ($erstattung === null) {
            $fehler['erstattung'] = 'Bitte eine Erstattungsart wählen.';
        }

        $iban = self::text($eingabe['iban'] ?? null);
        $kontoinhaber = self::text($eingabe['kontoinhaber'] ?? null);
        if ($erstattung === Erstattungsart::Ueberweisung) {
            if ($iban === '') {
                $fehler['iban'] = 'Bitte eine IBAN angeben.';
            } elseif (!Iban::istGueltig($iban)) {
                $fehler['iban'] = 'Das ist keine gültige IBAN.';
            }

            if ($kontoinhaber === '') {
                $fehler['kontoinhaber'] = 'Bitte den Namen des Kontoinhabers angeben.';
            } elseif (mb_strlen($kontoinhaber) > self::KONTOINHABER_MAX) {
                $fehler['kontoinhaber'] = 'Der Name ist zu lang.';
            }
        }

        $freitext = self::text($eingabe['freitext'] ?? null);
        if ($freitext === '') {
            $fehler['freitext'] = 'Bitte kurz beschreiben, worum es geht.';
        } elseif (mb_strlen($freitext) > self::FREITEXT_MAX) {
            $fehler['freitext'] = 'Der Text ist zu lang.';
        }

        $kostenstelleId = self::kostenstelleId($eingabe['kostenstelle'] ?? null);
        if ($kostenstelleId !== null && !array_key_exists($kostenstelleId, $this->kostenstellen->active())) {
            $fehler['kostenstelle'] = 'Unbekannte Mannschaft oder unbekannter Bereich.';
        }

        if (($eingabe['datenschutz'] ?? null) !== true) {
            $fehler['datenschutz'] = 'Bitte den Datenschutz-Hinweis bestätigen.';
        }

        if ($fehler !== []) {
            return [null, $fehler];
        }

        return [
            new EinreichungsAngaben(
                name: $name,
                email: $email === '' ? null : $email,
                erstattung: $erstattung,
                iban: $erstattung === Erstattungsart::Ueberweisung ? Iban::normalisieren($iban) : null,
                kontoinhaber: $erstattung === Erstattungsart::Ueberweisung ? $kontoinhaber : null,
                freitext: $freitext,
                kostenstelleId: $kostenstelleId,
            ),
            [],
        ];
    }

    /**
     * @param array<string, string> $fehler filled in by reference on a
     *        problem, the same shape App\Service\Account\PasswordChange uses
     *        for its list of messages
     * @return list<int>
     */
    private function pruefeSeiten(mixed $eingabe, string $formHash, array &$fehler): array
    {
        [$blobIds, $problem] = Seitenliste::lesen($eingabe, $this->einstellungen->maxSeiten);
        if ($problem !== null) {
            $fehler['seiten'] = $problem;

            return [];
        }

        // Every id must belong to THIS visit - otherwise a visitor could
        // attach a blob they merely guessed the id of, uploaded under a
        // different, unrelated token.
        $erlaubt = $this->submissionUploads->blobIdsForFormHash($formHash);
        if (array_diff($blobIds, $erlaubt) !== []) {
            $fehler['seiten'] = 'Eine Seite ist nicht mehr vorhanden. Bitte erneut hochladen.';

            return [];
        }

        if ($this->blobs->totalSize($blobIds) > $this->einstellungen->maxEinreichungBytes()) {
            $fehler['seiten'] = 'Die Einreichung ist insgesamt zu groß. Bitte weniger oder kleinere Seiten verwenden.';

            return [];
        }

        return $blobIds;
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
