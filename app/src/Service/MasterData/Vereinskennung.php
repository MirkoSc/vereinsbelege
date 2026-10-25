<?php

declare(strict_types=1);

namespace App\Service\MasterData;

/**
 * The club's own VAT id and tax number (issue #245, E-67) - '' when the
 * club has none. Stored as typed; compared like a supplier key
 * (SupplierKeys::kennung() / ::steuernummer()). A pure value, no keys.
 */
final readonly class Vereinskennung
{
    public function __construct(
        public string $ustId = '',
        public string $steuernummer = '',
    ) {
    }

    /**
     * Checks the admin form: a VAT id like the supplier form, a tax number
     * with digits, at most SupplierService::TEXT_MAX characters. A tax
     * number is compared as written (its digits and letters): the local and
     * the 13-digit ELSTER form of the same number do not match - the page
     * says to enter the form the club's invoices print.
     *
     * @throws SupplierRuleViolation
     */
    public static function pruefen(string $ustId, string $steuernummer): self
    {
        $ustId = SupplierKeys::kennung($ustId);
        if ($ustId !== '' && preg_match(SupplierService::USTID_MUSTER, $ustId) !== 1) {
            throw new SupplierRuleViolation('Die USt-ID ist ungültig (Ländercode und bis zu 13 Zeichen, z. B. DE123456789).');
        }
        $steuernummer = trim((string) preg_replace('/\s+/u', ' ', $steuernummer));
        if ($steuernummer !== '' && preg_match('/[0-9]/', $steuernummer) !== 1) {
            throw new SupplierRuleViolation('Die Steuernummer enthält keine Ziffern.');
        }
        if (mb_strlen($steuernummer) > SupplierService::TEXT_MAX) {
            throw new SupplierRuleViolation(sprintf('Die Steuernummer ist länger als %d Zeichen.', SupplierService::TEXT_MAX));
        }

        return new self($ustId, $steuernummer);
    }

    /** Whether $wert (as a receipt states it) is the club's VAT id. */
    public function istUstId(string $wert): bool
    {
        $wert = SupplierKeys::kennung($wert);

        return $wert !== '' && $wert === SupplierKeys::kennung($this->ustId);
    }

    /** Whether $wert (as a receipt states it) is the club's tax number. */
    public function istSteuernummer(string $wert): bool
    {
        $wert = SupplierKeys::steuernummer($wert);

        return $wert !== '' && $wert === SupplierKeys::steuernummer($this->steuernummer);
    }

    /** @return array{ust_id: string, steuernummer: string} */
    public function alsDaten(): array
    {
        return ['ust_id' => $this->ustId, 'steuernummer' => $this->steuernummer];
    }
}
