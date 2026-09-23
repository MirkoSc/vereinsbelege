<?php

declare(strict_types=1);

namespace App\Service\Inbox;

use App\Domain\AuditAction;
use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\Document;
use App\Domain\DocumentStatus;
use App\Domain\InboxAction;
use App\Domain\InboxItem;
use App\Domain\Zugriffsbereich;
use App\Repository\CostCenterRepository;
use App\Repository\DocumentRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Storage\BlobService;
use App\Service\Upload\MagicBytes;

/**
 * The inbox (issue #27/M4-5, docs/spec/02-datenmodell.md "Statusmodell"):
 * lists what came in, opens a submission for reading, streams its pages and
 * applies the three decisions - accept, reject with a reason, put on
 * Wiedervorlage - plus the cost center.
 *
 * Framework-free like the other services: no Http, no Session. The caller
 * (App\App\InboxController) resolves the viewer's scope
 * (App\Domain\Zugriffsbereich) and the vault; every read here goes through
 * the scope in SQL, so an entry outside it does not exist for this class.
 *
 * Nothing decrypted leaves through an exception message, the audit log's
 * plaintext columns or a file: notes are FieldCipher under the document's
 * DEK, audit details are sealed to the vault by AuditLog, pages stream from
 * BlobService::openRead() straight into the response.
 */
