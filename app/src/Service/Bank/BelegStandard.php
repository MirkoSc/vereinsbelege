<?php

declare(strict_types=1);

namespace App\Service\Bank;

use App\Domain\BankTransactionDirection;
use App\Repository\SettingRepository;

/**
 * Whether a new booking needs a receipt when nothing else says so (E-17,
 * M9-6, issue #64, docs/spec/04-bank-und-abgleich.md section 1): an expense
 * always does; income does not, unless the club switches on
 * `buchung_einnahme_beleg_noetig`. Used for new bookings (import, manual
 * "nach Richtung") and as the state a booking returns to when a rule is
 * taken back. Changing it never rewrites existing bookings.
 *
 * Same shape as App\Service\Export\ExportEinstellungen: anything in the
 * database but `1` is the default.
 */
final readonly class BelegStandard
{
    public const string SETTING_EINNAHME = 'buchung_einnahme_beleg_noetig';

    public function __construct(public bool $einnahmeBelegNoetig = false)
    {
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        return new self($settings->get(self::SETTING_EINNAHME) === '1');
    }

    public function speichern(SettingRepository $settings): void
    {
        $settings->set(self::SETTING_EINNAHME, $this->einnahmeBelegNoetig ? '1' : '0');
    }

    public function noetig(BankTransactionDirection $richtung): bool
    {
        return match ($richtung) {
            BankTransactionDirection::Ausgabe => true,
            BankTransactionDirection::Einnahme => $this->einnahmeBelegNoetig,
        };
    }
}
