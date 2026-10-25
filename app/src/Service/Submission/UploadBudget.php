<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Repository\UploadBudgetRepository;

/**
 * How many bytes the public upload may put on the server (security review
 * N-04, issue #242, docs/spec/01-sicherheit.md section 5) - the rate limit
 * counts uploads, this counts what they weigh. Three budgets, all keyed by
 * a hash only:
 *
 *   - **open** per address: what is announced but not finished yet - the
 *     bound on `var/tmp`. Reserved with the size create() announces
 *     (App\Service\Upload\UploadService never accepts more for an
 *     upload) and given back on finish, abort, or by the cron once it gives
 *     up on the upload (App\Service\Cron\UploadBudgetCleanupTask);
 *   - per address and **hour**: what one address may send, announced size,
 *     not given back;
 *   - for everybody per calendar **day** (Europe/Berlin): only bytes that
 *     were actually stored, charged when an upload becomes a blob. Announcing
 *     alone never touches it - closing the form for everybody has to cost
 *     real bytes, not a few cheap requests (coordinator review of #242).
 *
 * An IPv6 address counts as its /64, since one connection usually gets a
 * whole /64 and can rotate within it freely. While the free space of
 * `shared/var` is below a threshold, public uploads pause altogether
 * (App\Service\Submission\FreierSpeicher - when the host does not tell,
 * the budgets are the only bound).
 *
 * Signed-in functions never pass through here, so they keep working when
 * the budget or the disk runs out.
 */
final readonly class UploadBudget
{
    public const string PURPOSE_IP = 'einreichung_bytes_ip';
    public const string PURPOSE_OFFEN = 'einreichung_bytes_offen';
    public const string PURPOSE_GESAMT = 'einreichung_bytes_gesamt';

    private const string GLOBAL_VALUE = 'global';

    private const int FENSTER_IP_SEKUNDEN = 3600;

    public function __construct(
        private UploadBudgetRepository $rows,
        private EinreichungsEinstellungen $einstellungen,
        private FreierSpeicher $speicher,
    ) {
    }

    /**
     * Before upload $uploadId is opened: null when it may start (its
     * reservation is then held until freigeben()), otherwise why not -
     * and then nothing is counted.
     */
    public function reservieren(string $ip, string $uploadId, int $bytes, ?\DateTimeImmutable $now = null): ?EinreichungAbgelehnt
    {
        $now ??= new \DateTimeImmutable();

        $knapp = $this->pruefeSpeicher();
        if ($knapp !== null) {
            return $knapp;
        }

        // An empty file costs nothing; UploadService refuses it with its own
        // message right after.
        if ($bytes < 1) {
            return null;
        }

        // A day that is already full turns the upload away before it
        // starts; charged is the day only once bytes are stored (verbuchen()).
        [$tagStart] = self::tagesFenster($now);
        if ($this->rows->verbraucht(self::globalerSchluessel(), $tagStart) + $bytes > $this->einstellungen->budgetGesamtBytes()) {
            return EinreichungAbgelehnt::BudgetGesamt;
        }

        // No address (the command line) is no shared counter for everybody -
        // the same rule as App\Service\RateLimiter; the day still counts.
        if ($ip === '') {
            return null;
        }

        $bereich = self::ipBereich($ip);
        $stunde = self::schluessel(self::PURPOSE_IP, $bereich);
        [$ipStart, $ipEnde] = self::stundenFenster($now);
        if (!$this->rows->reservieren($stunde, $ipStart, $ipEnde, $bytes, $this->einstellungen->budgetIpBytes())) {
            return EinreichungAbgelehnt::BudgetIp;
        }

        $offen = self::schluessel(self::PURPOSE_OFFEN, $bereich);
        if (!$this->rows->offenReservieren($uploadId, $offen, $bytes, $this->einstellungen->offenIpBytes(), $now)) {
            $this->rows->freigeben($stunde, $bytes);

            return EinreichungAbgelehnt::BudgetIp;
        }

        return null;
    }

    /**
     * Gives the open reservation of an upload back - it was finished,
     * aborted or thrown away. Idempotent.
     */
    public function freigeben(string $uploadId): void
    {
        $this->rows->offenFreigeben($uploadId);
    }

    /**
     * Charges the bytes of an upload that is about to become a blob to
     * today's budget - null when they fit.
     */
    public function verbuchen(int $bytes, ?\DateTimeImmutable $now = null): ?EinreichungAbgelehnt
    {
        [$tagStart, $tagEnde] = self::tagesFenster($now ?? new \DateTimeImmutable());

        return $this->rows->reservieren(self::globalerSchluessel(), $tagStart, $tagEnde, max(1, $bytes), $this->einstellungen->budgetGesamtBytes())
            ? null
            : EinreichungAbgelehnt::BudgetGesamt;
    }

    /** Takes a charge of verbuchen() back - storing the blob failed after all. */
    public function zurueckbuchen(int $bytes): void
    {
        $this->rows->freigeben(self::globalerSchluessel(), max(1, $bytes));
    }

    /**
     * The free-space threshold alone - for the step that turns an upload
     * into a blob (App\Api\EinreichungUploadController::finish()).
     */
    public function pruefeSpeicher(): ?EinreichungAbgelehnt
    {
        $frei = $this->speicher->bytes();

        return $frei !== null && $frei < $this->einstellungen->minFreiBytes() ? EinreichungAbgelehnt::SpeicherKnapp : null;
    }

    /** Today's numbers, for the admin page and the system check. */
    public function stand(?\DateTimeImmutable $now = null): UploadBudgetStand
    {
        [$tagStart] = self::tagesFenster($now ?? new \DateTimeImmutable());

        return new UploadBudgetStand(
            verbrauchtHeute: $this->rows->verbraucht(self::globalerSchluessel(), $tagStart),
            budgetHeute: $this->einstellungen->budgetGesamtBytes(),
            groessteDatei: $this->einstellungen->maxDateiBytes(),
            freiBytes: $this->speicher->bytes(),
            minFreiBytes: $this->einstellungen->minFreiBytes(),
        );
    }

    /**
     * What an address counts as: IPv4 as it is, IPv6 as its /64 (an
     * IPv4-mapped IPv6 address as the IPv4 address inside). Anything that
     * is not an address stays as it is - it is hashed either way.
     */
    public static function ipBereich(string $ip): string
    {
        $binaer = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($binaer === false || strlen($binaer) !== 16) {
            return $ip;
        }

        if (str_starts_with($binaer, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($binaer, 12));
        }

        return bin2hex(substr($binaer, 0, 8)) . '/64';
    }

    private static function schluessel(string $purpose, string $value): string
    {
        return hash('sha256', $purpose . ':' . $value, true);
    }

    private static function globalerSchluessel(): string
    {
        return self::schluessel(self::PURPOSE_GESAMT, self::GLOBAL_VALUE);
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function stundenFenster(\DateTimeImmutable $now): array
    {
        $timestamp = $now->getTimestamp();
        $start = $now->setTimestamp($timestamp - ($timestamp % self::FENSTER_IP_SEKUNDEN));

        return [$start, $start->setTimestamp($start->getTimestamp() + self::FENSTER_IP_SEKUNDEN)];
    }

    /**
     * Midnight to midnight in the application's time zone - a day with a
     * clock change is 23 or 25 hours long, as a calendar day is.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function tagesFenster(\DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0);

        return [$start, $start->add(new \DateInterval('P1D'))];
    }
}
