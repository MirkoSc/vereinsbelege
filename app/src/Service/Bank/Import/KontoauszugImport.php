<?php

declare(strict_types=1);

namespace App\Service\Bank\Import;

use App\Domain\AuditAction;
use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\BankImportRecord;
use App\Domain\BankImportStats;
use App\Domain\BankImportStatus;
use App\Domain\BankTransactionDirection;
use App\Domain\BankTransactionDocStatus;
use App\Domain\BankTransactionRecord;
use App\Domain\BankTransactionSource;
use App\Domain\BlobMeta;
use App\Domain\Iban;
use App\Repository\BankAccountRepository;
use App\Repository\BankImportRepository;
use App\Repository\BankTransactionRepository;
use App\Repository\CsvProfileRepository;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Kontoangabe;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;
use App\Service\Storage\BlobService;

/**
 * The statement import (M9-4, issue #62, docs/spec/04-bank-und-abgleich.md
 * section 4): upload → preview → confirm → step chain → done. Nothing is
 * swallowed silently - the preview shows what will happen, and the balance
 * check says afterwards whether the balances agree.
 *
 * - Upload: the file is read once to refuse what cannot be imported, then
 *   stored as it is as an encrypted blob (the original stays) and an import
 *   row `vorschau` is created. Nothing else is written.
 * - The account: the IBAN of the MT940 account line (bank code and account
 *   number turned into a German IBAN), else the account picked for the last
 *   finished import of the same line, else the one chosen in the form, else
 *   the preview asks. Only active bank accounts take imports; a file whose
 *   line names another account than the one chosen is refused.
 * - Preview: everything is worked out from the file again on every view -
 *   new or already stored (App\Service\Bank\Import\Dedupschluessel), before
 *   the opening date, row errors, the balance check
 *   (App\Service\Bank\Import\Saldenpruefung).
 * - Confirm: the import moves to `laeuft`; the browser then calls schritt()
 *   until it is `fertig`. One step writes at most $schrittPosten bookings in
 *   one short transaction and moves the cursor from exactly the value it
 *   read, so a repeated or parallel step writes nothing twice; the unique
 *   dedup key per account is the net underneath.
 * - Bookings before the opening date are imported like the others (they
 *   only stay outside the balance from the opening date on); rows that did
 *   not fit the CSV format are shown and left out.
 *
 * Every method needs the unlocked vault of the session (blind indexes,
 * reading the file), except verwerfen().
 */
