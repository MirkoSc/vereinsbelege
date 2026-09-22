<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Repository\SettingRepository;

/**
 * How long a logged-in session lives (docs/spec/01-sicherheit.md section 2):
 * 30 minutes idle, 12 hours absolute.
 *
 * Both are settings rather than constants because an installation has a say:
 * a treasurer working through a stack of receipts on a Sunday afternoon and
 * an external auditor with a 60-day account want different numbers. The
 * admin page for them is a later milestone; until then the rows simply are
 * not there and the defaults apply.
 *
 * An unusable value in the database (empty, zero, negative, not a number)
 * falls back to the default instead of producing a session that never
 * expires - a timeout setting must not be able to switch the timeout off.
 */
final readonly class SessionTimeouts
{
    public const int IDLE_DEFAULT = 1800;

    public const int ABSOLUTE_DEFAULT = 43200;

    public const string SETTING_IDLE = 'session_idle_timeout_s';

    public const string SETTING_ABSOLUTE = 'session_absolute_timeout_s';

    public function __construct(
        public int $idleSeconds = self::IDLE_DEFAULT,
        public int $absoluteSeconds = self::ABSOLUTE_DEFAULT,
    ) {
    }

    public static function fromSettings(SettingRepository $settings): self
    {
        return new self(
            self::positive($settings->get(self::SETTING_IDLE), self::IDLE_DEFAULT),
            self::positive($settings->get(self::SETTING_ABSOLUTE), self::ABSOLUTE_DEFAULT),
        );
    }

    private static function positive(string $value, int $default): int
    {
        $sekunden = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($sekunden) && $sekunden > 0 ? $sekunden : $default;
    }
}
