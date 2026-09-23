<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\BlobMeta;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\SubmissionUploadRepository;
use App\Service\Submission\InterneErfassung;
use App\Service\Upload\MagicBytes;
use App\Service\Upload\UploadError;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;
use App\Service\Upload\UploadStore;
use App\Support\FileLogger;

/**
 * The chunk upload (docs/spec/03-erfassung-und-ki.md section 4). Four short
 * requests instead of one long one:
 *
 *   POST /api/upload                     {groesse}  -> {id, chunks, chunk_bytes}
 *   POST /api/upload/{id}/chunk/{n}      raw body   -> {chunk, fehlend, ...}
 *   POST /api/upload/{id}/finish         {name}     -> {blob_id, groesse, typ}
 *   POST /api/upload/{id}/abort                     -> {status}
 *
 * The path segments are English because the spec fixes the chunk route; the
 * texts the browser shows are German like everywhere else.
 *
 * ---------------------------------------------------------------------
 * Rights: `document.submit_internal` (M3-6, issue #19), declared on the
 * routes and checked by App\Http\LoginGuard (app/src/routes.php), which
 * answers 401/403 JSON rather than redirecting - these endpoints are driven
 * from fetch(), where a login page would arrive as garbage. The CSRF token
 * is checked on top of that.
 *
 * The public submission (/einreichen, issue #24/M4-2) has no session by
 * design and therefore cannot use this route as it stands: it has its own
 * chunk upload under App\Api\EinreichungUploadController, credentialed by
 * App\Service\Submission\FormToken instead of session CSRF.
 *
 * The internal capture `/app/belege/neu` (issue #28/M4-6) sends its capture
 * id in `X-Erfassung` on every request; finish() then also records the blob
 * in `submission_upload` under App\Service\Submission\InterneErfassung::
 * uploadHash() - bound to the account and that page load - so only this
 * page can later attach it to a receipt, and the cron deletes it after 24 h
 * if nothing does. Without the header nothing changes.
 * ---------------------------------------------------------------------
 *
 * The request body of a chunk is raw bytes, which App\Http\Request does not
 * expose - it only decodes JSON. Rather than widen that ported class, the
 * stream is injected as a closure, the same way InstallController injects
 * is_uploaded_file() so a test can drive it.
 */
final readonly class UploadController
{
    private const string CSRF_MESSAGE = 'Sitzung abgelaufen – bitte die Seite neu laden.';

    /** Long enough for a real file name, short enough to stay a file name. */
    private const int MAX_NAME_LENGTH = 200;

    /**
     * @param \Closure(): UploadStore $store built lazily: it opens the
     *        database connection, which the chunk requests must not pay for.
     * @param (\Closure(): SubmissionUploadRepository)|null $vermerke built
     *        lazily, same reason - where finish() records an internal
     *        capture's blob (issue #28/M4-6).
     * @param (\Closure(): resource)|null $body the request body; defaults to
     *        php://input.
     */
    public function __construct(
        private Session $session,
        private UploadService $uploads,
        private \Closure $store,
        private ?\Closure $body = null,
        private ?FileLogger $logger = null,
        private ?\Closure $vermerke = null,
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
     * Checks the file and hands it to the storage layer, encrypted, in one
     * pass - the plaintext never becomes a file of its own.
     *
     * @param array<string, string> $params
     */
    public function finish(Request $request, array $params): Response
    {
        return $this->guarded($request, function () use ($request, $params): Response {
            $id = $params['id'];

            $erfassung = $request->header('x-erfassung');
            if ($erfassung !== null && ($this->vermerke === null || !InterneErfassung::istErfassungsId($erfassung))) {
                return Response::json(['fehler' => 'Die Seite ist abgelaufen – bitte neu laden.'], 422);
            }

            $status = $this->uploads->status($id);
            if (!$status->vollstaendig()) {
                return Response::json([
                    'fehler' => UploadError::Incomplete->message(),
                    'fehlend' => $status->fehlend,
                ], UploadError::Incomplete->status());
            }

            $typ = MagicBytes::detect($this->uploads->head($id));
            if ($typ === null) {
                // Nothing will come of this upload, so it goes now instead of
                // waiting 24 h for the cron.
                $this->uploads->discard($id);

                throw new UploadException(UploadError::UnsupportedType);
            }

            $name = self::dateiname($request->post['name'] ?? '');
            $blob = ($this->store)()->store($this->uploads->chunks($id), new BlobMeta($typ, $name));
            $this->uploads->discard($id);

            $userId = $this->session->userId();
            if ($erfassung !== null && $this->vermerke !== null && $userId !== null) {
                ($this->vermerke)()->record($blob->id, InterneErfassung::uploadHash($userId, $erfassung), new \DateTimeImmutable());
            }

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
     * CSRF check, then the JSON error contract for everything the handler can
     * throw. The kernel would answer a plain text 500, which no fetch() can
     * make sense of - and a refused upload is an everyday case, not a bug.
     *
     * @param \Closure(): Response $handler
     */
    private function guarded(Request $request, \Closure $handler): Response
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => self::CSRF_MESSAGE], 403);
        }

        try {
            return $handler();
        } catch (UploadException $e) {
            return Response::json(['fehler' => $e->error->message()], $e->error->status());
        } catch (\Throwable $e) {
            // Class and route only - never the message, which could carry a
            // path or a file name (CLAUDE.md section 4).
            $this->logger?->append(sprintf('upload failed: %s in %s', $e::class, $request->path));

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
