<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\BlobMeta;
use App\Domain\Worker;
use App\Domain\WorkerFaehigkeit;
use App\Domain\WorkerStatus;
use App\Http\Request;
use App\Http\Response;
use App\Service\Submission\EinreichungsEinstellungen;
use App\Service\Submission\MailEinreichung;
use App\Service\Submission\MailEinreichungErgebnis;
use App\Service\Submission\SubmissionUploadStore;
use App\Service\Upload\MagicBytes;
use App\Service\Upload\UploadException;
use App\Service\Upload\UploadService;
use App\Service\Worker\MailEingangGrenze;
use App\Service\Worker\MailEingangSpeicher;
use App\Service\Worker\WorkerProtokoll;
use App\Service\Worker\WorkerRouting;
use App\Support\FileLogger;

/**
 * The mail intake's part of `/api/worker/*` (issue #87, E-54,
 * docs/spec/07-worker.md section 9). The worker read a mail from the
 * club's IMAP mailbox and hands its attachments in here, the way a member
 * hands in the public form: the web host encrypts them under fresh data
 * keys sealed to VK_pub on arrival (App\Service\Submission\SubmissionUploadStore, the
 * same as `/einreichen/upload`), then makes the receipt
 * (App\Service\Submission\MailEinreichung). The worker cannot read any of
 * it afterwards.
 *
 *   POST mail/pruefen                 {schluessel} → {vorhanden, referenz}
 *   POST mail/uploads                 {schluessel, groesse} → upload ticket
 *   POST mail/uploads/{u}/chunks/{n}  raw plaintext chunk (over TLS)
 *   POST mail/uploads/{u}/finish      {schluessel, name} → {blob_id, typ}
 *   POST mail/einreichen              {schluessel, blobs[], absender{name,
 *                                     adresse}, betreff} → {status, referenz}
 *
 * Every route needs the signature (routes.php), an active worker of our
 * protocol, the module on, the mail intake switched on and `mail`
 * reported - otherwise 409 `mail_eingang_aus`. File types are decided by
 * the magic bytes here, not by the worker; sizes and page counts by the
 * public submission's limits; mails and bytes per worker and hour by
 * App\Service\Worker\MailEingangGrenze (429 `mail_limit`, issue #243).
 */