final readonly class Posteingang
{
    /** Rows of the list; one more is read to know whether there are more. */
    public const int SEITE = 100;

    /** With a search the PHP-side match needs a wider SQL window. */
    public const int SUCHFENSTER = 1000;

    public const int NOTIZ_MAX = 1000;

    private const string TABLE_SUBMISSION = 'submission';
    private const string COLUMN_PAYLOAD = 'payload_enc';
    private const string TABLE_DOCUMENT = 'document';
    private const string COLUMN_NOTE = 'status_note_enc';

    /** The page types a preview may be sent as (docs/spec/03-erfassung-und-ki.md section 4). */
    private const array ENDUNG = [
        MagicBytes::JPEG => 'jpg',
        MagicBytes::PNG => 'png',
        MagicBytes::PDF => 'pdf',
    ];

    public function __construct(
        private DocumentRepository $documents,
        private CostCenterRepository $kostenstellen,
        private BlobService $blobs,
        private AuditLog $audit,
    ) {
    }

    /**
     * @return array{0: list<InboxEintrag>, 1: bool} the entries and whether
     *         the list was cut off
     */
    public function liste(InboxFilter $filter, Zugriffsbereich $bereich, ?Vault $vault, \DateTimeImmutable $heute): array
    {
        $suche = $vault === null ? '' : $filter->suche;
        $fenster = $suche === '' ? self::SEITE + 1 : self::SUCHFENSTER + 1;
        $items = $this->documents->inbox($filter, $bereich, $heute, $fenster);
        $abgeschnitten = count($items) >= $fenster;

        $eintraege = [];
        foreach ($items as $item) {
            $daten = $vault === null ? null : $this->daten($item, $vault);
            if ($suche !== '' && ($daten === null || !$daten->passtZu($suche))) {
                continue;
            }
            $eintraege[] = new InboxEintrag($item, $daten, null);
            if (count($eintraege) > self::SEITE) {
                $abgeschnitten = true;
                break;
            }
        }

        return [array_slice($eintraege, 0, self::SEITE), $abgeschnitten];
    }

    /**
     * One entry, opened as far as the vault allows - null when it does not
     * exist or lies outside $bereich.
     */
    public function eintrag(int $id, Zugriffsbereich $bereich, ?Vault $vault): ?InboxEintrag
    {
        $item = $this->documents->inboxItem($id, $bereich);
        if ($item === null) {
            return null;
        }

        return new InboxEintrag(
            $item,
            $vault === null ? null : $this->daten($item, $vault),
            $vault === null ? null : $this->notiz($item->document, $vault),
        );
    }

    /**
     * The pages of a document for the preview, in upload order, then the
     * working PDF if it is a file of its own.
     *
     * @return list<array{blobId: int, mime: string, seite: int, pdf: bool}>
     */
    public function seiten(Document $document, Vault $vault): array
    {
        $seiten = [];
        foreach ($document->blobIds() as $nummer => $blobId) {
            $blob = $this->blobs->find($blobId);
            $meta = $blob === null ? null : $this->meta($blob, $vault);
            if ($meta === null) {
                continue;
            }
            $seiten[] = [
                'blobId' => $blobId,
                'mime' => $meta->mimeType,
                'seite' => $nummer + 1,
                'pdf' => $blobId === $document->pdfBlobId && !in_array($blobId, $document->originalBlobIds, true),
            ];
        }

        return $seiten;
    }

    /**
     * One page of a document, ready to stream: the decrypted pieces, the
     * type and a file name made of the reference number only (no original
     * name - that is club data, CLAUDE.md section 4). Null when the blob is
     * not one of this document's, not finished, or of a type the preview
     * does not send.
     *
     * @return array{chunks: \Generator<string>, mime: string, dateiname: string}|null
     */
    public function datei(InboxItem $item, int $blobId, Vault $vault): ?array
    {
        $position = array_search($blobId, $item->document->blobIds(), true);
        if ($position === false) {
            return null;
        }

        $blob = $this->blobs->find($blobId);
        if ($blob === null || !$blob->isComplete()) {
            return null;
        }
        $meta = $this->meta($blob, $vault);
        $endung = $meta === null ? null : (self::ENDUNG[$meta->mimeType] ?? null);
        if ($endung === null) {
            return null;
        }

        $name = $item->referenz ?? ('Beleg-' . $item->document->id);

        return [
            'chunks' => $this->blobs->openRead($blob, $vault),
            'mime' => $meta->mimeType,
            'dateiname' => sprintf('%s-%d.%s', $name, $position + 1, $endung),
        ];
    }

    /**
     * Accept, reject or put on Wiedervorlage.
     *
     * @param string $text the reason (Ablehnen, required) or the note
     *        (Wiedervorlage, optional); ignored for Annehmen
     * @param \DateTimeImmutable|null $datum the Wiedervorlage date
     *        (required there, today or later)
     * @throws InboxRuleViolation
     */
    public function entscheiden(
        InboxAction $aktion,
        Document $document,
        Vault $vault,
        string $text,
        ?\DateTimeImmutable $datum,
        ?int $userId,
        string $ip,
        \DateTimeImmutable $now,
    ): void {
        $ziel = $aktion->zielStatus();
        if (!$aktion->erlaubtAus($document->status) || !$document->status->kannWechselnZu($ziel)) {
            throw new InboxRuleViolation('Dieser Statuswechsel ist für den Beleg nicht möglich.');
        }

        $text = trim($text);
        if (mb_strlen($text) > self::NOTIZ_MAX) {
            throw new InboxRuleViolation(sprintf('Der Text ist zu lang (höchstens %d Zeichen).', self::NOTIZ_MAX));
        }

        $details = ['von' => $document->status->value];
        $resubmitOn = null;
        $notiz = null;

        switch ($aktion) {
            case InboxAction::Annehmen:
                break;
            case InboxAction::Ablehnen:
                if ($text === '') {
                    throw new InboxRuleViolation('Bitte einen Grund für die Ablehnung angeben.');
                }
                $notiz = $text;
                $details['grund'] = $text;
                break;
            case InboxAction::Wiedervorlage:
                if ($datum === null) {
                    throw new InboxRuleViolation('Bitte ein Datum für die Wiedervorlage angeben.');
                }
                if ($datum->format('Y-m-d') < $now->format('Y-m-d')) {
                    throw new InboxRuleViolation('Das Datum der Wiedervorlage darf nicht in der Vergangenheit liegen.');
                }
                $resubmitOn = $datum;
                $notiz = $text === '' ? null : $text;
                $details['am'] = $datum->format('Y-m-d');
                if ($notiz !== null) {
                    $details['notiz'] = $notiz;
                }
                break;
        }

        $notizEnc = $notiz === null ? null : FieldCipher::encrypt(
            $vault->openDataKey($document->dekSealed),
            $notiz,
            new FieldContext(self::TABLE_DOCUMENT, $document->id, self::COLUMN_NOTE),
        );

        if (!$this->documents->wechsleStatus($document->id, $document->status, $ziel, $notizEnc, $resubmitOn, $userId, $now)) {
            throw new InboxRuleViolation('Der Beleg wurde inzwischen anders bearbeitet. Bitte die Seite neu laden.');
        }

        $this->audit->record($aktion->auditAction(), $userId, $ip, $document->id, $details, $now);
    }

    /**
     * Sets or clears the cost center. An inactive one is accepted only if
     * the document already carries it (saving the form unchanged).
     *
     * @throws InboxRuleViolation
     */
    public function kostenstelle(Document $document, ?int $kostenstelleId, ?int $userId, string $ip, \DateTimeImmutable $now): bool
    {
        if ($document->status === DocumentStatus::Festgeschrieben) {
            throw new InboxRuleViolation('Ein festgeschriebener Beleg kann nicht mehr geändert werden.');
        }
        if ($kostenstelleId === $document->costCenterId) {
            return false;
        }

        $name = null;
        if ($kostenstelleId !== null) {
            $name = $this->kostenstellen->active()[$kostenstelleId] ?? null;
            if ($name === null) {
                throw new InboxRuleViolation('Unbekannte oder deaktivierte Kostenstelle.');
            }
        }

        $this->documents->setzeKostenstelle($document->id, $kostenstelleId);
        $this->audit->record(AuditAction::BelegKostenstelle, $userId, $ip, $document->id, ['kostenstelle' => $name], $now);

        return true;
    }

    private function daten(InboxItem $item, Vault $vault): ?EinreichungsDaten
    {
        if ($item->document->submissionId === null || $item->submissionDekSealed === null || $item->payloadEnc === null) {
            return null;
        }

        try {
            $json = FieldCipher::decrypt(
                $vault->openDataKey($item->submissionDekSealed),
                $item->payloadEnc,
                new FieldContext(self::TABLE_SUBMISSION, $item->document->submissionId, self::COLUMN_PAYLOAD),
            );
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (CryptoException | \JsonException) {
            // Sealed to an older vault version or damaged - the entry still
            // shows with its plaintext columns.
            return null;
        }

        return is_array($payload) ? EinreichungsDaten::fromPayload($payload) : null;
    }

    private function notiz(Document $document, Vault $vault): ?string
    {
        if ($document->statusNoteEnc === null) {
            return null;
        }

        try {
            return FieldCipher::decrypt(
                $vault->openDataKey($document->dekSealed),
                $document->statusNoteEnc,
                new FieldContext(self::TABLE_DOCUMENT, $document->id, self::COLUMN_NOTE),
            );
        } catch (CryptoException) {
            return null;
        }
    }

    private function meta(Blob $blob, Vault $vault): ?BlobMeta
    {
        try {
            return $this->blobs->meta($blob, $vault);
        } catch (CryptoException) {
            return null;
        }
    }
}