final readonly class KontoauszugImport
{
    /** Largest file the upload takes - statements are small (2 MiB, like the CSV assistant). */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** Bookings written per step - well inside the time budget of one request. */
    public const int SCHRITT_POSTEN = 100;

    /** Purpose of `bank_import.source_bi` - never rename. */
    public const string SOURCE_PURPOSE = 'bank_import.source';

    /** Purpose of `bank_transaction.counterparty_bi` - never rename. */
    public const string COUNTERPARTY_PURPOSE = 'bank_transaction.counterparty';

    private const string TABLE = 'bank_transaction';

    public function __construct(
        private \PDO $pdo,
        private BankImportRepository $importe,
        private BankTransactionRepository $buchungen,
        private BankAccountRepository $kontoRecords,
        private BankAccountService $konten,
        private CsvProfileRepository $profile,
        private BlobService $blobs,
        private SettingRepository $settings,
        private AuditLog $audit,
        private KontoauszugLeser $leser = new KontoauszugLeser(),
        private int $schrittPosten = self::SCHRITT_POSTEN,
    ) {
    }

    /**
     * Stores the file and creates the preview. Returns the import id.
     *
     * @throws KontoauszugUnlesbar the file cannot be imported
     * @throws BankRuleViolation   the account does not take it
     */
    public function hochladen(
        Vault $vault,
        string $inhalt,
        string $dateiname,
        ?int $gewaehltesKonto,
        ?ImportFormat $format,
        ?int $userId,
        \DateTimeImmutable $now,
    ): int {
        if (trim($inhalt) === '') {
            throw new KontoauszugUnlesbar('Die Datei ist leer.');
        }
        if (strlen($inhalt) > self::MAX_BYTES) {
            throw new KontoauszugUnlesbar('Die Datei ist zu groß (höchstens 2 MB).');
        }

        $profile = $this->profile->all();
        $format ??= $this->leser->erkenne($inhalt, $profile);
        $datei = $this->leser->lies($inhalt, $format, $profile);
        if ($datei->posten === [] && $datei->fehler === []) {
            throw new KontoauszugUnlesbar('Die Datei enthält keine Buchungen.');
        }

        $sourceBi = $datei->konto === null ? null : $this->sourceBi($vault, $datei->konto);
        $kontoId = $this->kontoFuerDatei($vault, $datei, $sourceBi, $gewaehltesKonto);
        if ($kontoId !== null) {
            $this->nimmtImporte($vault, $kontoId);
        }

        $blob = $this->blobs->storeString(
            $inhalt,
            new BlobMeta($format->istMt940() ? 'text/plain' : 'text/csv', self::dateiname($dateiname)),
            $vault,
            BlobService::configuredStorage($this->settings),
        );
        [$von, $bis] = $datei->zeitraum() ?? [null, null];
        try {
            return $this->importe->insert($kontoId, $format->toString(), $blob->id, $sourceBi, $von, $bis, $userId, $now);
        } catch (\Throwable $e) {
            $this->blobs->delete($blob);

            throw $e;
        }
    }

    /**
     * The preview, or the result once confirmed. Null for an unknown id.
     *
     * @throws KontoauszugUnlesbar the stored file can no longer be read (its
     *         CSV format was deleted, say)
     */
    public function vorschau(Vault $vault, int $id): ?ImportVorschau
    {
        $import = $this->importe->find($id);
        if ($import === null) {
            return null;
        }

        [$datei, $dateiname] = $this->datei($vault, $import);
        $konto = $import->accountId === null ? null : $this->konten->finde($vault, $import->accountId);

        $vorhanden = [];
        $schluessel = [];
        if ($konto !== null && $import->status === BankImportStatus::Vorschau) {
            $schluessel = Dedupschluessel::fuer($vault->blindIndex(), $konto->id, $datei->posten);
            $vorhanden = $this->buchungen->existingDedup($konto->id, $schluessel);
        }

        $posten = [];
        $duplikate = 0;
        $vorStichtag = 0;
        foreach ($datei->posten as $i => $p) {
            $duplikat = $schluessel === [] ? null : isset($vorhanden[$schluessel[$i]]);
            $frueh = $konto !== null && $p->umsatz->buchungsdatum->format('Y-m-d') < $konto->openingDate->format('Y-m-d');
            $posten[] = new VorschauPosten($p, $duplikat, $frueh);
            $duplikate += $duplikat === true ? 1 : 0;
            $vorStichtag += $frueh ? 1 : 0;
        }

        $zaehler = $import->status === BankImportStatus::Vorschau || $import->stats === null
            ? new BankImportStats(
                gesamt: count($datei->posten),
                neu: count($datei->posten) - $duplikate,
                duplikat: $duplikate,
                fehler: count($datei->fehler),
                vorgemerkt: $datei->vorgemerkt,
                vorStichtag: $vorStichtag,
            )
            : $import->stats;

        [$kontostand, $hinweis] = $import->status === BankImportStatus::Vorschau
            ? $this->kontostandVorBeginn($vault, $datei, $konto)
            : [null, null];

        return new ImportVorschau(
            $import,
            $datei,
            $konto,
            $posten,
            $zaehler,
            Saldenpruefung::pruefe($datei, $kontostand, BankAccountService::CURRENCY),
            $hinweis,
            $dateiname,
        );
    }

    /**
     * "Welches Konto?" for a preview.
     *
     * @throws BankRuleViolation
     * @throws KontoauszugUnlesbar
     */
    public function kontoWaehlen(Vault $vault, int $id, int $kontoId, \DateTimeImmutable $now): void
    {
        $import = $this->importe->find($id) ?? throw new BankRuleViolation('Diesen Import gibt es nicht.');
        if ($import->status !== BankImportStatus::Vorschau) {
            throw new BankRuleViolation('Das Konto lässt sich nur vor dem Übernehmen wählen.');
        }

        [$datei] = $this->datei($vault, $import);
        $kontoId = $this->kontoFuerDatei($vault, $datei, null, $kontoId) ?? $kontoId;
        $this->nimmtImporte($vault, $kontoId);
        $this->importe->setAccount($id, $kontoId, $now);
    }

    /**
     * Confirms the preview; the step chain may start.
     *
     * @throws BankRuleViolation
     * @throws KontoauszugUnlesbar
     */
    public function uebernehmen(Vault $vault, int $id, ?int $userId, \DateTimeImmutable $now): void
    {
        $vorschau = $this->vorschau($vault, $id) ?? throw new BankRuleViolation('Diesen Import gibt es nicht.');
        if ($vorschau->import->status !== BankImportStatus::Vorschau) {
            // Confirmed already - by a second click or another tab. Nothing to do.
            return;
        }
        if ($vorschau->konto === null) {
            throw new BankRuleViolation('Bitte zuerst das Konto wählen.', 'konto');
        }
        if ($vorschau->zaehler->gesamt === 0) {
            throw new BankRuleViolation('Die Datei enthält keine Buchung, die sich übernehmen lässt.');
        }
        $this->nimmtImporte($vault, $vorschau->konto->id);

        $this->importe->start($id, $userId, $vorschau->zaehler, $vorschau->salden->ergebnis, $now);
    }

    /**
     * One step of the chain: writes the next bookings and says where the
     * import stands. Safe to call again and from two tabs at once.
     *
     * @throws BankRuleViolation   unknown import, or not confirmed
     * @throws KontoauszugUnlesbar
     */
    public function schritt(Vault $vault, int $id, string $ip, \DateTimeImmutable $now): ImportStand
    {
        $import = $this->importe->find($id) ?? throw new BankRuleViolation('Diesen Import gibt es nicht.');
        if ($import->status === BankImportStatus::Fertig) {
            $gesamt = $import->stats->gesamt ?? $import->nextIndex;

            return new ImportStand(BankImportStatus::Fertig, $gesamt, $gesamt);
        }
        if ($import->status !== BankImportStatus::Laeuft || $import->accountId === null) {
            throw new BankRuleViolation('Dieser Import ist nicht bestätigt.');
        }

        [$datei] = $this->datei($vault, $import);
        $gesamt = count($datei->posten);
        $von = min($import->nextIndex, $gesamt);
        $bis = min($von + $this->schrittPosten, $gesamt);

        if ($von < $bis) {
            $geschrieben = $this->schreibe($vault, $import, $datei, $von, $bis, $now);
            if (!$geschrieben) {
                // Another request moved the chain on meanwhile: report what is.
                $jetzt = $this->importe->find($id);

                return new ImportStand($jetzt->status ?? $import->status, min($jetzt->nextIndex ?? $von, $gesamt), $gesamt);
            }
        }

        if ($bis < $gesamt) {
            return new ImportStand(BankImportStatus::Laeuft, $bis, $gesamt);
        }

        $neu = $this->buchungen->countForImport($id);
        $vorher = $import->stats ?? new BankImportStats(gesamt: $gesamt);
        $stats = new BankImportStats(
            gesamt: $gesamt,
            neu: $neu,
            duplikat: $gesamt - $neu,
            fehler: $vorher->fehler,
            vorgemerkt: $vorher->vorgemerkt,
            vorStichtag: $vorher->vorStichtag,
        );
        if ($this->importe->finish($id, $stats, $now)) {
            $this->audit->record(AuditAction::KontoauszugImportiert, $import->importedBy, $ip, $id, [
                'format' => $import->format,
                ...$stats->toArray(),
                'saldenpruefung' => $import->balanceCheck->value ?? '',
            ], $now);
        }

        return new ImportStand(BankImportStatus::Fertig, $gesamt, $gesamt);
    }

    /**
     * Discards a preview: the row and the stored file. A confirmed import
     * stays. Needs no vault - nothing is read.
     */
    public function verwerfen(int $id): bool
    {
        $import = $this->importe->find($id);
        if ($import === null || !$this->importe->deletePreview($id)) {
            return false;
        }

        $blob = $this->blobs->find($import->fileBlobId);
        if ($blob !== null) {
            $this->blobs->delete($blob);
        }

        return true;
    }

    /**
     * The latest imports for the history on the import page.
     *
     * @return list<BankImportRecord>
     */
    public function letzte(int $anzahl): array
    {
        return $this->importe->recent($anzahl);
    }

    /**
     * Writes postings $von..$bis-1 in one transaction. False when another
     * request had moved the cursor already - then nothing was written.
     */
    private function schreibe(Vault $vault, BankImportRecord $import, ImportDatei $datei, int $von, int $bis, \DateTimeImmutable $now): bool
    {
        $kontoId = (int) $import->accountId;
        $index = $vault->blindIndex();
        $schluessel = array_slice(Dedupschluessel::fuer($index, $kontoId, $datei->posten), $von, $bis - $von, true);

        $this->pdo->beginTransaction();
        try {
            // First, so a parallel step waits on the row lock or loses here.
            if (!$this->importe->advance($import->id, $von, $bis, $now)) {
                $this->pdo->rollBack();

                return false;
            }

            $vorhanden = $this->buchungen->existingDedup($kontoId, array_values($schluessel));
            foreach ($schluessel as $i => $dedup) {
                if (!isset($vorhanden[$dedup])) {
                    $this->buchung($vault, $kontoId, $import->id, $datei->posten[$i], $dedup, $now);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return true;
    }

    private function buchung(Vault $vault, int $kontoId, int $importId, ImportPosten $p, string $dedup, \DateTimeImmutable $now): void
    {
        $umsatz = $p->umsatz;
        $details = $umsatz->details;
        $richtung = BankTransactionDirection::ausDemBetrag($umsatz->cent);
        $belegNoetig = $richtung->belegNoetigStandard();
        $gegenIban = Iban::normalisieren($details->iban ?? '');

        $key = DataKey::generate();
        $id = $this->buchungen->insert(
            $kontoId,
            $importId,
            $umsatz->buchungsdatum,
            $umsatz->valuta,
            $richtung,
            $vault->sealDataKey($key),
            $dedup,
            $gegenIban === '' ? null : $vault->blindIndex()->forValue(self::COUNTERPARTY_PURPOSE, $gegenIban),
            BankTransactionDocStatus::fuerNeueBuchung($belegNoetig),
            $belegNoetig,
            BankTransactionSource::Import,
            $now,
        );
        if ($id === null) {
            return;
        }

        $daten = [
            'amount' => $umsatz->cent,
            'currency' => $p->waehrung,
            'counterparty_name' => $details->name ?? '',
            'counterparty_iban' => $gegenIban,
            'purpose' => $details->verwendungszweck ?? '',
            'eref' => $details?->sepa('EREF') ?? '',
            'mref' => $details?->sepa('MREF') ?? '',
            'cred' => $details?->sepa('CRED') ?? '',
            'gvc' => $details->gvc ?? '',
            'booking_text' => $details->buchungstext ?? '',
        ];
        $this->buchungen->setCiphertext($id, FieldCipher::encrypt(
            $key,
            json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            new FieldContext(self::TABLE, $id, 'data_enc'),
        ));
    }

    /**
     * The stored file, read again.
     *
     * @return array{ImportDatei, string} the file and its original name
     *
     * @throws KontoauszugUnlesbar
     */
    private function datei(Vault $vault, BankImportRecord $import): array
    {
        $blob = $this->blobs->find($import->fileBlobId) ?? throw new KontoauszugUnlesbar('Die Datei dieses Imports fehlt.');
        $inhalt = implode('', iterator_to_array($this->blobs->openRead($blob, $vault), false));

        try {
            $format = ImportFormat::fromString($import->format);
        } catch (\InvalidArgumentException) {
            throw new KontoauszugUnlesbar('Das Format dieses Imports ist unbekannt.');
        }

        return [$this->leser->lies($inhalt, $format, $this->profile->all()), $this->blobs->meta($blob, $vault)->originalName ?? ''];
    }

    /**
     * The account the file itself names (by IBAN), else the one remembered
     * for its account line, else the chosen one. Refuses a choice that
     * contradicts the file.
     *
     * @throws BankRuleViolation
     */
    private function kontoFuerDatei(Vault $vault, ImportDatei $datei, ?string $sourceBi, ?int $gewaehlt): ?int
    {
        $ausDatei = $this->kontoMitIban($vault, $datei->konto);
        if ($ausDatei !== null) {
            if ($gewaehlt !== null && $gewaehlt !== $ausDatei) {
                $name = $this->konten->finde($vault, $ausDatei)?->data->name ?? '?';
                throw new BankRuleViolation(
                    sprintf('Die Datei gehört zum Konto „%s“, nicht zum gewählten Konto.', $name),
                    'konto',
                    $ausDatei,
                );
            }

            return $ausDatei;
        }
        if ($gewaehlt !== null) {
            return $gewaehlt;
        }

        return $sourceBi === null ? null : $this->importe->accountForSource($sourceBi);
    }

    private function kontoMitIban(Vault $vault, ?Kontoangabe $angabe): ?int
    {
        if ($angabe === null) {
            return null;
        }
        $iban = $angabe->iban ?? ($angabe->blz !== null && $angabe->kontonummer !== null
            ? Iban::ausBlzUndKonto($angabe->blz, $angabe->kontonummer)
            : null);
        if ($iban === null || !Iban::istGueltig($iban)) {
            return null;
        }

        return $this->kontoRecords->idWithIban($vault->blindIndex()->forValue(BankAccountService::IBAN_PURPOSE, Iban::normalisieren($iban)));
    }

    private function sourceBi(Vault $vault, Kontoangabe $angabe): string
    {
        return $vault->blindIndex()->forValue(self::SOURCE_PURPOSE, KontoauszugLeser::kontokennung($angabe));
    }

    /**
     * @throws BankRuleViolation
     */
    private function nimmtImporte(Vault $vault, int $kontoId): BankAccount
    {
        $konto = $this->konten->finde($vault, $kontoId) ?? throw new BankRuleViolation('Dieses Konto gibt es nicht.', 'konto');
        if ($konto->kind !== BankAccountKind::Bank) {
            throw new BankRuleViolation('Kontoauszüge lassen sich nur in ein Bankkonto importieren, nicht in eine Kasse.', 'konto');
        }
        if (!$konto->active) {
            throw new BankRuleViolation(sprintf('Das Konto „%s“ ist deaktiviert und nimmt keine Importe an.', $konto->data->name), 'konto');
        }

        return $konto;
    }

    /**
     * The balance the application knows for the start of the file's first
     * booking day: opening balance plus the stored bookings from the opening
     * date up to the day before. Null (with the reason) when it cannot be
     * compared.
     *
     * @return array{?int, ?string}
     */
    private function kontostandVorBeginn(Vault $vault, ImportDatei $datei, ?BankAccount $konto): array
    {
        $beginn = $datei->zeitraum()[0] ?? null;
        if (!$datei->hatSalden() || $beginn === null) {
            return [null, null];
        }
        if ($konto === null) {
            return [null, 'Der Anschluss an den Kontostand lässt sich erst prüfen, wenn das Konto feststeht.'];
        }
        if ($beginn->format('Y-m-d') < $konto->openingDate->format('Y-m-d')) {
            return [null, sprintf(
                'Die Datei beginnt vor dem Stichtag des Kontos (%s) – der Anschluss an den Anfangssaldo lässt sich nicht prüfen.',
                $konto->openingDate->format('d.m.Y'),
            )];
        }

        $stand = $konto->openingBalance;
        foreach ($this->buchungen->between($konto->id, $konto->openingDate, $beginn) as $buchung) {
            $stand += $this->betrag($vault, $buchung);
        }

        return [$stand, null];
    }

    private function betrag(Vault $vault, BankTransactionRecord $buchung): int
    {
        $json = FieldCipher::decrypt($vault->openDataKey($buchung->dekSealed), $buchung->dataEnc, new FieldContext(self::TABLE, $buchung->id, 'data_enc'));
        $daten = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        return is_array($daten) && is_int($daten['amount'] ?? null) ? $daten['amount'] : 0;
    }

    /** Only the base name, at most 200 characters - it is shown, never used as a path. */
    private static function dateiname(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr($name === '' ? 'kontoauszug' : $name, 0, 200);
    }
}
