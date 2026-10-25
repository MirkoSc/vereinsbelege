<?php

declare(strict_types=1);

namespace App\Worker\Schritt;

use App\Domain\ArtifactKind;
use App\Service\Crypto\CryptoException;
use App\Service\Ki\KiAbfrage;
use App\Service\Ki\KiErgebnis;
use App\Service\Ki\KiVersuch;
use App\Service\Processing\ERechnung\ERechnungBefund;
use App\Service\Processing\ERechnung\ERechnungErgebnis;
use App\Service\Processing\ERechnung\ERechnungLeser;
use App\Service\Processing\ERechnung\ERechnungPdf;
use App\Service\Processing\KiAuslese\AusleseEingabe;
use App\Service\Processing\KiAuslese\Auslesung;
use App\Service\Processing\Pdf\PdfTextlayer;
use App\Service\Processing\PngZuJpeg;
use App\Service\Processing\ProcessingException;
use App\Service\Processing\Textlayer;
use App\Service\Processing\TextlayerBefund;
use App\Service\Upload\MagicBytes;
use App\Service\Worker\WorkerKrypto;
use App\Worker\Identitaet;
use App\Worker\JobFehler;
use App\Worker\JobLauf;
use App\Worker\KiKonfiguration;
use App\Worker\Werkzeuge;

/**
 * `ai_extract` on the worker (docs/spec/07-worker.md section 2: "ohne
 * Zeitlimit, rund um die Uhr"), with the web app's own extraction logic:
 * App\Service\Processing\KiAuslese\Auslesung builds the request and checks
 * the answer, App\Service\Ki\KiAbfrage asks and validates (CLAUDE.md
 * section 6a). Only the gathering of the input is the worker's:
 *
 *   - e-invoice: the first original that reads as one (XML, or a PDF with
 *     an embedded XML) - App\Service\Processing\ERechnung;
 *   - text: the PDFs' own text layers when every original is a PDF with a
 *     usable one (App\Service\Processing\Textlayer); otherwise, with OCR
 *     tools at hand, the worker's own Tesseract text when it is usable for
 *     every original - the OCR results already stored are sealed to the
 *     vault, the worker cannot read them back;
 *   - page images otherwise: the processed scan, the photo (PNG converted),
 *     PDF pages rendered with Poppler - as many as the profile's
 *     `max_images`, within 20 MiB;
 *   - free text from the worker context (sealed to W_pub); never name,
 *     e-mail or IBAN - the context does not carry them;
 *   - no supplier preselection: it needs the vault's blind index.
 *
 * The chain is the session's (App\Service\Document\KiAuslesung): first
 * call with the default profile, then its repair call, or - when it was out
 * of reach - the fallback profile and its repair; at most three calls.
 * Each attempt becomes one `extraction` result (`seq` = its place) in the
 * session's shape `{versuch, meldung, extraktion, hinweise}`. The web host
 * checks the attempt's data against the schema itself and rebuilds message,
 * extraction and hints (WorkerUebernahme) - nothing of it is taken on trust.
 */
