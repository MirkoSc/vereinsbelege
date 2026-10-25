<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Domain\BankAccount;
use App\Domain\BankAccountData;
use App\Domain\BankAccountKind;
use App\Domain\InvoiceDirection;
use App\Domain\Zugriffsbereich;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * The session cache of report input (issue #72, docs/spec/
 * 05-auswertung-und-export.md section 1: "Ergebnis je Session kurz
 * zwischengespeichert (verschlüsselt in der Session, nicht in der DB)").
 * Sits in front of Auswertungsdaten with the same interface, so the
 * dashboard (#72), "Wohin fließt das Geld" (#73) and the Kassenprüfung
 * (#74) share one cache without knowing about it.
 *
 * What is cached is the decrypted INPUT (report lines, account movements),
 * not finished figures - every page computes its own figures from it with
 * ReportService, which narrows to period and scope once more.
 *
 * Encryption like the full-text index (03 section 10): one random key per
 * session, sealed to the vault's public key (opening it needs the unlocked
 * vault, i.e. the cookie `__Host-vk`); each entry gzip-compressed, padded
 * and AEAD-encrypted under it. The session files alone open nothing.
 *
 *     $_SESSION['auswertung_cache'] = [v, benutzer, stand, schluessel,
 *                                      eintraege => [id => [zeit, chiffrat]]]
 *
 * An entry is used only when ALL of these hold - otherwise it is rebuilt,
 * never patched (a stale figure after a change would be a defect, a
 * rebuild only costs time):
 * - same account (benutzer) and the sealed key opens with this vault;
 * - the Datenstand fingerprint is unchanged since the entry was built (it
 *   is taken BEFORE loading, so a write racing the load makes the entry
 *   look older than it is - a miss next time, never a stale hit);
 * - same basis, period and Zugriffsbereich (they are the entry's id, and
 *   the id is inside the ciphertext as well, so an entry cannot be moved
 *   to another slot);
 * - younger than LEBENSDAUER.
 * Bounded: at most MAX_EINTRAEGE entries and MAX_BYTES of ciphertext, the
 * oldest goes first; an entry larger than that is simply not kept.
 *
 * No state of its own beyond the services: everything is in $_SESSION. A
 * logout destroys the session and the cache with it.
 */
final readonly class AuswertungsCache implements Auswertungsquelle
{
    public const int VERSION = 1;

    /** Seconds an entry is used at most ("kurz zwischengespeichert"). */
    public const int LEBENSDAUER = 1800;

    public const int MAX_EINTRAEGE = 8;

    /**
     * Total ciphertext kept in the session - the session is read on every
     * request. A year of a club's bookings packs to a few dozen KiB.
     */
    public const int MAX_BYTES = 524_288;

    private const string SESSION_KEY = 'auswertung_cache';

    private const string TABELLE = 'auswertung_cache';

    private const string SPALTE = 'eintrag';

    private const int FORMAT = 1;

    /** Padding block: the length of an entry tells little about its content. */
    private const int BLOCK = 1024;

    /** Upper bound when inflating - far above a year of a club. */
    private const int MAX_KLARTEXT = 64 * 1024 * 1024;

    /**
     * @param \Closure(): int|null $uhr seconds since the epoch; tests pass their own
     */
    public function __construct(
        private Auswertungsquelle $quelle,
        private Datenstand $stand,
        private int $benutzerId,
        private ?\Closure $uhr = null,
    ) {
    }

    public function rechner(): ReportService
    {
        return $this->quelle->rechner();
    }

    public function grundlage(Vault $vault, Grundlage $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich): Auswertungsgrundlage
    {
        return $this->mitCache(
            $vault,
            self::id('grundlage', $grundlage->value, $zeitraum, $bereich),
            fn(): Auswertungsgrundlage => $this->quelle->grundlage($vault, $grundlage, $zeitraum, $bereich),
            self::grundlageKodieren(...),
            static fn(array $d): ?Auswertungsgrundlage => self::grundlageDekodieren($d, $grundlage, $zeitraum),
        );
    }

    public function kontobewegungen(Vault $vault, Zeitraum $zeitraum, Zugriffsbereich $bereich): array
    {
        return $this->mitCache(
            $vault,
            self::id('konten', '', $zeitraum, $bereich),
            fn(): array => $this->quelle->kontobewegungen($vault, $zeitraum, $bereich),
            self::kontenKodieren(...),
            self::kontenDekodieren(...),
        );
    }

    /**
     * Entry $id from the session - or, when it is missing, expired, does not
     * open or does not decode, freshly loaded with $laden and kept in its
     * place.
     *
     * @template T
     *
     * @param \Closure(): T $laden
     * @param \Closure(T): array<string, mixed> $kodieren
     * @param \Closure(array<string, mixed>): (T|null) $dekodieren
     *
     * @return T
     */
    private function mitCache(Vault $vault, string $id, \Closure $laden, \Closure $kodieren, \Closure $dekodieren): mixed
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return $laden();
        }
        // Before loading - see the class comment.
        $stand = $this->stand->fingerabdruck();
        if ($stand === null) {
            unset($_SESSION[self::SESSION_KEY]);

            return $laden();
        }

        $zustand = $this->zustand($vault, $stand);
        $jetzt = $this->jetzt();
        $eintrag = $zustand['eintraege'][$id] ?? null;
        if ($eintrag !== null && $jetzt - $eintrag['zeit'] <= self::LEBENSDAUER && $eintrag['zeit'] <= $jetzt) {
            $daten = self::entschluesseln($zustand['schluessel'], $eintrag['chiffrat'], $id);
            $ergebnis = $daten === null ? null : $dekodieren($daten);
            if ($ergebnis !== null) {
                return $ergebnis;
            }
        }
        unset($zustand['eintraege'][$id]);

        $ergebnis = $laden();
        $chiffrat = self::verschluesseln($zustand['schluessel'], ['id' => $id, 'f' => self::FORMAT, 'd' => $kodieren($ergebnis)]);
        if (strlen($chiffrat) <= self::MAX_BYTES) {
            $zustand['eintraege'][$id] = ['zeit' => $jetzt, 'chiffrat' => $chiffrat];
        }
        $this->merke($vault, self::begrenzt($zustand, $jetzt));

        return $ergebnis;
    }

    /**
     * The session's cache state with its key opened - a fresh, empty one
     * when there is none, it is of another account, another data state or
     * a format this release does not write, or its key does not open.
     *
     * @return array{stand: string, schluessel: DataKey, eintraege: array<string, array{zeit: int, chiffrat: string}>}
     */
    private function zustand(Vault $vault, string $stand): array
    {
        $wert = $_SESSION[self::SESSION_KEY] ?? null;
        $leer = ['stand' => $stand, 'schluessel' => DataKey::generate(), 'eintraege' => []];
        if (!is_array($wert) || ($wert['v'] ?? null) !== self::VERSION || ($wert['benutzer'] ?? null) !== $this->benutzerId
            || !is_string($wert['stand'] ?? null) || !hash_equals($wert['stand'], $stand)
            || !is_string($wert['schluessel'] ?? null) || !is_array($wert['eintraege'] ?? null)) {
            return $leer;
        }
        try {
            $schluessel = $vault->openDataKey($wert['schluessel']);
        } catch (CryptoException) {
            return $leer;
        }

        $eintraege = [];
        foreach ($wert['eintraege'] as $id => $eintrag) {
            if (is_string($id) && is_array($eintrag) && is_int($eintrag['zeit'] ?? null) && is_string($eintrag['chiffrat'] ?? null)) {
                $eintraege[$id] = ['zeit' => $eintrag['zeit'], 'chiffrat' => $eintrag['chiffrat']];
            }
        }

        return ['stand' => $stand, 'schluessel' => $schluessel, 'eintraege' => $eintraege];
    }

    /**
     * @param array{stand: string, schluessel: DataKey, eintraege: array<string, array{zeit: int, chiffrat: string}>} $zustand
     */
    private function merke(Vault $vault, array $zustand): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'v' => self::VERSION,
            'benutzer' => $this->benutzerId,
            'stand' => $zustand['stand'],
            'schluessel' => $vault->sealDataKey($zustand['schluessel']),
            'eintraege' => $zustand['eintraege'],
        ];
    }

    /**
     * Without expired entries, then oldest first out until both limits hold.
     *
     * @param array{stand: string, schluessel: DataKey, eintraege: array<string, array{zeit: int, chiffrat: string}>} $zustand
     *
     * @return array{stand: string, schluessel: DataKey, eintraege: array<string, array{zeit: int, chiffrat: string}>}
     */
    private static function begrenzt(array $zustand, int $jetzt): array
    {
        $eintraege = array_filter(
            $zustand['eintraege'],
            static fn(array $e): bool => $jetzt - $e['zeit'] <= self::LEBENSDAUER && $e['zeit'] <= $jetzt,
        );
        // Newest first; a stable sort keeps the order of equal times.
        uasort($eintraege, static fn(array $a, array $b): int => $b['zeit'] <=> $a['zeit']);
        $behalten = [];
        $bytes = 0;
        foreach ($eintraege as $id => $e) {
            if (count($behalten) >= self::MAX_EINTRAEGE || $bytes + strlen($e['chiffrat']) > self::MAX_BYTES) {
                continue;
            }
            $behalten[$id] = $e;
            $bytes += strlen($e['chiffrat']);
        }

        return [...$zustand, 'eintraege' => $behalten];
    }

    private function jetzt(): int
    {
        return $this->uhr === null ? time() : ($this->uhr)();
    }

    /**
     * The id of an entry: what it holds, its period and the reader's scope.
     * A hash - neither the scope nor the dates need to stand in the session.
     */
    private static function id(string $art, string $grundlage, Zeitraum $zeitraum, Zugriffsbereich $bereich): string
    {
        $kostenstellen = $bereich->kostenstellen;
        if ($kostenstellen !== null) {
            $kostenstellen = array_values(array_unique(array_map(intval(...), $kostenstellen)));
            sort($kostenstellen);
        }

        return hash('sha256', json_encode([
            $art,
            $grundlage,
            $zeitraum->von->format('Y-m-d'),
            $zeitraum->bis->format('Y-m-d'),
            $kostenstellen,
            $bereich->von?->format('Y-m-d'),
            $bereich->bis?->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $inhalt
     */
    private static function verschluesseln(DataKey $schluessel, array $inhalt): string
    {
        $json = json_encode($inhalt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $gepackt = gzdeflate($json, 6);
        if ($gepackt === false) {
            throw new \RuntimeException('Auswertungs-Zwischenspeicher lässt sich nicht packen.');
        }

        return FieldCipher::encrypt($schluessel, sodium_pad($gepackt, self::BLOCK), new FieldContext(self::TABELLE, 1, self::SPALTE));
    }

    /**
     * @return array<string, mixed>|null the payload, null when it does not
     *         open, decode or belong to entry $id
     */
    private static function entschluesseln(DataKey $schluessel, string $chiffrat, string $id): ?array
    {
        try {
            $gepackt = sodium_unpad(FieldCipher::decrypt($schluessel, $chiffrat, new FieldContext(self::TABELLE, 1, self::SPALTE)), self::BLOCK);
        } catch (CryptoException | \SodiumException) {
            return null;
        }
        $json = @gzinflate($gepackt, self::MAX_KLARTEXT);
        if ($json === false) {
            return null;
        }
        try {
            $inhalt = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($inhalt) || ($inhalt['f'] ?? null) !== self::FORMAT || !is_string($inhalt['id'] ?? null)
            || !hash_equals($inhalt['id'], $id) || !is_array($inhalt['d'] ?? null)) {
            return null;
        }

        return $inhalt['d'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function grundlageKodieren(Auswertungsgrundlage $g): array
    {
        return [
            'u' => $g->unlesbar,
            'w' => $g->fremdwaehrung,
            'p' => array_map(static fn(Posten $p): array => [
                $p->datum->format('Y-m-d'),
                $p->art->value,
                $p->betrag,
                $p->kategorieId,
                $p->kostenstelleId,
                $p->lieferantId,
                $p->sphaere,
                $p->kontoId,
                $p->belegId,
                $p->buchungId,
            ], $g->posten),
        ];
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function grundlageDekodieren(array $d, Grundlage $grundlage, Zeitraum $zeitraum): ?Auswertungsgrundlage
    {
        if (!is_int($d['u'] ?? null) || !is_int($d['w'] ?? null) || !is_array($d['p'] ?? null)) {
            return null;
        }
        $posten = [];
        foreach ($d['p'] as $z) {
            if (!is_array($z) || count($z) !== 10) {
                return null;
            }
            [$datum, $art, $betrag, $kategorie, $kostenstelle, $lieferant, $sphaere, $konto, $beleg, $buchung] = $z;
            $art = is_string($art) ? InvoiceDirection::tryFrom($art) : null;
            $tag = is_string($datum) ? self::tag($datum) : null;
            if ($tag === null || $art === null || !is_int($betrag) || !self::idOderNull($kategorie) || !self::idOderNull($kostenstelle)
                || !self::idOderNull($lieferant) || !($sphaere === null || is_string($sphaere)) || !self::idOderNull($konto)
                || !self::idOderNull($beleg) || !self::idOderNull($buchung)) {
                return null;
            }
            $posten[] = new Posten($tag, $art, $betrag, $kategorie, $kostenstelle, $lieferant, $sphaere, $konto, $beleg, $buchung);
        }

        return new Auswertungsgrundlage($grundlage, $zeitraum, $posten, $d['u'], $d['w']);
    }

    /**
     * @param array{konten: list<BankAccount>, bewegungen: list<Kontobewegung>, unlesbar: int} $k
     *
     * @return array<string, mixed>
     */
    private static function kontenKodieren(array $k): array
    {
        return [
            'u' => $k['unlesbar'],
            'k' => array_map(static fn(BankAccount $a): array => [
                $a->id,
                $a->kind->value,
                $a->data->toPayload(),
                $a->openingBalance,
                $a->currency,
                $a->openingDate->format('Y-m-d'),
                $a->active,
                $a->createdAt->format(\DateTimeInterface::ATOM),
                $a->updatedAt->format(\DateTimeInterface::ATOM),
            ], $k['konten']),
            'b' => array_map(static fn(Kontobewegung $b): array => [$b->kontoId, $b->datum->format('Y-m-d'), $b->betrag], $k['bewegungen']),
        ];
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array{konten: list<BankAccount>, bewegungen: list<Kontobewegung>, unlesbar: int}|null
     */
    private static function kontenDekodieren(array $d): ?array
    {
        if (!is_int($d['u'] ?? null) || !is_array($d['k'] ?? null) || !is_array($d['b'] ?? null)) {
            return null;
        }
        $konten = [];
        foreach ($d['k'] as $z) {
            if (!is_array($z) || count($z) !== 9) {
                return null;
            }
            [$id, $art, $daten, $anfang, $waehrung, $eroeffnet, $aktiv, $angelegt, $geaendert] = $z;
            $art = is_string($art) ? BankAccountKind::tryFrom($art) : null;
            $eroeffnet = is_string($eroeffnet) ? self::tag($eroeffnet) : null;
            $angelegt = is_string($angelegt) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $angelegt) : false;
            $geaendert = is_string($geaendert) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $geaendert) : false;
            if (!is_int($id) || $art === null || !is_array($daten) || !is_int($anfang) || !is_string($waehrung) || $eroeffnet === null
                || !is_bool($aktiv) || $angelegt === false || $geaendert === false) {
                return null;
            }
            $konten[] = new BankAccount($id, $art, BankAccountData::fromPayload($daten), $anfang, $waehrung, $eroeffnet, $aktiv, $angelegt, $geaendert);
        }
        $bewegungen = [];
        foreach ($d['b'] as $z) {
            if (!is_array($z) || count($z) !== 3 || !is_int($z[0]) || !is_string($z[1]) || !is_int($z[2])) {
                return null;
            }
            $tag = self::tag($z[1]);
            if ($tag === null) {
                return null;
            }
            $bewegungen[] = new Kontobewegung($z[0], $tag, $z[2]);
        }

        return ['konten' => $konten, 'bewegungen' => $bewegungen, 'unlesbar' => $d['u']];
    }

    private static function tag(string $wert): ?\DateTimeImmutable
    {
        $tag = \DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $tag === false || $tag->format('Y-m-d') !== $wert ? null : $tag;
    }

    private static function idOderNull(mixed $wert): bool
    {
        return $wert === null || is_int($wert);
    }
}
