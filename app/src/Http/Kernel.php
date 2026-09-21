<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\FileLogger;
use App\View\Area;
use App\View\View;

/**
 * Request -> Response pipeline: static assets first, then routing.
 */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly StaticFileHandler $staticFiles,
        private readonly View $view,
        private readonly bool $debug = false,
        private readonly ?FileLogger $logger = null,
    ) {
    }

    public function handle(Request $request): ResponseInterface
    {
        // The layout marks the current navigation entry with
        // aria-current="page"; this is the one place every response passes
        // through, so no controller has to remember to pass its own path.
        $this->view->setCurrentPath($request->path);

        try {
            return $this->staticFiles->tryServe($request) ?? $this->dispatch($request);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $request);
        }
    }

    private function dispatch(Request $request): ResponseInterface
    {
        $match = $this->router->match($request->method, $request->path);

        return match ($match->type) {
            MatchType::Matched => ($match->handler)($request, $match->params),
            MatchType::NotFound => Response::html(
                // Area::Oeffentlich: a 404 can hit any URL, including one
                // that never had a session, so its layout must not need one.
                $this->view->render('error', [
                    'title' => 'Seite nicht gefunden',
                    'message' => 'Die angeforderte Seite existiert nicht.',
                ], Area::Oeffentlich),
                404,
            ),
            MatchType::MethodNotAllowed => new Response(
                405,
                [
                    'Allow' => implode(', ', array_map(
                        static fn(HttpMethod $m): string => $m->value,
                        $match->allowedMethods,
                    )),
                    'Content-Type' => 'text/plain; charset=utf-8',
                ],
                'Methode nicht erlaubt',
            ),
        };
    }

    private function errorResponse(\Throwable $e, Request $request): Response
    {
        // Structured log WITHOUT the full exception string: a stack trace
        // carries function arguments when zend.exception_ignore_args is off,
        // and on this application that means the plaintext password from the
        // login path, an unwrapped vault key, or decrypted receipt data.
        // Bootstrap forces the ini setting on (issue #97) and the release
        // ships a .user.ini as a net before that; logging class, message and
        // request context only keeps the guarantee even if both were to fail.
        // PDO exception messages never contain bound values, so getMessage()
        // is safe here.
        $zeile = sprintf(
            '%s: %s [%s %s] at %s:%d',
            $e::class,
            $e->getMessage(),
            $request->method->value,
            $request->path,
            $e->getFile(),
            $e->getLine(),
        );

        error_log($zeile);
        // Additionally into shared/var/log/: error_log() goes to the
        // provider's PHP log, which is not reliably readable from the
        // customer panel and rotates on its own schedule. Same line, so the
        // "never the full exception string" guarantee holds for both.
        $this->logger?->append($zeile);

        // Full trace only in debug mode (dev), never in production.
        $body = $this->debug
            ? (string) $e
            : 'Interner Fehler – bitte versuchen Sie es später erneut.';

        return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }
}