final readonly class KiAuslesen implements Schritt
{
    /** Page images per request at most, in bytes (as App\Service\Document\KiAuslesung). */
    public const int BILDER_MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private KiKonfiguration $konfiguration,
        private Auslesung $auslesung,
        private KiAbfrage $abfrage,
        private Werkzeuge $werkzeuge,
        private Identitaet $identitaet,
    ) {
    }

    public function typ(): string
    {
        return 'ai_extract';
    }

    public function ausfuehren(JobLauf $lauf, string $verzeichnis): void
    {
        // The default profile with its key - without it nothing to do; the
        // session route reads the receipt (or skips it, AI switched off).
        [$profil, $apiKey] = $this->konfiguration->standard() ?? throw new JobFehler('KiNichtEingerichtet', true);

        $eingabe = $this->eingabe($lauf, $verzeichnis, Auslesung::bilderGrenze($profil));
        if ($this->auslesung->nichtLesbar($eingabe, $profil) !== null) {
            // The session route stores the reason at the receipt.
            throw new JobFehler('KiNichtLesbar', true);
        }
        $anfrage = $this->auslesung->anfrage($eingabe, $profil);
        $version = Auslesung::PROMPT_VERSION;
        $kette = [];

        $lauf->puls();
        $erster = $this->abfrage->fragen($profil, $apiKey, $anfrage, $version);
        $this->speichern($lauf, $kette, $erster, $eingabe);
        if ($erster->reparaturMoeglich()) {
            $lauf->puls();
            $this->speichern($lauf, $kette, $this->abfrage->reparieren($profil, $apiKey, $anfrage, $erster, $version), $eingabe);

            return;
        }
        $fallback = $erster->fallbackMoeglich() ? $this->konfiguration->fallback($profil->id) : null;
        if ($fallback === null) {
            return;
        }
        [$fbProfil, $fbKey] = $fallback;
        $lauf->puls();
        $zweiter = $this->abfrage->fallbackFragen($fbProfil, $fbKey, $anfrage, $erster, $version);
        $this->speichern($lauf, $kette, $zweiter, $eingabe);
        if ($zweiter->reparaturMoeglich()) {
            $lauf->puls();
            $this->speichern($lauf, $kette, $this->abfrage->reparieren($fbProfil, $fbKey, $anfrage, $zweiter, $version), $eingabe);
        }
    }

    /**
     * @param list<KiVersuch> $kette the attempts so far; $versuch is added
     */
    private function speichern(JobLauf $lauf, array &$kette, KiVersuch $versuch, AusleseEingabe $eingabe): void
    {
        $kette[] = $versuch;
        $vorschlag = $versuch->gueltig() && $versuch->daten !== null ? $this->auslesung->auswerten($versuch->daten, $eingabe) : null;
        $json = json_encode([
            'versuch' => $versuch->toArray(),
            'meldung' => (new KiErgebnis($kette))->meldung(),
            'extraktion' => $vorschlag?->extraktion,
            'hinweise' => $vorschlag->hinweise ?? [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $lauf->datenHochladen(ArtifactKind::Extraction, count($kette) - 1, $json);
        sodium_memzero($json);
    }

    private function eingabe(JobLauf $lauf, string $verzeichnis, int $bilderGrenze): AusleseEingabe
    {
        $kontext = $lauf->kontext();
        $originale = Quellen::originale($lauf);

        $eRechnung = null;
        foreach ($originale as [, $inhalt, $typ]) {
            $ergebnis = match ($typ) {
                MagicBytes::XML => self::leise(static fn(): ERechnungErgebnis => ERechnungLeser::lesen($inhalt)),
                MagicBytes::PDF => self::leise(static fn(): ERechnungErgebnis => ERechnungPdf::lesen($inhalt)),
                default => null,
            };
            if ($ergebnis?->befund === ERechnungBefund::Gelesen && $ergebnis->rechnung !== null) {
                $eRechnung = $ergebnis->rechnung->alsExtraktion();
                break;
            }
        }

        // The pages that count: PDFs and images, no XML.
        $seitenOriginale = array_values(array_filter($originale, static fn(array $o): bool => in_array($o[2], [MagicBytes::PDF, MagicBytes::JPEG, MagicBytes::PNG, MagicBytes::HEIC], true)));
        $text = self::textlayer($seitenOriginale);
        $seiten = $text === null ? $this->seiten($lauf, $verzeichnis, $seitenOriginale, $bilderGrenze) : [];
        if ($text === null && $this->werkzeuge->kannOcr()) {
            $text = $this->eigeneOcr($lauf, $verzeichnis, $seiten, count($seitenOriginale));
        }

        $bilder = [];
        if ($text === null) {
            $bytes = 0;
            foreach ($seiten as [, $bild]) {
                if (count($bilder) >= $bilderGrenze) {
                    break;
                }
                try {
                    $jpeg = $bild();
                } catch (ProcessingException | JobFehler) {
                    // A PNG GD cannot read, a PDF page Poppler cannot render
                    // within the limits: that page stays out, the rest goes.
                    continue;
                }
                if ($bytes + strlen($jpeg) > self::BILDER_MAX_BYTES) {
                    break;
                }
                $bytes += strlen($jpeg);
                $bilder[] = $jpeg;
            }
        }

        $freitext = '';
        if ($kontext->kontextVersiegelt !== null) {
            try {
                $freitext = WorkerKrypto::kontextOeffnen($kontext->kontextVersiegelt, $this->identitaet->schluesselpaar)['freitext'];
            } catch (CryptoException) {
                $freitext = '';
            }
        }

        return new AusleseEingabe(
            $text,
            $eRechnung,
            $bilder,
            $text === null ? count($seiten) : 0,
            $freitext,
            $kontext->kategorien,
            $kontext->kostenstellen,
            [],
        );
    }

    /**
     * The PDFs' own text, when every page original is a PDF with a usable
     * text layer - the case in which the session sends text instead of images.
     *
     * @param list<array{int, string, ?string}> $originale
     */
    private static function textlayer(array $originale): ?string
    {
        if ($originale === []) {
            return null;
        }
        $texte = [];
        foreach ($originale as [, $inhalt, $typ]) {
            if ($typ !== MagicBytes::PDF) {
                return null;
            }
            $layer = self::leise(static fn(): Textlayer => PdfTextlayer::lesen($inhalt));
            if ($layer === null || !$layer->brauchbar()) {
                return null;
            }
            $texte[] = $layer->text();
        }

        return implode(Textlayer::SEITENWECHSEL, $texte);
    }

    /**
     * Every page of the receipt as [original index, JPEG producer], in
     * order. The producer renders a PDF page only when it is asked for.
     *
     * @param list<array{int, string, ?string}> $originale
     *
     * @return list<array{int, \Closure(): string}>
     */
    private function seiten(JobLauf $lauf, string $verzeichnis, array $originale, int $bilderGrenze): array
    {
        $kontext = $lauf->kontext();
        $seiten = [];
        foreach ($originale as $index => [$blobId, $inhalt, $typ]) {
            $nummer = (int) array_search($blobId, $kontext->originale, true);
            $verarbeitet = $kontext->verarbeitet($nummer);
            if ($typ !== MagicBytes::PDF && $verarbeitet !== null) {
                $scan = $lauf->blob($verarbeitet);
                if (Quellen::typ($scan) === MagicBytes::JPEG) {
                    $seiten[] = [$index, static fn(): string => $scan];
                    continue;
                }
            }
            if ($typ === MagicBytes::JPEG) {
                $seiten[] = [$index, static fn(): string => $inhalt];
            } elseif ($typ === MagicBytes::PNG) {
                $seiten[] = [$index, static fn(): string => PngZuJpeg::konvertiere($inhalt)];
            } elseif ($typ === MagicBytes::PDF && $this->werkzeuge->kannRendern()) {
                $pdf = Quellen::datei($verzeichnis, 'ki-' . $index . '.pdf', $inhalt);
                try {
                    $anzahl = min($this->werkzeuge->seitenzahl($pdf, $lauf->pulsGeber()), Quellen::MAX_SEITEN);
                } catch (JobFehler) {
                    // A PDF Poppler cannot open: its pages stay out, as in the
                    // browser route; the text or the other pages may do.
                    continue;
                }
                $werkzeuge = $this->werkzeuge;
                $puls = $lauf->pulsGeber();
                for ($seite = 1; $seite <= $anzahl; ++$seite) {
                    $seiten[] = [$index, static fn(): string => Quellen::seitenbild($werkzeuge, $pdf, $seite, $verzeichnis, $puls)];
                }
            }
            // A HEIC without a processed JPEG is no input for the model.
        }
        // The pages that may go to the model are produced once, also when
        // the OCR pass asked for them first.
        foreach ($seiten as $i => [$index, $bild]) {
            if ($i < $bilderGrenze) {
                $seiten[$i] = [$index, self::einmal($bild)];
            }
        }

        return $seiten;
    }

    /**
     * @param \Closure(): string $bild
     *
     * @return \Closure(): string
     */
    private static function einmal(\Closure $bild): \Closure
    {
        $wert = null;

        return static function () use ($bild, &$wert): string {
            return $wert ??= $bild();
        };
    }

    /**
     * Tesseract over every page; the text only when it is usable for every
     * original (Textlayer, the same rule as for a PDF's text layer).
     *
     * @param list<array{int, \Closure(): string}> $seiten
     * @param int $originale how many page originals the receipt has - one
     *        without any page (a PDF Poppler cannot open, a HEIC without
     *        scan) means the text would not cover the receipt
     */
    private function eigeneOcr(JobLauf $lauf, string $verzeichnis, array $seiten, int $originale): ?string
    {
        if ($seiten === []) {
            return null;
        }
        $jeOriginal = [];
        try {
            foreach ($seiten as [$index, $bild]) {
                try {
                    $jpeg = $bild();
                } catch (ProcessingException) {
                    return null;
                }
                $datei = Quellen::datei($verzeichnis, 'ocr-seite.jpg', $jpeg);
                $jeOriginal[$index][] = $this->werkzeuge->bildText($datei, $lauf->pulsGeber());
                unlink($datei);
            }
        } catch (JobFehler) {
            return null;
        }
        if (count($jeOriginal) !== $originale) {
            return null;
        }
        $texte = [];
        foreach ($jeOriginal as $seitenText) {
            $layer = new Textlayer(TextlayerBefund::Gelesen, array_map(static fn(string $s): string => (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8'), $seitenText));
            if (!$layer->brauchbar()) {
                return null;
            }
            $texte[] = $layer->text();
        }

        return implode(Textlayer::SEITENWECHSEL, $texte);
    }

    /**
     * A reader that refuses broken input: null instead of the exception.
     *
     * @template T
     *
     * @param \Closure(): T $lesen
     *
     * @return ?T
     */
    private static function leise(\Closure $lesen): mixed
    {
        try {
            return $lesen();
        } catch (ProcessingException | \ValueError | \JsonException) {
            return null;
        }
    }
}
