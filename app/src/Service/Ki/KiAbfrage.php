<?php

declare(strict_types=1);

namespace App\Service\Ki;

/**
 * Asks a model for JSON and accepts only JSON that fits the schema
 * (CLAUDE.md section 6, docs/spec/03-erfassung-und-ki.md section 6
 * "Client"):
 *
 * 1. fragen(): one call; the first JSON object of the answer - bare, in a
 *    code fence or in prose (profiles without `json_schema`) - is checked
 *    against the schema in PHP, whatever the provider promised.
 * 2. reparieren(): if the answer is there but does not fit (no JSON, schema
 *    violated), exactly ONE more call: the same conversation, the model's
 *    answer, and app/prompts/reparatur-v1.md with the list of errors. Pages
 *    and `response_format` go along again, so the model can look at the
 *    receipt instead of making a value up.
 * 3. Still not valid, or a call without usable answer (timeout, HTTP
 *    error): the result is "KI fehlgeschlagen" - nothing is guessed.
 * 4. Exception: if the FIRST call finds the provider out of reach
 *    (KiAufrufStatus::ausfall(): connection, timeout, HTTP 429/5xx) and an
 *    optional fallback profile exists, fallbackFragen() asks that profile
 *    once - from scratch, and with its own repair call if its answer does
 *    not fit. Never a second fallback, never a fallback after a repair
 *    call, and each call is a step of its own.
 *
 * Two methods instead of one loop because no request may run long
 * (CLAUDE.md section 1): the job (M7-5) runs each call as a step of its
 * own and keeps the first attempt (encrypted) in between. ausfuehren() does
 * both in a row, for the worker (M7b) and tests.
 *
 * Framework-free (no Http, Session, Repository) and silent: nothing is
 * logged, the KiAufruf entries go back to the caller.
 */
