<?php

declare(strict_types=1);

namespace App\Service\Suche;

use App\Domain\ArtifactKind;
use App\Domain\Zugriffsbereich;
use App\Repository\DocumentArtifactRepository;
use App\Repository\DocumentRepository;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\DataKey;
use App\Service\Crypto\FieldCipher;
use App\Service\Crypto\FieldContext;
use App\Service\Crypto\Vault;

/**
 * Full-text search over the recognised text of the receipts with an index
 * that exists only for one session (issue #94, docs/spec/
 * 03-erfassung-und-ki.md section 10).
 *
 * Nothing searchable is stored permanently: no plaintext index, no word
 * hashes in the database or in files - those would tell a database reader
 * which words are frequent. Instead, after unlocking, a short step chain
 * (schritt(), driven by public/js/suche.js; CLAUDE.md section 1) decrypts
 * the texts of the documents the viewer may see, normalises them
 * (Suchtext) and writes them as encrypted parts into a directory of this
 * session (SuchindexSpeicher). The key of these parts is random per index
 * and sits in the session only sealed to the vault (SitzungsSuchindex); a
 * search decrypts the parts one by one and compares in PHP.
 *
 * Which documents need (re)indexing is told by `document_artifact` ids: the
 * cursor is the highest artifact id of the kinds the sources read
 * (Suchtextquelle::arten()) that the index has taken in. A new run of a job
 * writes new rows, so new text puts its document into the next step; the
 * newer entry overrides the older one at search time (parts are read newest
 * first). The first build is the same thing from cursor 0.
 *
 * Scope (01 section 4): only documents within the viewer's `inbox.view`
 * Zugriffsbereich are indexed, and every hit is checked against it again in
 * SQL before it is shown - a scope that narrowed since keeps hiding rows. A
 * scope that changed at all, an old document that entered or left a
 * cost-center scope, another account or a vault that no longer opens the
 * key throws the index away and starts over.
 */
