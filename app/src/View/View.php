<?php

declare(strict_types=1);

namespace App\View;

/**
 * Minimal template renderer: plain PHP templates inside the shared layout.
 * Data keys become local variables in the template (EXTR_SKIP: the
 * reserved names $file and $data cannot be overridden).
 *
 * There is exactly one layout file. What differs between the public pages,
 * the user area and the admin area is chrome - navigation, content width,
 * whether a CSRF token travels with htmx - and all of that hangs off the
 * Area enum, so a new page picks its area instead of copying a layout.
 */
final class View
{
    /**
     * Set once per request by the Kernel, for aria-current in the
     * navigation. Not a constructor argument because the View is built in
     * bootstrap.php, before there is a Request; not readonly for the same
     * reason. One request = one process here, so there is nothing to share
     * it with by accident.
     */
    private string $currentPath = '/';

    /**
     * Who is logged in, and the token their forms need. Set once per
     * request by App\Http\LoginGuard, for the same reason $currentPath is
     * set by the Kernel: it belongs to the chrome, not to a page, and no
     * controller should have to remember to pass it.
     *
     * The display name is decrypted for this one request and lives nowhere
     * else - not in the session, which is on disk (docs/spec/01-sicherheit.md
     * section 2).
     */
    private ?string $angemeldet = null;

    private ?string $csrfToken = null;

    public function __construct(
        private readonly string $viewsDir,
        private readonly string $version,
        private readonly string $appName = 'Vereinsbelege',
    ) {
    }

    public function setCurrentPath(string $path): void
    {
        $this->currentPath = $path;
    }

    /**
     * @param string|null $anzeigename decrypted display name, or null when
     *                                 nobody is logged in
     */
    public function setAnmeldung(?string $anzeigename, ?string $csrfToken): void
    {
        $this->angemeldet = $anzeigename;
        $this->csrfToken = $csrfToken;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], Area $bereich = Area::Oeffentlich): string
    {
        // A page that renders nothing but static markup still gets the token
        // of the session it runs in - the logout button lives in the chrome,
        // and it is a form like any other.
        $data['csrf'] ??= $this->csrfToken;

        $content = $this->renderFile($this->viewsDir . '/' . $template . '.php', $data);

        return $this->renderFile(
            $this->viewsDir . '/layout.php',
            [
                ...$data,
                // After the spread, so a template cannot shadow the frame
                // it renders into.
                'angemeldet' => $this->angemeldet,
                'content' => $content,
                'bereich' => $bereich,
                'pfad' => $this->currentPath,
                'version' => $this->version,
                'appName' => $this->appName,
                'partialsDir' => $this->viewsDir . '/partials',
            ],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
