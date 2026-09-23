<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\BlobMeta;
use App\Http\Request;
use App\Http\Response;
use App\Service\Submission\FormToken;
use App\Service\Submission\SubmissionUploadStore;
use App\Service\Upload\MagicBytes;
use App\Service\Upload\UploadError;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;
use App\Support\FileLogger;

/**
 * The chunk upload for the public submission (docs/spec/03-erfassung-und-ki.md
 * section 4, issue #24/M4-2): the same four short requests as
 * App\Api\UploadController, under `/einreichen/upload/...`.
 *
 * `/einreichen` has no session by design (App\Http\Session's class docblock),
 * so this controller cannot reuse UploadController's session-CSRF guard. Its
 * credential is the stateless App\Service\Submission\FormToken instead,
 * carried in the same `X-CSRF-Token` header every request already sends
 * (public/js/upload.js); verified fresh on every request, nothing about it
 * is looked up. finish() additionally records which token uploaded the blob
 * (App\Service\Submission\SubmissionUploadStore), so App\Service\Submission\
 * SubmissionService can later refuse a blob id that belongs to a different
 * visit.
 */
final readonly class EinreichungUploadController
{
    private const string TOKEN_MESSAGE = 'Das Formular ist abgelaufen – bitte die Seite neu laden.';

    /** Long enough for a real file name, short enough to stay a file name. */
    private const int MAX_NAME_LENGTH = 200;

    /**
     * @param \Closure(): SubmissionUploadStore $store built lazily: it opens
     *        the database connection, which the chunk requests must not pay
     *        for.
     * @param (\Closure(): resource)|null $body the request body; defaults to
     *        php://input.
     */
    public function __construct(
        private FormToken $formToken,
        private UploadService $uploads,
        private \Closure $store,
        private ?\Closure $body = null,
        private ?FileLogger $logger = null,
    ) {
    }

    public function create(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $groesse = $request->post['groesse'] ?? null;
            if (!is_int($groesse) && !(is_string($groesse) && ctype_digit($groesse))) {
                return Response::json(['fehler' => 'Ungültige Dateigröße.'], 422);
            }

            return Response::json($this->uploads->create((int) $groesse)->toArray(), 201);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function chunk(Request $request, array $params): Response
    {
        return $this->guarded($request, function () use ($params): Response {
            $id = $params['id'];
            $index = (int) $params['n'];

            $oeffnen = $this->body ?? static fn() => fopen('php://input', 'rb');
            $stream = $oeffnen();
            if (!is_resource($stream)) {
                throw new \RuntimeException('Cannot read the request body.');
            }

            try {
                $this->uploads->writeChunk($id, $index, $stream);
            } finally {
                fclose($stream);
            }

            return Response::json(['chunk' => $index] + $this->uploads->status($id)->toArray());
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function finish(Request $request, array $params): Response
    {
        return $this->guarded($request, function () use ($request, $params): Response {
            $id = $params['id'];

            $status = $this->uploads->status($id);
            if (!$status->vollstaendig()) {
                return Response::json([
                    'fehler' => UploadError::Incomplete->message(),
                    'fehlend' => $status->fehlend,
                ], UploadError::Incomplete->status());
            }

            $typ = MagicBytes::detect($this->uploads->head($id));
            if ($typ === null) {
                $this->uploads->discard($id);

                throw new UploadException(UploadError::UnsupportedType);
            }

            $name = self::dateiname($request->post['name'] ?? '');
            $tokenData = $this->formToken->pruefen($request->header('x-csrf-token') ?? '');
            \assert($tokenData !== null); // guarded() already refused an invalid token.

            $blob = ($this->store)()->store(
                $this->uploads->chunks($id),
                new BlobMeta($typ, $name),
                $tokenData->hash(),
                new \DateTimeImmutable(),
            );
            $this->uploads->discard($id);

            return Response::json([
                'blob_id' => $blob->id,
                'groesse' => $blob->size,
                'typ' => $typ,
            ], 201);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function abort(Request $request, array $params): Response
    {
        return $this->guarded($request, function () use ($params): Response {
            $this->uploads->discard($params['id']);

            return Response::json(['status' => 'geloescht']);
        });
    }

    /**
     * Verifies the form token, then the JSON error contract for everything
     * the handler can throw - the same shape as
     * App\Api\UploadController::guarded(), CSRF/session replaced by the form
     * token.
     *
     * @param \Closure(): Response $handler
     */
    private function guarded(Request $request, \Closure $handler): Response
    {
        if ($this->formToken->pruefen($request->header('x-csrf-token') ?? '') === null) {
            return Response::json(['fehler' => self::TOKEN_MESSAGE], 403);
        }

        try {
            return $handler();
        } catch (UploadException $e) {
            return Response::json(['fehler' => $e->error->message()], $e->error->status());
        } catch (\Throwable $e) {
            // Class and route only - never the message, which could carry a
            // path or a file name (CLAUDE.md section 4).
            $this->logger?->append(sprintf('public upload failed: %s in %s', $e::class, $request->path));

            return Response::json(['fehler' => 'Der Upload ist fehlgeschlagen.'], 500);
        }
    }

    /**
     * The name the browser sent, reduced to something that is only ever
     * shown, never used as a path: no directories, no control characters, a
     * sane length. It is business data, so it is passed straight into the
     * encrypted blob metadata and nowhere else.
     */
    private static function dateiname(mixed $name): string
    {
        if (!is_string($name)) {
            return '';
        }

        $sauber = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name)));
        $sauber = trim($sauber ?? '');

        return mb_substr($sauber, 0, self::MAX_NAME_LENGTH);
    }
}
