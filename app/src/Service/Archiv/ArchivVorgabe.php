<?php

declare(strict_types=1);

namespace App\Service\Archiv;

use App\Domain\InvoiceDirection;
use App\Domain\Zahlungsweg;

/**
 * What the archive import knows about one imported document (issue
 * #77/M12-3, docs/spec/05-auswertung-und-export.md section 3) - stored as
 * the document's `vorgabe` artifact (App\Domain\ArtifactKind::Vorgabe),
 * encrypted under its own DEK, schema `archiv-v1`.
 *
 * On the review page it is laid over whatever read the receipt
 * automatically (an e-invoice now, the AI with M7-5): the archive values
 * were set by a person when the archive was sorted, so they take priority;
 * where an automatic value differs, the page says so (ueberlagern()). The
 * `Kasse` folder prefills the Zahlungsweg "bar bezahlt" (M10-1, issue
 * #65) and says so in a hint.
 */
final readonly class ArchivVorgabe
{
    public const string SCHEMA = 'archiv-v1';

    /**
     * @param list<string> $hinweise German, from reading the path
     */
    public function __construct(
        public string $pfad,
        public ?int $lieferantId = null,
        public ?string $lieferantName = null,
        public ?\DateTimeImmutable $datum = null,
        public ?string $nr = null,
        public ?int $kategorieId = null,
        public InvoiceDirection $richtung = InvoiceDirection::Ausgabe,
        public bool $richtungAusOrdner = false,
        public bool $kasse = false,
        public bool $wiedervorlage = false,
        public array $hinweise = [],
    ) {
    }

    /**
     * @param list<string> $hinweise on top of the path's own
     */
    public static function aus(ArchivVorbelegung $v, ?int $lieferantId, array $hinweise = []): self
    {
        return new self(
            pfad: $v->pfad,
            lieferantId: $lieferantId,
            lieferantName: $v->lieferant,
            datum: $v->datum,
            nr: $v->nr,
            kategorieId: $v->kategorieId,
            richtung: $v->richtung,
            richtungAusOrdner: $v->richtungAusOrdner,
            kasse: $v->kasse,
            wiedervorlage: $v->wiedervorlage,
            hinweise: [...$v->hinweise, ...$hinweise],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'pfad' => $this->pfad,
            'lieferant_id' => $this->lieferantId,
            'lieferant_name' => $this->lieferantName,
            'datum' => $this->datum?->format('Y-m-d'),
            'nr' => $this->nr,
            'kategorie_id' => $this->kategorieId,
            'richtung' => $this->richtung->value,
            'richtung_aus_ordner' => $this->richtungAusOrdner,
            'kasse' => $this->kasse,
            'wiedervorlage' => $this->wiedervorlage,
            'hinweise' => $this->hinweise,
        ];
    }

    /**
     * Null for anything that is not an `archiv-v1` payload.
     *
     * @param array<string, mixed> $werte
     */
    public static function fromArray(array $werte): ?self
    {
        if (($werte['schema'] ?? null) !== self::SCHEMA || !is_string($werte['pfad'] ?? null)) {
            return null;
        }
        $text = static fn(string $k): ?string => is_string($werte[$k] ?? null) && $werte[$k] !== '' ? $werte[$k] : null;
        $id = static fn(string $k): ?int => is_int($werte[$k] ?? null) ? $werte[$k] : null;
        $datum = $text('datum') === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $text('datum'));

        return new self(
            pfad: $werte['pfad'],
            lieferantId: $id('lieferant_id'),
            lieferantName: $text('lieferant_name'),
            datum: $datum === false ? null : $datum,
            nr: $text('nr'),
            kategorieId: $id('kategorie_id'),
            richtung: InvoiceDirection::tryFrom((string) $text('richtung')) ?? InvoiceDirection::Ausgabe,
            richtungAusOrdner: ($werte['richtung_aus_ordner'] ?? false) === true,
            kasse: ($werte['kasse'] ?? false) === true,
            wiedervorlage: ($werte['wiedervorlage'] ?? false) === true,
            hinweise: array_values(array_filter(is_array($werte['hinweise'] ?? null) ? $werte['hinweise'] : [], is_string(...))),
        );
    }

    /**
     * Lays the archive values over the review form. A field counts as "read
     * automatically" when it differs from the empty form; where such a value
     * differs from the archive's, the archive wins and a hint says what was
     * replaced.
     *
     * @param array<string, string> $leer the form without any receipt (Pruefung::felder())
     * @param array<string, string> $gelesen the form after the automatic reading
     * @param string $quelle what read it ("E-Rechnung", later "KI"), for the hints
     * @return array{felder: array<string, string>, hinweise: list<string>}
     */
    public function ueberlagern(array $leer, array $gelesen, string $quelle): array
    {
        $felder = $gelesen;
        $hinweise = [sprintf('Aus dem Archiv-Import: „%s“.', $this->pfad)];
        $gelesenWert = static fn(string $feld): ?string => ($gelesen[$feld] ?? '') !== ($leer[$feld] ?? '') ? ($gelesen[$feld] ?? '') : null;

        $setze = function (string $feld, string $wert, string $bezeichnung, ?string $anzeigeArchiv = null, ?string $anzeigeGelesen = null) use (&$felder, &$hinweise, $gelesenWert, $quelle): void {
            $vorher = $gelesenWert($feld);
            if ($vorher !== null && $vorher !== $wert) {
                $hinweise[] = sprintf(
                    'Archiv: %s %s, %s: %s – Archivwert übernommen.',
                    $bezeichnung,
                    $anzeigeArchiv ?? $wert,
                    $quelle,
                    $anzeigeGelesen ?? $vorher,
                );
            }
            $felder[$feld] = $wert;
        };

        if ($this->richtungAusOrdner) {
            $setze('richtung', $this->richtung->value, 'Richtung', $this->richtung->label(), InvoiceDirection::tryFrom($gelesen['richtung'] ?? '')?->label());
        }
        if ($this->datum !== null) {
            $alt = \DateTimeImmutable::createFromFormat('!Y-m-d', $gelesen['datum'] ?? '');
            $setze('datum', $this->datum->format('Y-m-d'), 'Datum', $this->datum->format('d.m.Y'), $alt === false ? null : $alt->format('d.m.Y'));
        }
        if ($this->nr !== null) {
            $setze('nummer', $this->nr, 'Rechnungsnummer');
        }
        if ($this->lieferantId !== null) {
            $vorher = $gelesenWert('lieferant');
            $neuName = $gelesenWert('lieferant_neu_name');
            if (($vorher !== null && $vorher !== (string) $this->lieferantId) || $neuName !== null) {
                $hinweise[] = sprintf('Archiv: %s „%s“, %s nennt einen anderen – Archivwert übernommen.', $this->richtung->partner(), $this->lieferantName ?? '', $quelle);
            }
            $felder['lieferant'] = (string) $this->lieferantId;
            $felder['lieferant_neu_name'] = '';
            $felder['lieferant_neu_iban'] = '';
        }
        if ($this->kategorieId !== null) {
            $felder['kategorie'] = (string) $this->kategorieId;
        }
        if ($this->kasse) {
            $felder['zahlungsweg'] = Zahlungsweg::Bar->value;
            $hinweise[] = 'Archiv: Ordner „Kasse“ – bar bezahlt, Zahlungsweg vorbelegt.';
        }

        return ['felder' => $felder, 'hinweise' => [...$hinweise, ...$this->hinweise]];
    }
}
