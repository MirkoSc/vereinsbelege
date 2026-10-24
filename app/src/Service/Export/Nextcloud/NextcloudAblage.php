<?php

declare(strict_types=1);

namespace App\Service\Export\Nextcloud;

use App\Domain\AuditAction;
use App\Domain\ExportRun;
use App\Domain\ExportRunUpload;
use App\Domain\NextcloudAblageStatus;
use App\Domain\Zugriffsbereich;
use App\Repository\ExportRunUploadRepository;
use App\Service\Audit\AuditLog;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\Service\Export\ExportFilter;
use App\Service\Export\Dateiname;
use App\Service\Export\ExportPlan;
use App\Service\Export\ZipExport;
use App\Service\Storage\BlobException;
use App\Service\Storage\BlobService;

/**
 * Files an export run into the club's Nextcloud (issue #95,
 * docs/spec/05-auswertung-und-export.md section 2 "Nextcloud-Ablage").
 *
 * No ZIP on the server: the files go up one by one over WebDAV, with the
 * very paths and names of the ZIP export (ZipExport::vorbereiten(), the
 * pattern of M12-1), index.csv last. Everything is decrypted chunk by chunk
 * straight into the upload - no plaintext on disk.
 *
 * An idempotent chain of short requests in the user's session (CLAUDE.md
 * sections 1 and 4): starten() records the run's file count, every
 * schritt() files the next few files and moves the cursor in
 * `export_run_upload` after each one. A step that fails changes nothing - the
 * next one tries the same file again.
 *
 * Per file, never overwriting anything in the cloud:
 * - nothing there → MKCOL the missing folders, PUT, check the size;
 * - the same file there (Nextcloud's SHA-1, else the size) → skipped;
 * - another file there → the next free " (2)", " (3)" … instead, exactly
 *   as the ZIP export numbers collisions. index.csv keeps the name the
 *   pattern gave; a renamed file is counted and the page says so.
 */
