<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Domain\AuditAction;
use App\Domain\DocumentSource;
use App\Domain\Erstattungsart;
use App\Domain\Iban;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Repository\SubmissionRepository;
use App\Repository\SubmissionUploadRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Mail\Mailer;

/**
 * The rules of the public submission (docs/spec/03-erfassung-und-ki.md
 * section 1, issue #24/M4-2): validates the form, checks the uploaded pages
 * belong to this visit, then writes `submission` and `document` in one
 * transaction and hands back a reference number.
 *
 * Framework-free like the other Account/MasterData services: no Http, no
 * Session - the caller (App\PublicPages\EinreichungController) has already
 * verified the form token and resolved the vault.
 */
final readonly class SubmissionService
{
    /**
     * A page count high enough for a stack of receipts photographed one by
     * one, low enough to bound `document.original_blob_ids`. Real spam
     * defence (a setting, a smaller limit) is issue #25/M4-3 - this is a
     * sanity bound, not that control.
     */
    public const int MAX_SEITEN = 20;

    public const int NAME_MAX = 200;
    public const int EMAIL_MAX = 254;
    public const int KONTOINHABER_MAX = 200;
    public const int FREITEXT_MAX = 1000;

    /** Reference candidates tried before giving up (mirrors App\Service\Audit\AuditLog::MAX_ATTEMPTS). */
    private const int MAX_REFERENZ_VERSUCHE = 5;

    private const string SUBMISSION_TABLE = 'submission';
    private const string PAYLOAD_COLUMN = 'payload_enc';

    public function __construct(
        private \PDO $pdo,
        private SubmissionRepository $submissions,
        private DocumentRepository $documents,
        private SubmissionUploadRepository $submissionUploads,
        private CostCenterRepository $kostenstellen,
        private Mailer $mailer,
        private AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $eingabe the decoded JSON body
     */
    public function einreichen(array $eingabe, string $formHash, Vault $vault, ?\DateTimeImmutable $now = null): SubmissionResult
    {
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
            $id = $this->submissions->insertDraft($formHash, $now);

            $key = DataKey::generate();
            $payloadEnc = FieldCipher::encrypt(
                $key,
                json_encode($this->payload($angaben), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                new FieldContext(self::SUBMISSION_TABLE, $id, self::PAYLOAD_COLUMN),
            );
            $referenz = $this->reserviereReferenz($id, $vault->sealDataKey($key), $payloadEnc, $now);

            $documentId = $this->documents->insert(
                DocumentSource::Einreichung,
                $id,
                $blobIds,
                $vault->sealDataKey(DataKey::generate()),
                $now,
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
        if (!is_array($eingabe) || $eingabe === []) {
            $fehler['seiten'] = 'Bitte mindestens eine Seite hinzufügen.';

            return [];
        }

        if (count($eingabe) > self::MAX_SEITEN) {
            $fehler['seiten'] = 'Zu viele Seiten in einer Einreichung.';

            return [];
        }

        $blobIds = [];
        foreach ($eingabe as $wert) {
            $blobId = is_int($wert) ? $wert : (is_string($wert) && ctype_digit($wert) ? (int) $wert : null);
            if ($blobId === null || $blobId < 1) {
                $fehler['seiten'] = 'Eine Seite ist ungültig. Bitte erneut hochladen.';

                return [];
            }

            $blobIds[] = $blobId;
        }

        if (count(array_unique($blobIds)) !== count($blobIds)) {
            $fehler['seiten'] = 'Eine Seite wurde doppelt eingereicht.';

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

        return $blobIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EinreichungsAngaben $angaben): array
    {
        $erstattung = ['art' => $angaben->erstattung->value];
        if ($angaben->iban !== null) {
            $erstattung['iban'] = $angaben->iban;
        }
        if ($angaben->kontoinhaber !== null) {
            $erstattung['kontoinhaber'] = $angaben->kontoinhaber;
        }

        $payload = ['name' => $angaben->name, 'erstattung' => $erstattung, 'freitext' => $angaben->freitext];
        if ($angaben->email !== null) {
            $payload['email'] = $angaben->email;
        }
        if ($angaben->kostenstelleId !== null) {
            $payload['kostenstelle_hinweis'] = $angaben->kostenstelleId;
        }

        return $payload;
    }

    /**
     * Retries the reference candidate on a collision - the same shape as
     * App\Service\Audit\AuditLog::record(), just an UPDATE instead of an
     * INSERT: the row already exists (from insertDraft()), only its
     * reference_code needs a value nobody else has taken yet. A duplicate
     * key error does not abort the surrounding transaction on InnoDB, so the
     * next candidate is simply tried within the same one.
     */
    private function reserviereReferenz(int $id, string $dekSealed, string $payloadEnc, \DateTimeImmutable $now): string
    {
        $jahr = (int) $now->format('Y');

        for ($versuch = 1; ; $versuch++) {
            $referenz = sprintf('R-%d-%04d', $jahr, $this->submissions->hoechsteLaufnummer($jahr) + 1);

            try {
                $this->submissions->complete($id, $referenz, $dekSealed, $payloadEnc);

                return $referenz;
            } catch (\PDOException $e) {
                if ($versuch >= self::MAX_REFERENZ_VERSUCHE || !self::istDuplicateKey($e)) {
                    throw $e;
                }
            }
        }
    }

    private static function istDuplicateKey(\PDOException $e): bool
    {
        return $e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062;
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
