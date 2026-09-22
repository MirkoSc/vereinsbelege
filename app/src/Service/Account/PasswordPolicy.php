<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * Password rule from docs/spec/01-sicherheit.md section 3: minimum length,
 * checked against a list of common passwords, no composition rules (no
 * forced digit/symbol - that pushes people towards predictable patterns
 * instead of length).
 *
 * The list is App\Config\Paths::dataDir() . '/haeufige-passwoerter.txt', one
 * lowercase entry per line: a small own compilation (base words, keyboard
 * walks, German and English top picks, expanded with the digit/year suffixes
 * people actually append) rather than a verbatim, separately licensed breach
 * corpus (CLAUDE.md section 8). It ships in the release like any other
 * `app/data` file, no build step, no external service.
 *
 * Used by the installer (M3-2, the first admin) and will be reused by
 * password set/change/reset (M3-3, M3-5) - one rule, one place.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    /** @var list<string>|null lazy: only loaded once a password is actually checked */
    private ?array $commonPasswords = null;

    public function __construct(private readonly string $listFile)
    {
    }

    /**
     * @return list<string> German error messages, empty when the password is acceptable
     */
    public function violations(#[\SensitiveParameter] string $password): array
    {
        $errors = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MIN_LENGTH);
        }

        if (in_array(mb_strtolower($password), $this->commonPasswords(), true)) {
            $errors[] = 'Dieses Passwort ist zu bekannt und leicht zu erraten. Bitte ein anderes wählen.';
        }

        return $errors;
    }

    public function isAcceptable(#[\SensitiveParameter] string $password): bool
    {
        return $this->violations($password) === [];
    }

    /**
     * @return list<string>
     */
    private function commonPasswords(): array
    {
        if ($this->commonPasswords !== null) {
            return $this->commonPasswords;
        }

        if (!is_file($this->listFile)) {
            // The list is a defence in depth, not the only rule - a missing
            // file (broken release packaging) must not make every password
            // unrejectable-by-length-alone into an exception.
            return $this->commonPasswords = [];
        }

        $lines = file($this->listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $this->commonPasswords = $lines === false ? [] : $lines;
    }
}
