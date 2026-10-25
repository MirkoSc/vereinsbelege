<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Repository\UploadBudgetRepository;

/**
 * How many bytes the public upload may put on the server (security review
 * N-04, issue #242, docs/spec/01-sicherheit.md section 5) - the rate limit
 * counts uploads, this counts what they weigh.
 *
 *   - per IP address and hour; an IPv6 address counts as its /64, since one
 *     connection usually gets a whole /64 and can rotate within it freely;
 *   - for all public uploads together per calendar day (Europe/Berlin);
 *   - and while the free space of `shared/var` is below a threshold, public
 *     uploads pause altogether (App\Service\Submission\FreierSpeicher -
 *     when the host does not tell, the daily budget is the only bound).
 *
 * Reserved is the size create() announces, before a single byte arrives:
 * App\Service\Upload\UploadService never accepts more than that for an
 * upload, so the reservation bounds both the chunks in `var/tmp` and the
 * blob that becomes of them. Nothing is given back on abort or once the
 * cron removes an unclaimed upload - the budget is what was sent, not what
 * stayed.
 *
 * Signed-in functions never pass through here, so they keep working when
 * the budget or the disk runs out.
 */
final readonly class UploadBudget
{
    public const string PURPOSE_IP = 'einreichung_bytes_ip';
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
     * Counts $bytes against both budgets, or neither: null when the upload
     * may start, otherwise why not.
     */
    public function reservieren(string $ip, int $bytes, ?\DateTimeImmutable $now = null): ?EinreichungAbgelehnt
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

        // No address (the command line) is no shared counter for everybody -
        // the same rule as App\Service\RateLimiter; the day still counts.
        $ipSchluessel = $ip === '' ? null : self::schluessel(self::PURPOSE_IP, self::ipBereich($ip));
        [$ipStart, $ipEnde] = self::stundenFenster($now);
        if ($ipSchluessel !== null && !$this->rows->reservieren($ipSchluessel, $ipStart, $ipEnde, $bytes, $this->einstellungen->budgetIpBytes())) {
            return EinreichungAbgelehnt::BudgetIp;
        }

        [$tagStart, $tagEnde] = self::tagesFenster($now);
        if (!$this->rows->reservieren(self::globalerSchluessel(), $tagStart, $tagEnde, $bytes, $this->einstellungen->budgetGesamtBytes())) {
            if ($ipSchluessel !== null) {
                $this->rows->freigeben($ipSchluessel, $bytes);
            }

            return EinreichungAbgelehnt::BudgetGesamt;
        }

        return null;
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
