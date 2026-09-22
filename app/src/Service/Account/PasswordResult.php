<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * The outcome of a password change or reset (App\Service\Account\
 * PasswordChange, App\Service\Account\PasswordReset, issue #18/M3-5).
 *
 * Either a list of German messages for the form, or success plus the
 * account's new `session_epoch` - which the one session that changed the
 * password adopts so that it stays logged in while every other one ends
 * (App\Http\Session::adoptEpoch()).
 */
final readonly class PasswordResult
{
    /**
     * @param list<string> $fehler
     */
    private function __construct(
        public array $fehler,
        public ?int $userId,
        public ?int $sessionEpoch,
    ) {
    }

    public static function erfolg(int $userId, int $sessionEpoch): self
    {
        return new self([], $userId, $sessionEpoch);
    }

    public static function fehler(string ...$fehler): self
    {
        return new self(array_values($fehler), null, null);
    }

    public function istErfolg(): bool
    {
        return $this->fehler === [] && $this->sessionEpoch !== null;
    }
}