final readonly class Volltextsuche
{
    /** Documents per step: decrypting their artifacts stays well within a short request. */
    public const int SCHRITT_DOKUMENTE = 40;

    public const int MAX_TREFFER = 50;

    /** Parts are padded to a multiple of this, so their size says little about the text in them. */
    private const int BLOCK = 4096;

    /**
     * Upper bound for one decompressed part: SCHRITT_DOKUMENTE times
     * Suchtext::MAX_BYTES_JE_DOKUMENT is 1.25 MiB, JSON escaping at most
     * doubles that (normalised texts hold no control characters).
     */
    private const int MAX_TEIL_BYTES = 8 * 1024 * 1024;

    private const int FORMAT = 1;

    private const string TABELLE = 'suchindex';

    private const string SPALTE = 'teil';

    /**
     * @param list<Suchtextquelle> $quellen
     */
    public function __construct(
        private DocumentRepository $documents,
        private DocumentArtifactRepository $artifacts,
        private SuchindexSpeicher $speicher,
        private SitzungsSuchindex $sitzung,
        private array $quellen,
    ) {
    }

    public function stand(int $benutzerId, Zugriffsbereich $bereich, Vault $vault): SuchindexStand
    {
        return $this->standVon($this->zustand($benutzerId, $bereich, $vault), $bereich);
    }

    /**
     * Takes up to SCHRITT_DOKUMENTE more documents into the index - the
     * step of the chain. Idempotent in effect: a step cut off before the
     * session was written is simply done again (the part is overwritten).
     */
    public function schritt(int $benutzerId, Zugriffsbereich $bereich, Vault $vault): SuchindexStand
    {
        $zustand = $this->zustand($benutzerId, $bereich, $vault) ?? $this->neu($benutzerId, $bereich);

        return $this->standVon($this->schrittMit($zustand, $bereich, $vault), $bereich);
    }

    /**
     * Brings the index up to date right here when that takes a single
     * step - a few new receipts since the last search, or a small club's
     * whole first build - so the page need not run the chain for it.
     */
    public function nachziehen(int $benutzerId, Zugriffsbereich $bereich, Vault $vault): SuchindexStand
    {
        $zustand = $this->zustand($benutzerId, $bereich, $vault);
        $stand = $this->standVon($zustand, $bereich);
        if (!$stand->aktuell() && $stand->offen <= self::SCHRITT_DOKUMENTE) {
            $stand = $this->standVon($this->schrittMit($zustand ?? $this->neu($benutzerId, $bereich), $bereich, $vault), $bereich);
        }

        return $stand;
    }

    /**
     * Searches the index - null when there is none that is built through
     * (the caller runs the chain first). A part that is missing or does not
     * decrypt throws the index away: it is a cache, rebuilt rather than
     * trusted.
     *
     * Two passes: every part is read once to find the matching documents
     * (their ids only); the excerpt is then cut only for the hits shown,
     * from the parts that hold them.
     */
    public function suche(int $benutzerId, Zugriffsbereich $bereich, Vault $vault, Suchanfrage $anfrage): ?Suchergebnis
    {
        $zustand = $this->zustand($benutzerId, $bereich, $vault);
        if ($zustand === null || !$zustand['fertig']) {
            return null;
        }
        if ($anfrage->leer()) {
            return new Suchergebnis([], false);
        }

        $gesehen = [];
        $kandidaten = [];
        for ($teil = $zustand['teile']; $teil >= 1; $teil--) {
            $eintraege = $this->teil($zustand, $teil);
            if ($eintraege === null) {
                $this->verwerfen();

                return null;
            }
            // Newest part first, so a document's newest entry is the one
            // that counts.
            foreach ($eintraege as [$id, $text]) {
                if (!isset($gesehen[$id])) {
                    $gesehen[$id] = true;
                    if ($anfrage->passt($text)) {
                        $kandidaten[$id] = $teil;
                    }
                }
            }
        }

        // Newest documents first, checked against the scope in SQL.
        krsort($kandidaten);
        $gezeigt = [];
        $mehr = false;
        foreach (array_chunk(array_keys($kandidaten), 200) as $ids) {
            foreach ($this->documents->inboxItems($ids, $bereich) as $item) {
                if (count($gezeigt) >= self::MAX_TREFFER) {
                    $mehr = true;
                    break 2;
                }
                $gezeigt[] = $item;
            }
        }

        $texte = [];
        $treffer = [];
        foreach ($gezeigt as $item) {
            $teil = $kandidaten[$item->document->id];
            if (!isset($texte[$teil])) {
                $texte[$teil] = [];
                foreach ($this->teil($zustand, $teil) ?? [] as [$id, $text]) {
                    $texte[$teil][$id] = $text;
                }
            }
            $treffer[] = new Suchtreffer($item, $anfrage->ausschnitt($texte[$teil][$item->document->id] ?? ''));
        }

        return new Suchergebnis($treffer, $mehr);
    }

    /** Throws this session's index away - logout, "neu aufbauen", a broken part. */
    public function verwerfen(): void
    {
        $this->sitzung->verwerfen($this->speicher);
    }

    /**
     * @param array{id: string, schluessel: DataKey, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>} $zustand
     *
     * @return array{id: string, schluessel: DataKey, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>}
     */
    private function schrittMit(array $zustand, Zugriffsbereich $bereich, Vault $vault): array
    {
        // Documents in the order of their newest artifact: the new cursor
        // is the newest artifact of the last one, and every document not
        // taken has a newer one - so each is indexed once per change.
        $dokumente = $this->artifacts->naechsteSuchdokumente($zustand['cursor'], $this->arten(), $bereich, self::SCHRITT_DOKUMENTE);

        // Worker uploads (issue #52) get their row id when announced but
        // become visible only on commit - possibly after the cursor passed
        // them. Those the cursor passed while still reserved are taken in
        // here once visible (review of #52, issue #53); a document indexed
        // again gets a newer entry, and the newest entry counts.
        // Still reserved first: one committed between the two queries is
        // then simply taken in next time, never lost in between.
        $reserviert = $this->artifacts->nochReserviert($zustand['nachholen']);
        $nachgeholt = $this->artifacts->sichtbarGeworden(array_values(array_diff($zustand['nachholen'], $reserviert)), $this->arten(), $bereich);
        $zustand['nachholen'] = $reserviert;

        $ids = array_values(array_unique([...$nachgeholt, ...array_column($dokumente, 'document_id')]));
        if ($ids !== []) {
            $eintraege = [];
            foreach ($ids as $id) {
                $eintraege[] = [$id, $this->text($id, $vault)];
            }
            $teil = $zustand['teile'] + 1;
            $this->speicher->schreibe($zustand['id'], $teil, $this->verschluesseln($zustand['schluessel'], $teil, $eintraege));
            $zustand['teile'] = $teil;
        }
        if ($dokumente !== []) {
            $zustand['cursor'] = $dokumente[array_key_last($dokumente)]['bis'];
            $zustand['nachholen'] = array_values(array_unique([
                ...$zustand['nachholen'],
                ...$this->artifacts->reservierteBis($zustand['cursor']),
            ]));
            sort($zustand['nachholen']);
        }
        if (count($dokumente) < self::SCHRITT_DOKUMENTE) {
            $zustand['fertig'] = true;
        }
        $zustand['mitglieder'] = $this->mitglieder($zustand['cursor'], $bereich, $zustand['nachholen']);
        $this->merke($zustand, $vault);

        return $zustand;
    }

    /**
     * @param array{fertig: bool, cursor: int, nachholen: list<int>}|null $zustand
     */
    private function standVon(?array $zustand, Zugriffsbereich $bereich): SuchindexStand
    {
        return new SuchindexStand(
            $zustand['fertig'] ?? false,
            $this->artifacts->zaehleSuchquellenNach($zustand['cursor'] ?? 0, $this->arten(), $bereich)
                + count($this->artifacts->sichtbarGeworden($zustand['nachholen'] ?? [], $this->arten(), $bereich)),
        );
    }

    /**
     * Which documents up to the cursor are in the scope - only for a
     * cost-center scope, the one scope whose members change with an old
     * document (its cost center is set later). A period scope is on
     * `created_at`, which never changes; no scope has nothing to change.
     * Former reservations not taken in yet ($nachholen) are left out, so
     * their commit alone does not look like a change of scope.
     *
     * @param list<int> $nachholen
     */
    private function mitglieder(int $cursor, Zugriffsbereich $bereich, array $nachholen): string
    {
        return $bereich->kostenstellen === null ? '' : $this->artifacts->suchdokumenteBis($cursor, $this->arten(), $bereich, $nachholen);
    }

    /**
     * The session's index with its key opened - or null when there is none
     * usable for this account, this scope and this vault (it is thrown
     * away then).
     *
     * @return array{id: string, schluessel: DataKey, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>}|null
     */
    private function zustand(int $benutzerId, Zugriffsbereich $bereich, Vault $vault): ?array
    {
        $gespeichert = $this->sitzung->lies();
        if ($gespeichert === null) {
            // Nothing, or an entry in a format this release does not write:
            // its files go too.
            $this->sitzung->verwerfen($this->speicher);

            return null;
        }
        if ($gespeichert['benutzer'] !== $benutzerId || !hash_equals($gespeichert['bereich'], self::fingerabdruck($bereich))) {
            $this->verwerfen();

            return null;
        }
        try {
            $schluessel = $vault->openDataKey($gespeichert['schluessel']);
        } catch (CryptoException) {
            $this->verwerfen();

            return null;
        }
        // An old document entered or left a cost-center scope: what the
        // index holds no longer matches what the viewer may see - start over.
        if (!hash_equals($gespeichert['mitglieder'], $this->mitglieder($gespeichert['cursor'], $bereich, $gespeichert['nachholen']))) {
            $this->verwerfen();

            return null;
        }

        return [...$gespeichert, 'schluessel' => $schluessel];
    }

    /**
     * @return array{id: string, schluessel: DataKey, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>}
     */
    private function neu(int $benutzerId, Zugriffsbereich $bereich): array
    {
        $this->verwerfen();

        return [
            'id' => SuchindexSpeicher::neueId(),
            'schluessel' => DataKey::generate(),
            'benutzer' => $benutzerId,
            'bereich' => self::fingerabdruck($bereich),
            'cursor' => 0,
            'teile' => 0,
            'fertig' => false,
            'mitglieder' => '',
            'nachholen' => [],
        ];
    }

    /**
     * @param array{id: string, schluessel: DataKey, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>} $zustand
     */
    private function merke(array $zustand, Vault $vault): void
    {
        $this->sitzung->schreibe([...$zustand, 'schluessel' => $vault->sealDataKey($zustand['schluessel'])]);
    }

    /**
     * The normalised text of one document from every source - empty when
     * it has none (any more) or was deleted: an empty entry still overrides
     * an older one.
     */
    private function text(int $documentId, Vault $vault): string
    {
        $document = $this->documents->find($documentId);
        if ($document === null) {
            return '';
        }
        $texte = [];
        foreach ($this->quellen as $quelle) {
            // One artifact that does not open (another vault generation, a
            // damaged row) must not stop the whole chain: that source simply
            // contributes nothing for this document.
            try {
                array_push($texte, ...$quelle->texte($document, $vault));
            } catch (CryptoException | \SodiumException | \JsonException) {
                continue;
            }
        }

        return Suchtext::fuerIndex($texte);
    }

    /**
     * @param list<array{0: int, 1: string}> $eintraege
     */
    private function verschluesseln(DataKey $schluessel, int $teil, array $eintraege): string
    {
        $json = json_encode(['f' => self::FORMAT, 'e' => $eintraege], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $gepackt = gzdeflate($json, 6);
        if ($gepackt === false) {
            throw new \RuntimeException('Suchindex-Teil lässt sich nicht packen.');
        }

        return FieldCipher::encrypt($schluessel, sodium_pad($gepackt, self::BLOCK), new FieldContext(self::TABELLE, $teil, self::SPALTE));
    }

    /**
     * One part, decrypted - null when it is missing or not readable.
     *
     * @param array{id: string, schluessel: DataKey, benutzer: int, bereich: string, cursor: int, teile: int, fertig: bool, mitglieder: string, nachholen: list<int>} $zustand
     *
     * @return list<array{0: int, 1: string}>|null
     */
    private function teil(array $zustand, int $teil): ?array
    {
        $chiffrat = $this->speicher->lies($zustand['id'], $teil);
        if ($chiffrat === null) {
            return null;
        }
        try {
            $gepackt = sodium_unpad(
                FieldCipher::decrypt($zustand['schluessel'], $chiffrat, new FieldContext(self::TABELLE, $teil, self::SPALTE)),
                self::BLOCK,
            );
        } catch (CryptoException | \SodiumException) {
            return null;
        }
        $json = @gzinflate($gepackt, self::MAX_TEIL_BYTES);
        $daten = $json === false ? null : json_decode($json, true);
        if (!is_array($daten) || ($daten['f'] ?? null) !== self::FORMAT || !is_array($daten['e'] ?? null)) {
            return null;
        }

        $eintraege = [];
        foreach ($daten['e'] as $eintrag) {
            if (is_array($eintrag) && is_int($eintrag[0] ?? null) && is_string($eintrag[1] ?? null)) {
                $eintraege[] = [$eintrag[0], $eintrag[1]];
            }
        }

        return $eintraege;
    }

    /**
     * @return list<ArtifactKind>
     */
    private function arten(): array
    {
        $arten = [];
        foreach ($this->quellen as $quelle) {
            foreach ($quelle->arten() as $art) {
                $arten[$art->value] = $art;
            }
        }

        return array_values($arten);
    }

    /** Which scope the index was built for - any change rebuilds it. */
    private static function fingerabdruck(Zugriffsbereich $bereich): string
    {
        $kostenstellen = $bereich->kostenstellen;
        if ($kostenstellen !== null) {
            sort($kostenstellen);
        }

        return hash('sha256', json_encode([
            $kostenstellen,
            $bereich->von?->format('Y-m-d'),
            $bereich->bis?->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR));
    }
}