final readonly class WorkerMailController
{
    private const int MAX_JSON_BYTES = 65536;

    private const int MAX_CHUNK_BYTES = UploadService::CHUNK_BYTES + 4096;

    private const int MAX_NAME = 200;

    /**
     * The types a mail may bring: those of the public form without HEIC -
     * without a browser nobody converts it to the JPEG that is worked with
     * (E-10), so the worker leaves it out and the web host refuses it.
     */
    private const array TYPEN = [MagicBytes::PDF, MagicBytes::JPEG, MagicBytes::PNG, MagicBytes::XML];

    public function __construct(
        private MailEinreichung $einreichung,
        private MailEingangSpeicher $speicher,
        private WorkerRouting $routing,
        private UploadService $uploads,
        private SubmissionUploadStore $store,
        private EinreichungsEinstellungen $einstellungen,
        private MailEingangGrenze $grenze,
        private ?\Closure $linkBasis = null,
        private ?FileLogger $logger = null,
    ) {
    }

    public function pruefen(Request $request, Worker $worker): Response
    {
        return $this->bereit($worker) ?? $this->mitSchluessel($request, function (string $schluessel): Response {
            $referenz = $this->einreichung->vorhanden($schluessel);

            return Response::json(['vorhanden' => $referenz !== null, 'referenz' => $referenz]);
        });
    }

    public function uploadAnlegen(Request $request, Worker $worker): Response
    {
        return $this->bereit($worker) ?? $this->mitSchluessel($request, function (string $schluessel, array $daten) use ($request, $worker): Response {
            $groesse = $daten['groesse'] ?? null;
            if (!is_int($groesse) || $groesse < 1) {
                return self::fehler(422, 'groesse_ungueltig', 'Ungültige Dateigröße.');
            }
            // Larger than a whole hour allows: 413, so the worker sets the
            // mail aside instead of waiting for it hour after hour.
            if ($groesse > $this->einstellungen->maxDateiBytes() || $this->grenze->passtNie($groesse)) {
                return self::fehler(413, 'zu_gross', 'Die Datei ist zu groß.');
            }
            if (!$this->grenze->mailErlaubt($worker->id) || !$this->grenze->bytesReservieren($worker->id, $groesse)) {
                return self::mengenGrenze();
            }

            return $this->ausgefuehrt($request, function () use ($groesse, $worker): Response {
                try {
                    $ticket = $this->uploads->create($groesse);
                } catch (\Throwable $e) {
                    $this->grenze->bytesFreigeben($worker->id, $groesse);

                    throw $e;
                }

                return Response::json($ticket->toArray(), 201);
            });
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function uploadChunk(Request $request, array $params, Worker $worker): Response
    {
        if (($nein = $this->bereit($worker)) !== null) {
            return $nein;
        }
        $body = $request->body();
        if (strlen($body) > self::MAX_CHUNK_BYTES) {
            return self::fehler(413, 'zu_gross', 'Der Teil ist zu groß.');
        }

        return $this->ausgefuehrt($request, function () use ($params, $body): Response {
            $stream = fopen('php://memory', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open a memory stream.');
            }
            try {
                fwrite($stream, $body);
                rewind($stream);
                $index = (int) ($params['n'] ?? -1);
                $this->uploads->writeChunk($params['upload'] ?? '', $index, $stream);
            } finally {
                fclose($stream);
            }

            return Response::json(['chunk' => $index] + $this->uploads->status($params['upload'] ?? '')->toArray());
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function uploadAbschliessen(Request $request, array $params, Worker $worker): Response
    {
        return $this->bereit($worker) ?? $this->mitSchluessel($request, function (string $schluessel, array $daten) use ($request, $params): Response {
            $id = $params['upload'] ?? '';

            return $this->ausgefuehrt($request, function () use ($id, $schluessel, $daten): Response {
                $status = $this->uploads->status($id);
                if (!$status->vollstaendig()) {
                    return Response::json(['fehler' => 'unvollstaendig', 'meldung' => 'Es fehlen Teile.', 'fehlend' => $status->fehlend], 409);
                }
                $typ = MagicBytes::detect($this->uploads->head($id));
                if ($typ === null || !in_array($typ, self::TYPEN, true)) {
                    $this->uploads->discard($id);

                    return self::fehler(415, 'typ_nicht_erlaubt', 'Dieser Dateityp wird nicht angenommen.');
                }
                // Stored and bound to its mail in one step (submission_upload),
                // so the cron finds it again if the mail never becomes a receipt.
                $blob = $this->store->store($this->uploads->chunks($id), new BlobMeta($typ, self::dateiname($daten['name'] ?? '')), MailEinreichung::formHash($schluessel), new \DateTimeImmutable());
                $this->uploads->discard($id);

                return Response::json(['blob_id' => $blob->id, 'groesse' => $blob->size, 'typ' => $typ], 201);
            });
        });
    }

    public function einreichen(Request $request, Worker $worker): Response
    {
        return $this->bereit($worker) ?? $this->mitSchluessel($request, function (string $schluessel, array $daten) use ($request, $worker): Response {
            $absender = is_array($daten['absender'] ?? null) ? $daten['absender'] : [];
            $blobs = $daten['blobs'] ?? null;
            if (!is_array($blobs) || !array_is_list($blobs)) {
                return self::fehler(422, 'anhaenge_ungueltig', 'Erwartet wird eine Liste von Blob-IDs.');
            }
            if (!$this->grenze->mailReservieren($worker->id)) {
                return self::mengenGrenze();
            }

            try {
                $ergebnis = $this->einreichung->einreichen(
                    $schluessel,
                    $blobs,
                    is_string($absender['name'] ?? null) ? $absender['name'] : '',
                    is_string($absender['adresse'] ?? null) ? $absender['adresse'] : '',
                    is_string($daten['betreff'] ?? null) ? $daten['betreff'] : '',
                    $this->speicher->laden()->absender,
                    new \DateTimeImmutable(),
                    $this->linkBasis === null ? null : ($this->linkBasis)($request),
                );
            } catch (\Throwable $e) {
                $this->grenze->mailFreigeben($worker->id);

                throw $e;
            }
            // Only a new receipt counts against the hour.
            if ($ergebnis->status !== MailEinreichungErgebnis::EINGEREICHT) {
                $this->grenze->mailFreigeben($worker->id);
            }

            return match ($ergebnis->status) {
                MailEinreichungErgebnis::EINGEREICHT => Response::json(['status' => $ergebnis->status, 'referenz' => $ergebnis->referenz], 201),
                MailEinreichungErgebnis::VORHANDEN => Response::json(['status' => $ergebnis->status, 'referenz' => $ergebnis->referenz]),
                default => Response::json(['status' => $ergebnis->status, 'fehler' => $ergebnis->fehler, 'meldung' => 'Die Mail wurde nicht eingereicht.'], 422),
            };
        });
    }

    /**
     * Null when the worker may hand mail in right now, else the refusal.
     */
    private function bereit(Worker $worker): ?Response
    {
        if ($worker->status !== WorkerStatus::Aktiv) {
            return self::fehler(403, 'nicht_bestaetigt', 'Der Worker wartet auf die Bestätigung im Admin.');
        }
        if (!WorkerProtokoll::unterstuetzt($worker->protokollVersion)) {
            return self::fehler(409, 'protokoll', 'Worker-Update nötig: Protokollversion wird nicht unterstützt.');
        }
        if (!$this->routing->mailZugangErlaubt($worker->faehigkeiten) || !$this->speicher->aktiv()) {
            return self::fehler(409, 'mail_eingang_aus', 'Der E-Mail-Eingang ist nicht eingeschaltet.');
        }

        return null;
    }

    /**
     * Reads the JSON body and its `schluessel` (hex SHA-256 of the mail).
     *
     * @param \Closure(string, array<string, mixed>): Response $handler
     */
    private function mitSchluessel(Request $request, \Closure $handler): Response
    {
        $body = $request->body();
        $daten = strlen($body) > self::MAX_JSON_BYTES ? null : json_decode($body, true);
        if (!is_array($daten) || array_is_list($daten)) {
            return self::fehler(400, 'body_ungueltig', 'Erwartet wird ein JSON-Objekt.');
        }
        $schluessel = is_string($daten['schluessel'] ?? null) ? $daten['schluessel'] : '';
        if (!MailEinreichung::schluesselGueltig($schluessel)) {
            return self::fehler(422, 'schluessel_ungueltig', 'Der Schlüssel der Mail ist ungültig.');
        }

        return $handler($schluessel, $daten);
    }

    /**
     * The upload component's errors as JSON - its messages are fixed German
     * texts, never content; anything else is logged by class only.
     *
     * @param \Closure(): Response $handler
     */
    private function ausgefuehrt(Request $request, \Closure $handler): Response
    {
        try {
            return $handler();
        } catch (UploadException $e) {
            return Response::json(['fehler' => 'upload_' . $e->error->value, 'meldung' => $e->error->message()], $e->error->status());
        } catch (\Throwable $e) {
            $this->logger?->append(sprintf('worker mail intake failed: %s in %s', $e::class, $request->path));

            return self::fehler(500, 'interner_fehler', 'Der Upload ist fehlgeschlagen.');
        }
    }

    /** Only shown, never a path - like the public upload's file names. */
    private static function dateiname(mixed $name): string
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            return '';
        }
        $sauber = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name)));

        return mb_substr(trim($sauber ?? ''), 0, self::MAX_NAME);
    }

    /** The worker ends its run and tries again later; the mail waits in the mailbox. */
    private static function mengenGrenze(): Response
    {
        return self::fehler(429, 'mail_limit', 'Die Mengengrenze des E-Mail-Eingangs ist für diese Stunde erreicht.');
    }

    private static function fehler(int $status, string $fehler, string $meldung): Response
    {
        return Response::json(['fehler' => $fehler, 'meldung' => $meldung], $status);
    }
}
