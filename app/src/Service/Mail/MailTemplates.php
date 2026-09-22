<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Renders a plain-text mail body from app/views/mail/<name>.php.
 *
 * Deliberately separate from App\View\View, which always wraps its templates
 * in the HTML layout (navigation, CSS) - a mail body is neither HTML nor
 * part of any Area.
 *
 * Templates carry NO business content (CLAUDE.md section 4, docs/spec/
 * 06-betrieb.md section 3: "keine fachlichen Inhalte... nur Hinweise mit
 * Link") - only technical or procedural text.
 */
final readonly class MailTemplates
{
    public function __construct(private string $viewsDir)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $this->viewsDir . '/' . $template . '.php';

        return trim((string) ob_get_clean());
    }
}
