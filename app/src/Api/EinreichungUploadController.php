<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\BlobMeta;
use App\Http\Request;
use App\Http\Response;
use App\Service\Submission\FormToken;
use App\Service\Submission\Spamschutz;
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
 *
 * create() is also where App\Service\Submission\Spamschutz is checked
 * (issue #25/M4-3, docs/spec/01-sicherheit.md section 5): proof of work
 * (`X-Pow-Loesung`, the same header on every request the page sends,
 * public/js/upload.js), the per-file size limit, the rate limit and the
 * byte budget (App\Service\Submission\UploadBudget, issue #242). Every
 * accepted call counts against the budget immediately - chunk(), finish()
 * and abort() only continue an upload create() already let through, so they
 * check nothing but the token - except that finish() also honours the pause
 * (issue #78), since it is the step that stores the blob.
 */
final readonly class EinreichungUploadController
{
    private const string TOKEN_MESSAGE = 'Das Formular ist abgelaufen – bitte die Seite neu laden.';

    /** Long enough for a real file name, short enough to stay a file name. */
    private const int MAX_NAME_LENGTH = 200;

    /**
     * @param \Closure(): Spamschutz $spamschutz built lazily: it opens the
     *        database connection (the rate limit, the byte budget), which
     *        only create(), finish() and abort() must pay for.
     * @param \Closure(): SubmissionUploadStore $store built lazily: it opens
     *        the database connection, which the chunk requests must not pay
     *        for.
     * @param (\Closure(): resource)|null $body the request body; defaults to
     *        php://input.
     */
    public function __construct(
        private FormToken $formToken,
        private UploadService $uploads,
        private \Closure $spamschutz,
        private \Closure $store,
        private ?\Closure $body = null,
        private ?FileLogger $logger = null,
    ) {
    }

    public function create(Request $request): Response
    {
        $tokenData = $this->formToken->pruefen($request->header('x-csrf-token') ?? '');
        if ($tokenData === null) {
            return Response::json(['fehler' => self::TOKEN_MESSAGE], 403);
        }

        $groesse = $request->post['groesse'] ?? null;
        if (!is_int($groesse) && !(is_string($groesse) && ctype_digit($groesse))) {
            return Response::json(['fehler' => 'Ungültige Dateigröße.'], 422);
        }

        // Known before the upload exists: the byte budget reserves under it
        // and gives the reservation back by it (issue #242).
        $id = UploadService::neueId();
        $spamschutz = ($this->spamschutz)();
        $abgelehnt = $spamschutz->pruefeUpload(
            $request->ip,
            $tokenData,
            $request->header('x-pow-loesung'),
            (int) $groesse,
            $id,
        );
        if ($abgelehnt !== null) {
            return Response::json(['fehler' => $abgelehnt->message()], $abgelehnt->status());
        }

        return $this->ausgefuehrt($request, function () use ($groesse, $id, $spamschutz): Response {
            try {
                return Response::json($this->uploads->create((int) $groesse, id: $id)->toArray(), 201);
            } catch (\Throwable $e) {
                $spamschutz->uploadBeendet($id);

                throw $e;
            }
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

            // Paused in the meantime: an upload opened before the pause must
            // not become a stored blob after it (issue #78). Chunks before
            // this only reach var/tmp, which the cleanup empties anyway. The
            // same holds while free space is low (issue #242).
            $spamschutz = ($this->spamschutz)();
            $pausiert = $spamschutz->pruefePause();
            if ($pausiert !== null) {
                $this->verwerfen($spamschutz, $id);

                return Response::json(['fehler' => $pausiert->message()], $pausiert->status());
            }

            $status = $this->uploads->status($id);
            if (!$status->vollstaendig()) {
                return Response::json([
                    'fehler' => UploadError::Incomplete->message(),
                    'fehlend' => $status->fehlend,
                ], UploadError::Incomplete->status());
            }

            $name = self::dateiname($request->post['name'] ?? '');
            $tokenData = $this->formToken->pruefen($request->header('x-csrf-token') ?? '');
            \assert($tokenData !== null); // guarded() already refused an invalid token.

            // Opened once: the magic bytes are checked on exactly the bytes
            // that are sealed (security review N-13, issue #242).
            $offen = $this->uploads->oeffnen($id);
            try {
                $typ = MagicBytes::detect($offen->kopf());
                if ($typ === null) {
                    $offen->schliessen();
                    $this->verwerfen($spamschutz, $id);

                    throw new UploadException(UploadError::UnsupportedType);
                }

                // Today's budget counts what is stored, charged right before
                // it is (issue #242) - announcing alone never touched it.
                $voll = $spamschutz->uploadVerbuchen($offen->groesse);
                if ($voll !== null) {
                    $offen->schliessen();
                    $this->verwerfen($spamschutz, $id);

                    return Response::json(['fehler' => $voll->message()], $voll->status());
                }

                try {
                    $blob = ($this->store)()->store(
                        $offen->chunks(),
                        new BlobMeta($typ, $name),
                        $tokenData->hash(),
                        new \DateTimeImmutable(),
                    );
                } catch (\Throwable $e) {
                    $spamschutz->uploadZurueckbuchen($offen->groesse);

                    throw $e;
                }
            } finally {
                $offen->schliessen();
            }
            $this->verwerfen($spamschutz, $id);

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
            $this->verwerfen(($this->spamschutz)(), $params['id']);

            return Response::json(['status' => 'geloescht']);
        });
    }

    /**
     * The upload goes, and with it its open reservation in the byte budget
     * (issue #242) - both idempotent.
     */
    private function verwerfen(Spamschutz $spamschutz, string $id): void
    {
        $this->uploads->discard($id);
        $spamschutz->uploadBeendet($id);
    }

    /**
     * Verifies the form token, then runs the handler through
     * ausgefuehrt()'s exception mapping - chunk(), finish() and abort()
     * check only the token: the spam defence already ran once, in
     * create(), for the upload id they continue.
     *
     * @param \Closure(): Response $handler
     */
    private function guarded(Request $request, \Closure $handler): Response
    {
        if ($this->formToken->pruefen($request->header('x-csrf-token') ?? '') === null) {
            return Response::json(['fehler' => self::TOKEN_MESSAGE], 403);
        }

        return $this->ausgefuehrt($request, $handler);
    }

    /**
     * The JSON error contract for everything a handler can throw - the same
     * shape as App\Api\UploadController::guarded(), CSRF/session replaced by
     * the form token.
     *
     * @param \Closure(): Response $handler
     */
    private function ausgefuehrt(Request $request, \Closure $handler): Response
    {
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
