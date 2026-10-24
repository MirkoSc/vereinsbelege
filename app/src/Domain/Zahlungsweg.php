<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a receipt is settled (`invoice.payment_kind`, M10-1, issue #65,
 * docs/spec/04-bank-und-abgleich.md section 5 "Modell", E-27). Plaintext
 * structure chosen in the review page; it decides whether the matching
 * expects a booking for the receipt and which words the derived
 * App\Domain\PaymentStatus uses.
 */
enum Zahlungsweg: string
{
    /** A booking on one of the club's accounts - bank or cash box - settles the receipt. The default. */
    case Konto = 'konto';

    /** The submitter paid out of pocket; the club reimburses by transfer (M10-3). */
    case Erstattung = 'erstattung';

    /** Paid in cash without a cash-book entry: the receipt is the proof, nothing to match. */
    case Bar = 'bar';

    /** No payment expected: a credit note netted against an invoice, a document for information. */
    case Keine = 'keine';

    public function label(): string
    {
        return match ($this) {
            self::Konto => 'über Konto oder Kasse gebucht',
            self::Erstattung => 'Erstattung an die einreichende Person',
            self::Bar => 'bar bezahlt (ohne Kassenbuchung)',
            self::Keine => 'kein Zahlungsbezug',
        };
    }

    /** Whether the matching looks for bookings - only then may a receipt have allocations. */
    public function erwartetBuchung(): bool
    {
        return match ($this) {
            self::Konto, self::Erstattung => true,
            self::Bar, self::Keine => false,
        };
    }

    /**
     * What a submission's wish about the money means for the receipt
     * (App\Domain\Erstattungsart, docs/spec/03-erfassung-und-ki.md section 1):
     * "bitte überweisen" is a reimbursement to match, "bar erhalten" was
     * settled from the cash box by hand, "bereits vom Verein bezahlt" means a
     * booking of the club exists.
     */
    public static function ausErstattungsart(Erstattungsart $art): self
    {
        return match ($art) {
            Erstattungsart::Ueberweisung => self::Erstattung,
            Erstattungsart::Bar => self::Bar,
            Erstattungsart::Keine => self::Konto,
        };
    }
}