final readonly class KiAbfrage
{
    /** Longer answers are not searched for JSON (bytes). */
    public const int INHALT_MAX = 1_000_000;

    private const int TIEFE_MAX = 32;

    /** Opening braces tried before giving up on prose full of `{`. */
    private const int VERSUCHE_MAX = 50;

    public function __construct(
        private LlmClient $client,
        private KiPrompts $prompts,
    ) {
    }

    public function fragen(KiAnbieter $profil, #[\SensitiveParameter] string $apiKey, LlmAnfrage $anfrage, string $promptVersion): KiVersuch
    {
        return $this->versuch($profil, $apiKey, $anfrage, $promptVersion, false, false);
    }

    /**
     * The one step with the fallback profile, after fragen() found the
     * default profile out of reach. Same request, same prompt version; the
     * KiAufruf says that the fallback answered ($fallback = true), so the
     * log names the profile that was actually used.
     *
     * @param KiVersuch $vorher the first attempt (with the default profile)
     *
     * @throws \LogicException when $vorher is no outage of a first call,
     *         $fallback is the profile that just failed, or it is not usable
     */
    public function fallbackFragen(
        KiAnbieter $fallback,
        #[\SensitiveParameter] string $apiKey,
        LlmAnfrage $anfrage,
        KiVersuch $vorher,
        string $promptVersion,
    ): KiVersuch {
        if (!$vorher->fallbackMoeglich()) {
            throw new \LogicException('Only the first call of a chain that found the provider out of reach can fall back.');
        }
        if ($fallback->id === $vorher->aufruf->anbieterId || !$fallback->nutzbar()) {
            throw new \LogicException('The fallback must be another profile that is active and has a key.');
        }

        return $this->versuch($fallback, $apiKey, $anfrage, $promptVersion, false, true);
    }

    /**
     * @param KiVersuch $vorher the first attempt for the same request; made
     *        with the fallback profile, so is the repair ($profil must then
     *        be that profile)
     *
     * @throws \LogicException when $vorher cannot be repaired - it is valid,
     *         a repair already, or a call without answer
     */
    public function reparieren(
        KiAnbieter $profil,
        #[\SensitiveParameter] string $apiKey,
        LlmAnfrage $anfrage,
        KiVersuch $vorher,
        string $promptVersion,
    ): KiVersuch {
        if (!$vorher->reparaturMoeglich()) {
            throw new \LogicException('Only a first attempt with an answer that does not fit can be repaired.');
        }

        $nachfrage = $this->prompts->text('reparatur-v1', [
            'fehler' => implode("\n", array_map(static fn(string $fehler): string => '- ' . $fehler, $vorher->fehler)),
        ]);

        return $this->versuch($profil, $apiKey, $anfrage->mitNachtrag($vorher->inhalt, $nachfrage), $promptVersion, true, $vorher->aufruf->fallback);
    }

    /**
     * All steps in a row: at most three calls - the default profile's first
     * call, then either its repair call or (if it was out of reach and
     * $fallback is given) the fallback's first call and, if needed, the
     * fallback's repair call. Never a fourth.
     *
     * @param ?KiAnbieter $fallback the optional fallback profile
     *        (AiProviderRepository::fallback()), used only when it is
     *        another, usable profile than $profil
     */
    public function ausfuehren(
        KiAnbieter $profil,
        #[\SensitiveParameter] string $apiKey,
        LlmAnfrage $anfrage,
        string $promptVersion,
        ?KiAnbieter $fallback = null,
        #[\SensitiveParameter] ?string $fallbackKey = null,
    ): KiErgebnis {
        $erster = $this->fragen($profil, $apiKey, $anfrage, $promptVersion);
        if ($erster->reparaturMoeglich()) {
            return new KiErgebnis([$erster, $this->reparieren($profil, $apiKey, $anfrage, $erster, $promptVersion)]);
        }
        if (
            !$erster->fallbackMoeglich()
            || $fallback === null
            || $fallbackKey === null
            || $fallback->id === $profil->id
            || !$fallback->nutzbar()
        ) {
            return new KiErgebnis([$erster]);
        }

        $zweiter = $this->fallbackFragen($fallback, $fallbackKey, $anfrage, $erster, $promptVersion);
        if (!$zweiter->reparaturMoeglich()) {
            return new KiErgebnis([$erster, $zweiter]);
        }

        return new KiErgebnis([$erster, $zweiter, $this->reparieren($fallback, $fallbackKey, $anfrage, $zweiter, $promptVersion)]);
    }

    private function versuch(KiAnbieter $profil, string $apiKey, LlmAnfrage $anfrage, string $promptVersion, bool $reparatur, bool $fallback): KiVersuch
    {
        $schema = $anfrage->schema ?? throw new \LogicException('KiAbfrage needs a request with a schema.');

        try {
            $antwort = $this->client->chat($profil, $apiKey, $anfrage);
        } catch (LlmFehler $e) {
            return new KiVersuch(
                $e->status,
                null,
                [],
                '',
                $reparatur,
                new KiAufruf($profil->id, $profil->name, $profil->model, $promptVersion, $reparatur, $e->status, null, null, null, null, $e->httpStatus, null, $fallback),
            );
        }

        $objekt = self::erstesObjekt($antwort->inhalt);
        if ($objekt === null) {
            $status = KiAufrufStatus::KeinJson;
            $fehler = ['/: Die Antwort enthält kein JSON-Objekt.'];
            $daten = null;
        } else {
            $fehler = $schema->pruefe($objekt);
            $status = $fehler === [] ? KiAufrufStatus::Gueltig : KiAufrufStatus::SchemaVerletzt;
            $daten = $fehler === [] ? self::alsArray($objekt) : null;
        }
        if ($status !== KiAufrufStatus::Gueltig && $antwort->abbruchGrund === 'length') {
            $fehler[] = '/: Die Antwort wurde an der Token-Grenze abgeschnitten.';
        }

        return new KiVersuch(
            $status,
            $daten,
            $fehler,
            $antwort->inhalt,
            $reparatur,
            new KiAufruf(
                $profil->id,
                $profil->name,
                $antwort->modell,
                $promptVersion,
                $reparatur,
                $status,
                $antwort->promptTokens,
                $antwort->antwortTokens,
                $antwort->tokensGesamt,
                $antwort->dauerMs,
                null,
                $antwort->roh,
                $fallback,
            ),
        );
    }

    /**
     * The first JSON object in the text: the whole text, else the first
     * top-level span from `{` to its matching `}` that decodes (braces
     * inside strings do not count). Covers code fences and prose around the
     * JSON; never picks an object out of the inside of another one.
     */
    private static function erstesObjekt(string $text): ?\stdClass
    {
        if (strlen($text) > self::INHALT_MAX) {
            return null;
        }
        $ganz = json_decode(trim($text), false, self::TIEFE_MAX);
        if ($ganz instanceof \stdClass) {
            return $ganz;
        }

        $versuche = 0;
        $start = strpos($text, '{');
        while ($start !== false && $versuche++ < self::VERSUCHE_MAX) {
            $ende = self::passendeKlammer($text, $start);
            if ($ende === null) {
                // Never closed (cut off at the token limit): every later
                // brace sits inside it, and a part is not the answer.
                return null;
            }
            $objekt = json_decode(substr($text, $start, $ende - $start + 1), false, self::TIEFE_MAX);
            if ($objekt instanceof \stdClass) {
                return $objekt;
            }
            // Not JSON ("{laut Vorgabe}", or a broken object): go on after
            // it - an object nested in a broken one is not the answer either.
            $start = strpos($text, '{', $ende + 1);
        }

        return null;
    }

    private static function passendeKlammer(string $text, int $start): ?int
    {
        $tiefe = 0;
        $imText = false;
        $maskiert = false;
        $laenge = strlen($text);
        for ($i = $start; $i < $laenge; $i++) {
            $zeichen = $text[$i];
            if ($imText) {
                if ($maskiert) {
                    $maskiert = false;
                } elseif ($zeichen === '\\') {
                    $maskiert = true;
                } elseif ($zeichen === '"') {
                    $imText = false;
                }
                continue;
            }
            if ($zeichen === '"') {
                $imText = true;
            } elseif ($zeichen === '{') {
                $tiefe++;
            } elseif ($zeichen === '}' && --$tiefe === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The validated object as an associative array; an integral float
     * (`"category_id": 7.0`, valid as integer) comes out as the integer.
     *
     * @return array<string, mixed>
     */
    private static function alsArray(\stdClass $objekt): array
    {
        $daten = json_decode(json_encode($objekt, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return is_array($daten) ? $daten : [];
    }
}