final readonly class NextcloudAblage
{
    /** Files one step handles at most. */
    public const int MAX_JE_SCHRITT = 20;

    /** Seconds after which a step starts no further file. */
    public const float BUDGET_S = 8.0;

    /** Name variants tried per file before giving up. */
    public const int MAX_VARIANTEN = 50;

    public function __construct(
        private ZipExport $export,
        private BlobService $blobs,
        private ExportRunUploadRepository $uploads,
        private NextcloudZielSpeicher $ziele,
        private WebDav $dav,
        private AuditLog $audit,
        private float $budgetS = self::BUDGET_S,
        private int $maxJeSchritt = self::MAX_JE_SCHRITT,
    ) {
    }

    /** Whether a target is stored - needs no vault. */
    public function eingerichtet(): bool
    {
        return $this->ziele->eingerichtet();
    }

    public function stand(int $exportRunId): ?ExportRunUpload
    {
        return $this->uploads->find($exportRunId);
    }

    /**
     * Checks the target folder and starts the chain from the first file - a
     * second filing of the same run starts over and skips what is there.
     *
     * @throws NextcloudAblageFehler|WebDavFehler
     */
    public function starten(ExportRun $lauf, Zugriffsbereich $bereich, Vault $vault, ?int $userId, \DateTimeImmutable $now): ExportRunUpload
    {
        $ziel = $this->ziel($vault);
        $ordner = $this->dav->eigenschaften($ziel, '');
        if ($ordner === null || !$ordner->ordner) {
            throw new NextcloudAblageFehler('Der Ordner wurde in der Nextcloud nicht gefunden – bitte die Adresse in der Administration prüfen.');
        }

        $plan = $this->plan($lauf, $bereich, $vault);
        if ($plan->anzahlBelege === 0) {
            throw new NextcloudAblageFehler('Die Belege dieses Exports sind nicht mehr verfügbar – es wurde nichts abgelegt.');
        }
        $this->uploads->starten($lauf->id, $plan->anzahlDateien(), self::planHash($plan, $vault), $userId, $now);

        return $this->uploads->find($lauf->id) ?? throw new \LogicException('The upload row vanished.');
    }

    /**
     * Files the next few files - at most $maxJeSchritt, and none started
     * after $budgetS seconds. The last file is index.csv; once it is up the
     * chain is `fertig` and the audit log records it (once).
     *
     * @throws NextcloudAblageFehler|WebDavFehler the cursor stays where it was
     */
    public function schritt(ExportRun $lauf, Zugriffsbereich $bereich, Vault $vault, ?int $userId, string $ip, \DateTimeImmutable $now): ExportRunUpload
    {
        $stand = $this->uploads->find($lauf->id);
        if ($stand === null || $stand->status !== NextcloudAblageStatus::Laeuft) {
            return $stand ?? throw new NextcloudAblageFehler('Die Ablage dieses Exports wurde nicht gestartet.');
        }

        $ziel = $this->ziel($vault);
        $plan = $this->plan($lauf, $bereich, $vault);
        $gesamt = $plan->anzahlDateien();
        $index = $stand->nextIndex;
        $hash = self::planHash($plan, $vault);
        if ($hash !== $stand->planHash) {
            // A receipt of the run was deleted, rejected or moved since the
            // chain started: the cursor no longer points at the same file.
            // Start over - what is up already counts as there.
            if (!$this->uploads->neuerPlan($lauf->id, $stand->planHash, $gesamt, $hash, $now)) {
                return $this->uploads->find($lauf->id) ?? $stand;
            }
            $index = 0;
        }
        $beginn = hrtime(true);
        $erledigt = 0;
        /** @var array<string, true> $ordner folders known to exist in this request */
        $ordner = [];

        while ($index < $gesamt && $erledigt < $this->maxJeSchritt) {
            if ($erledigt > 0 && (hrtime(true) - $beginn) / 1e9 >= $this->budgetS) {
                break;
            }
            try {
                $ergebnis = $this->datei($ziel, $plan, $index, $vault, $ordner);
            } catch (BlobException | CryptoException) {
                throw new NextcloudAblageFehler('Eine Datei des Exports ist nicht lesbar. Bitte einen neuen Export erstellen.');
            }
            if (!$this->uploads->weiter($lauf->id, $index, $ergebnis, $now)) {
                // Another tab moved on first; it carries the chain.
                break;
            }
            $index++;
            $erledigt++;
        }

        if ($index >= $gesamt && $this->uploads->abschliessen($lauf->id, $now)) {
            $fertig = $this->uploads->find($lauf->id) ?? $stand;
            $this->audit->record(AuditAction::ExportNextcloud, $userId, $ip, entityId: $lauf->id, details: [
                'belege' => $plan->anzahlBelege,
                'dateien' => $gesamt,
                'hochgeladen' => $fertig->hochgeladen,
                'vorhanden' => $fertig->vorhanden,
                'umbenannt' => $fertig->umbenannt,
            ]);
        }

        return $this->uploads->find($lauf->id) ?? $stand;
    }

    /**
     * File number $index of the plan: the receipts in ZIP order, then
     * index.csv.
     *
     * @param array<string, true> $ordner
     *
     * @return 'hochgeladen'|'vorhanden'|'umbenannt'
     */
    private function datei(NextcloudZiel $ziel, ExportPlan $plan, int $index, Vault $vault, array &$ordner): string
    {
        $eintrag = $plan->eintraege[$index] ?? null;
        if ($eintrag === null) {
            $csv = $plan->indexCsv;

            return $this->ablegen($ziel, $plan->indexPfad, strlen($csv), sha1($csv), static fn(): array => [$csv], $ordner);
        }

        $blob = $this->blobs->find($eintrag['blobId']);
        if ($blob === null || !$blob->isComplete()) {
            throw new NextcloudAblageFehler('Eine Datei des Exports fehlt im Speicher. Bitte einen neuen Export erstellen.');
        }
        $hash = hash_init('sha1');
        foreach ($this->blobs->openRead($blob, $vault) as $stueck) {
            hash_update($hash, $stueck);
        }

        return $this->ablegen(
            $ziel,
            $eintrag['pfad'],
            $blob->size,
            hash_final($hash),
            fn(): \Generator => $this->blobs->openRead($blob, $vault),
            $ordner,
        );
    }

    /**
     * @param \Closure(): iterable<string> $inhalt
     * @param array<string, true> $ordner
     *
     * @return 'hochgeladen'|'vorhanden'|'umbenannt'
     */
    private function ablegen(NextcloudZiel $ziel, string $pfad, int $groesse, string $sha1, \Closure $inhalt, array &$ordner): string
    {
        for ($nummer = 1; $nummer <= self::MAX_VARIANTEN; $nummer++) {
            $kandidat = self::variante($pfad, $nummer);
            $vorhanden = $this->dav->eigenschaften($ziel, $kandidat);
            if ($vorhanden !== null) {
                // Without Nextcloud's checksum (a file put there by hand) a
                // matching size is not proof enough: compare the content.
                $gleich = $vorhanden->gleicheDatei($groesse, $sha1)
                    ?? hash_equals($sha1, sha1($this->dav->lesen($ziel, $kandidat, $groesse)));
                if ($gleich) {
                    return 'vorhanden';
                }
                // Somebody else's file (or a folder) has the name: never
                // overwrite it, take the next number.
                continue;
            }

            $this->ordnerAnlegen($ziel, $kandidat, $ordner);
            $this->dav->hochladen($ziel, $kandidat, $inhalt(), $groesse, $sha1);
            $angekommen = $this->dav->eigenschaften($ziel, $kandidat);
            if ($angekommen === null || $angekommen->ordner || ($angekommen->groesse !== null && $angekommen->groesse !== $groesse)) {
                throw new NextcloudAblageFehler('Eine Datei kam nicht vollständig in der Nextcloud an. Bitte erneut versuchen.');
            }

            return $nummer === 1 ? 'hochgeladen' : 'umbenannt';
        }

        throw new NextcloudAblageFehler(sprintf('In der Nextcloud liegen mehr als %d abweichende Dateien mit demselben Namen – bitte den Ordner aufräumen.', self::MAX_VARIANTEN - 1));
    }

    /**
     * MKCOL for every folder above $pfad, top down, once per request.
     *
     * @param array<string, true> $ordner
     */
    private function ordnerAnlegen(NextcloudZiel $ziel, string $pfad, array &$ordner): void
    {
        $segmente = explode('/', $pfad);
        array_pop($segmente);
        $weg = '';
        foreach ($segmente as $segment) {
            $weg = $weg === '' ? $segment : $weg . '/' . $segment;
            if (!isset($ordner[$weg])) {
                $this->dav->ordnerAnlegen($ziel, $weg);
                $ordner[$weg] = true;
            }
        }
    }

    /**
     * "a/b/Name.pdf" → "a/b/Name (2).pdf" - the same suffix as the ZIP
     * export's collisions (App\Service\Export\PfadVergabe).
     */
    public static function variante(string $pfad, int $nummer): string
    {
        if ($nummer === 1) {
            return $pfad;
        }
        $schnitt = strrpos($pfad, '/');
        $ordner = $schnitt === false ? '' : substr($pfad, 0, $schnitt + 1);
        $name = $schnitt === false ? $pfad : substr($pfad, $schnitt + 1);
        $punkt = strrpos($name, '.');
        $stamm = $punkt === false || $punkt === 0 ? $name : substr($name, 0, $punkt);
        $endung = $punkt === false || $punkt === 0 ? '' : substr($name, $punkt);
        $suffix = sprintf(' (%d)', $nummer);

        // The suffix always fits: the stem gives way, never the counter.
        return $ordner . Dateiname::segment($stamm, Dateiname::MAX_LAENGE - mb_strlen($suffix)) . $suffix . $endung;
    }

    /**
     * What a running chain must not lose track of: which file goes where,
     * in which order (paths and blob ids, index.csv included). The paths
     * carry supplier names, so the fingerprint is an HMAC under the vault's
     * blind index key - not computable without the vault, and no plain
     * hash anyone could test names against.
     */
    private static function planHash(ExportPlan $plan, Vault $vault): string
    {
        $liste = array_map(static fn(array $e): array => [$e['pfad'], $e['blobId']], $plan->eintraege);
        $liste[] = [$plan->indexPfad, 0];
        // Hashed first: BlindIndex::forValue() normalises case and spaces.
        $inhalt = hash('sha256', json_encode($liste, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return bin2hex($vault->blindIndex()->forValue('export_run_upload.plan', $inhalt));
    }

    private function plan(ExportRun $lauf, Zugriffsbereich $bereich, Vault $vault): ExportPlan
    {
        return $this->export->vorbereiten(ExportFilter::ausFeldern($lauf->filter, $lauf->createdAt), $bereich, $vault, $lauf->id);
    }

    private function ziel(Vault $vault): NextcloudZiel
    {
        try {
            $ziel = $this->ziele->lesen($vault);
        } catch (CryptoException | \JsonException) {
            $ziel = null;
        }

        return $ziel ?? throw new NextcloudAblageFehler('Es ist keine Nextcloud eingerichtet. Ein Admin trägt sie unter Administration → Export ein.');
    }
}
