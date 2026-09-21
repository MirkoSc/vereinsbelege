<?php

declare(strict_types=1);

namespace App\Service\SystemCheck;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';

    /** Worst status wins, so one failing probe cannot be averaged away. */
    public static function worst(self ...$stati): self
    {
        $worst = self::Ok;
        foreach ($stati as $status) {
            if ($status->rank() > $worst->rank()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warn => 1,
            self::Fail => 2,
        };
    }
}
