<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DocumentStatus;
use App\Domain\InboxAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The status model of `document.status` (docs/spec/02-datenmodell.md
 * "Statusmodell", issue #27/M4-5) as one full matrix: every pair of statuses
 * is either an allowed transition or not, and nothing in between.
 */
final class DocumentStatusTest extends TestCase
{
    /** The diagram of the spec, written out once more by hand. */
    private const array ERLAUBT = [
        'eingegangen' => ['bereit_zur_auswertung', 'wiedervorlage', 'abgelehnt'],
        'bereit_zur_auswertung' => ['ausgewertet', 'ki_fehler'],
        'ausgewertet' => ['in_pruefung', 'ki_fehler', 'wiedervorlage'],
        'in_pruefung' => ['geprueft', 'abgelehnt'],
        'geprueft' => ['festgeschrieben'],
        'festgeschrieben' => [],
        'ki_fehler' => ['bereit_zur_auswertung', 'wiedervorlage'],
        'wiedervorlage' => ['bereit_zur_auswertung', 'abgelehnt'],
        'abgelehnt' => [],
    ];

    /**
     * @return iterable<string, array{DocumentStatus, DocumentStatus, bool}>
     */
    public static function paare(): iterable
    {
        foreach (DocumentStatus::cases() as $von) {
            foreach (DocumentStatus::cases() as $nach) {
                yield $von->value . ' -> ' . $nach->value => [$von, $nach, in_array($nach->value, self::ERLAUBT[$von->value], true)];
            }
        }
    }

    #[DataProvider('paare')]
    public function testEveryPairIsAllowedExactlyAsTheDiagramSays(DocumentStatus $von, DocumentStatus $nach, bool $erlaubt): void
    {
        self::assertSame($erlaubt, $von->kannWechselnZu($nach));
    }

    public function testTheMatrixCoversEveryStatus(): void
    {
        self::assertSame(
            array_map(static fn(DocumentStatus $s): string => $s->value, DocumentStatus::cases()),
            array_keys(self::ERLAUBT),
        );
    }

    public function testNoStatusChangesToItself(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            self::assertFalse($status->kannWechselnZu($status), $status->value);
        }
    }

    public function testFixedAndRejectedAreFinal(): void
    {
        self::assertTrue(DocumentStatus::Festgeschrieben->istEndzustand());
        self::assertTrue(DocumentStatus::Abgelehnt->istEndzustand());
        self::assertFalse(DocumentStatus::Eingegangen->istEndzustand());
        self::assertFalse(DocumentStatus::Wiedervorlage->istEndzustand());
    }

    public function testEveryStatusHasAGermanLabel(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            self::assertNotSame('', $status->bezeichnung());
        }
        self::assertSame('KI fehlgeschlagen', DocumentStatus::KiFehler->bezeichnung());
    }

    /**
     * The inbox never offers a change the status model forbids.
     */
    public function testEveryInboxActionIsATransitionOfTheModel(): void
    {
        foreach (InboxAction::cases() as $aktion) {
            self::assertNotSame([], $aktion->vonStatus(), $aktion->value);
            foreach ($aktion->vonStatus() as $von) {
                self::assertTrue($von->kannWechselnZu($aktion->zielStatus()), $aktion->value . ' from ' . $von->value);
            }
        }
    }

    public function testTheInboxActionsAsAgreedForIssue27(): void
    {
        self::assertSame(DocumentStatus::BereitZurAuswertung, InboxAction::Annehmen->zielStatus());
        self::assertSame(DocumentStatus::Abgelehnt, InboxAction::Ablehnen->zielStatus());
        self::assertSame(DocumentStatus::Wiedervorlage, InboxAction::Wiedervorlage->zielStatus());

        self::assertSame([InboxAction::Annehmen, InboxAction::Ablehnen, InboxAction::Wiedervorlage], InboxAction::fuer(DocumentStatus::Eingegangen));
        self::assertSame([InboxAction::Annehmen, InboxAction::Ablehnen], InboxAction::fuer(DocumentStatus::Wiedervorlage));
        self::assertSame([InboxAction::Ablehnen], InboxAction::fuer(DocumentStatus::InPruefung));
        self::assertSame([InboxAction::Wiedervorlage], InboxAction::fuer(DocumentStatus::Ausgewertet));
        self::assertSame([InboxAction::Wiedervorlage], InboxAction::fuer(DocumentStatus::KiFehler));
        self::assertSame([], InboxAction::fuer(DocumentStatus::Abgelehnt));
        self::assertSame([], InboxAction::fuer(DocumentStatus::Festgeschrieben));
        self::assertSame([], InboxAction::fuer(DocumentStatus::BereitZurAuswertung));
    }
}
