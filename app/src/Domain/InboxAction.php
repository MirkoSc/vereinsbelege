<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a person with `document.edit` can do to a document in the inbox
 * (issue #27/M4-5, docs/spec/02-datenmodell.md "Statusmodell"). Each action
 * leads to one status and is offered only from the statuses listed in
 * vonStatus() - every one of them a transition DocumentStatus::uebergaenge()
 * allows (tests/Domain/InboxActionTest.php keeps the two in step).
 */
enum InboxAction: string
{
    case Annehmen = 'annehmen';
    case Ablehnen = 'ablehnen';
    case Wiedervorlage = 'wiedervorlage';

    public function zielStatus(): DocumentStatus
    {
        return match ($this) {
            self::Annehmen => DocumentStatus::BereitZurAuswertung,
            self::Ablehnen => DocumentStatus::Abgelehnt,
            self::Wiedervorlage => DocumentStatus::Wiedervorlage,
        };
    }

    /**
     * @return list<DocumentStatus>
     */
    public function vonStatus(): array
    {
        return match ($this) {
            self::Annehmen => [DocumentStatus::Eingegangen, DocumentStatus::Wiedervorlage],
            self::Ablehnen => [DocumentStatus::Eingegangen, DocumentStatus::Wiedervorlage, DocumentStatus::InPruefung],
            self::Wiedervorlage => [DocumentStatus::Eingegangen, DocumentStatus::Ausgewertet, DocumentStatus::KiFehler],
        };
    }

    public function erlaubtAus(DocumentStatus $status): bool
    {
        return in_array($status, $this->vonStatus(), true);
    }

    public function auditAction(): AuditAction
    {
        return match ($this) {
            self::Annehmen => AuditAction::BelegAngenommen,
            self::Ablehnen => AuditAction::BelegAbgelehnt,
            self::Wiedervorlage => AuditAction::BelegWiedervorlage,
        };
    }

    /**
     * @return list<self> the actions offered for a document in this status
     */
    public static function fuer(DocumentStatus $status): array
    {
        return array_values(array_filter(self::cases(), static fn(self $a): bool => $a->erlaubtAus($status)));
    }
}
