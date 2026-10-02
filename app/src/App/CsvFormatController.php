<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\AuditAction;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CsvProfileRepository;
use App\Service\Audit\AuditLog;
use App\Service\Bank\Csv\CsvBuchung;
use App\Service\Bank\Csv\CsvDatumsformat;
use App\Service\Bank\Csv\CsvDezimaltrenner;
use App\Service\Bank\Csv\CsvErgebnis;
use App\Service\Bank\Csv\CsvErkennung;
use App\Service\Bank\Csv\CsvException;
use App\Service\Bank\Csv\CsvFeld;
use App\Service\Bank\Csv\CsvFormatErkennung;
use App\Service\Bank\Csv\CsvParser;
use App\Service\Bank\Csv\CsvProfil;
use App\Service\Bank\Csv\CsvProfilErkennung;
use App\Service\Bank\Csv\CsvProfilUngueltig;
use App\Service\Bank\Csv\CsvTrennzeichen;
use App\Service\Bank\Csv\CsvZeichensatz;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * CSV formats for the statement import /app/konten/csv-formate (M9-3,
 * issue #61, docs/spec/04-bank-und-abgleich.md section 3): the shipped and
 * the club's own profiles, and the mapping assistant that learns a new one
 * - upload a sample export, map its columns with a live preview of the
 * first rows, save the profile.
 *
 * Every route needs `bank.import` (Admin, Finanzen - app/src/routes.php):
 * whoever imports statements teaches the formats.
 *
 * The sample file is never stored. The assistant's form sends it again with
 * every change (htmx, multipart), PHP reads it in memory with the very
 * parser the import uses, and the answer is the preview fragment - no blob,
 * no temp file of ours, nothing in the session. What is saved is the
 * profile: column names and formats, no club data. Cell contents appear
 * only in the preview sent back to the person who uploaded them, never in
 * a flash message, the audit log or a URL.
 *
 * Shipped profiles are read-only; the repository enforces it in SQL too.
 */
final readonly class CsvFormatController
{
    /** A statement export is a few KB per month; 2 MB is years of bookings. */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    public const int VORSCHAU_ZEILEN = 10;

    private const string LISTE = '/app/konten/csv-formate';

    /**
     * @param (\Closure(string): bool)|null $istUpload replaces
     *        is_uploaded_file() in tests, like App\Installer\InstallController
     */
    public function __construct(
        private View $view,
        private Session $session,
        private CsvProfileRepository $profile,
        private AuditLog $audit,
        private ?\Closure $istUpload = null,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('app/csv-formate', [
            'title' => 'CSV-Formate',
            'flash' => $this->session->pullFlash(),
            'profile' => $this->profile->all(),
        ], Area::App));
    }

    /**
     * @param array<string, string> $params
     */
    public function ansicht(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $profil = $this->profile->find((int) $params['id']);
        if ($profil === null) {
            return $this->nichtGefunden();
        }

        return Response::html($this->view->render('app/csv-format', [
            'title' => $profil->name,
            'flash' => $this->session->pullFlash(),
            'profil' => $profil,
        ], Area::App));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->assistent(null, '', $this->zustand($request, null, false), null);
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $profil = $this->eigenes((int) $params['id']);
        if ($profil instanceof ResponseInterface) {
            return $profil;
        }

        return $this->assistent($profil, $profil->name, $this->zustand($request, $profil, false), null);
    }

    /**
     * The htmx answer to every change in the assistant: detection (for a
     * new file), the mapping and the preview.
     */
    public function vorschau(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::html($this->view->fragment('app/csv-format-zuordnung', [
                ...$this->zustand(new Request($request->method, $request->path), null, false),
                'fehler' => 'Die Sitzung ist abgelaufen – bitte die Seite neu laden.',
            ]));
        }
        $id = (int) self::text($request, 'id');
        $bestehend = $id > 0 ? $this->profile->find($id) : null;

        return Response::html($this->view->fragment('app/csv-format-zuordnung', $this->zustand($request, $bestehend, true, erkennen: true)));
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::LISTE . '/neu');
        }

        return $this->sichern($request, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::LISTE . '/' . $id . '/bearbeiten');
        }
        $profil = $this->eigenes($id);
        if ($profil instanceof ResponseInterface) {
            return $profil;
        }

        return $this->sichern($request, $profil);
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::LISTE . '/' . $id);
        }
        $profil = $this->eigenes($id);
        if ($profil instanceof ResponseInterface) {
            return $profil;
        }

        $this->profile->delete($id);
        $this->audit->record(AuditAction::CsvFormatGeloescht, $this->session->userId(), $request->ip, $id, ['name' => $profil->name]);
        $this->session->flash('CSV-Format gelöscht.');

        return Response::redirect(self::LISTE);
    }

    private function sichern(Request $request, ?CsvProfil $bestehend): ResponseInterface
    {
        $name = self::text($request, 'name');
        $zustand = $this->zustand($request, $bestehend, true);

        $fehler = $zustand['fehler'];
        $feld = $fehler === null ? null : 'datei';
        if ($fehler === null && !$zustand['datei'] && $bestehend === null) {
            [$fehler, $feld] = ['Bitte eine Beispieldatei des Exports wählen – an ihr wird das Format geprüft.', 'datei'];
        }

        $profil = null;
        if ($fehler === null) {
            try {
                $profil = CsvProfil::neu(
                    $bestehend?->id,
                    $name,
                    $zustand['trennzeichen'],
                    $zustand['zeichensatz'],
                    $zustand['datumsformat'],
                    $zustand['dezimaltrenner'],
                    $zustand['zuordnung'],
                    $zustand['ergebnis'] !== null ? CsvProfil::signatur($zustand['ergebnis']->kopf) : $bestehend?->kopfSignatur,
                );
                if ($zustand['datei'] && $zustand['ergebnis'] === null) {
                    // The mapping is complete but the file does not fit it
                    // (no header with these columns).
                    [$fehler, $feld, $profil] = [$zustand['vorschauFehler'] ?? 'Die Datei passt nicht zu dieser Zuordnung.', 'datei', null];
                } elseif ($this->profile->nameExists($profil->name, $bestehend?->id)) {
                    [$fehler, $feld, $profil] = ['Ein CSV-Format mit diesem Namen gibt es schon.', 'name', null];
                }
            } catch (CsvProfilUngueltig $e) {
                [$fehler, $feld] = [$e->getMessage(), $e->feld];
            }
        }

        if ($profil === null) {
            return $this->assistent($bestehend, $name, $zustand, ['text' => $fehler ?? '', 'feld' => $feld ?? ''], 422);
        }

        $jetzt = new \DateTimeImmutable();
        if ($bestehend === null) {
            $id = $this->profile->create($profil, $jetzt);
            $this->audit->record(AuditAction::CsvFormatAngelegt, $this->session->userId(), $request->ip, $id, ['name' => $profil->name]);
            $this->session->flash('CSV-Format gespeichert.');
        } else {
            $id = $bestehend->id ?? 0;
            $this->profile->update($id, $profil, $jetzt);
            $this->audit->record(AuditAction::CsvFormatGeaendert, $this->session->userId(), $request->ip, $id, ['name' => $profil->name]);
            $this->session->flash('CSV-Format gespeichert.');
        }

        return Response::redirect(self::LISTE . '/' . $id);
    }

    /**
     * Everything the mapping fragment shows, from the sent form (or, on the
     * first page load, from the profile being edited).
     *
     * In the preview, a new file in a new profile is detected and its
     * suggestion replaces the form's values; the same file again (the
     * hidden fingerprint matches) keeps what the person chose. Saving and
     * editing never detect - saving stores what the form shows, editing
     * checks the existing mapping against a fresh export.
     *
     * @return array{
     *     datei: bool,
     *     kennung: ?string,
     *     fehler: ?string,
     *     erkannt: ?CsvErkennung,
     *     trennzeichen: CsvTrennzeichen,
     *     zeichensatz: CsvZeichensatz,
     *     datumsformat: CsvDatumsformat,
     *     dezimaltrenner: CsvDezimaltrenner,
     *     zuordnung: array<string, list<string>>,
     *     spalten: list<string>,
     *     kopfzeile: ?int,
     *     profilFehler: ?CsvProfilUngueltig,
     *     ergebnis: ?CsvErgebnis,
     *     vorschauFehler: ?string,
     *     zeilen: list<array{zeile: int, buchung: ?CsvBuchung, fehler: ?string}>,
     *     bekannt: ?CsvProfil,
     *     id: ?int,
     * }
     */
    private function zustand(Request $request, ?CsvProfil $bestehend, bool $gesendet, bool $erkennen = false): array
    {
        $zustand = [
            'datei' => false,
            'kennung' => null,
            'fehler' => null,
            'erkannt' => null,
            'trennzeichen' => CsvTrennzeichen::tryFrom(self::text($request, 'trennzeichen')) ?? $bestehend?->trennzeichen ?? CsvTrennzeichen::Semikolon,
            'zeichensatz' => CsvZeichensatz::tryFrom(self::text($request, 'zeichensatz')) ?? $bestehend?->zeichensatz ?? CsvZeichensatz::Automatisch,
            'datumsformat' => CsvDatumsformat::tryFrom(self::text($request, 'datumsformat')) ?? $bestehend?->datumsformat ?? CsvDatumsformat::Punkt,
            'dezimaltrenner' => CsvDezimaltrenner::tryFrom(self::text($request, 'dezimaltrenner')) ?? $bestehend?->dezimaltrenner ?? CsvDezimaltrenner::Komma,
            'zuordnung' => $gesendet ? self::zuordnung($request) : ($bestehend?->zuordnung ?? []),
            'spalten' => [],
            'kopfzeile' => null,
            'profilFehler' => null,
            'ergebnis' => null,
            'vorschauFehler' => null,
            'zeilen' => [],
            'bekannt' => null,
            'id' => $bestehend?->id,
        ];

        [$inhalt, $uploadFehler] = $this->upload($request);
        $zustand['fehler'] = $uploadFehler;
        if ($inhalt === null) {
            $zustand['spalten'] = self::spaltenAus($zustand['zuordnung'], []);

            return $zustand;
        }
        $zustand['datei'] = true;
        $zustand['kennung'] = substr(hash('sha256', $inhalt), 0, 16);

        try {
            if ($erkennen && $bestehend === null && self::text($request, 'datei_kennung') !== $zustand['kennung']) {
                $erkannt = new CsvFormatErkennung()->erkenne($inhalt);
                $zustand['erkannt'] = $erkannt;
                $zustand['trennzeichen'] = $erkannt->trennzeichen;
                $zustand['zeichensatz'] = CsvZeichensatz::Automatisch;
                $zustand['datumsformat'] = $erkannt->datumsformat ?? $zustand['datumsformat'];
                $zustand['dezimaltrenner'] = $erkannt->dezimaltrenner ?? $zustand['dezimaltrenner'];
                $zustand['zuordnung'] = $erkannt->vorschlag;
            }
            [$kopf, $zustand['kopfzeile']] = new CsvFormatErkennung()->kopf($inhalt, $zustand['zeichensatz'], $zustand['trennzeichen']);
        } catch (CsvException $e) {
            $zustand['fehler'] = $e->getMessage();
            $zustand['spalten'] = self::spaltenAus($zustand['zuordnung'], []);

            return $zustand;
        }
        $zustand['spalten'] = self::spaltenAus($zustand['zuordnung'], $kopf);

        try {
            // The name is checked on saving; the preview works without one.
            $name = mb_substr(trim(self::text($request, 'name')), 0, CsvProfil::NAME_MAX);
            $profil = CsvProfil::neu($bestehend?->id, $name !== '' ? $name : 'Neues Format', $zustand['trennzeichen'], $zustand['zeichensatz'],
                $zustand['datumsformat'], $zustand['dezimaltrenner'], $zustand['zuordnung'], null);
        } catch (CsvProfilUngueltig $e) {
            $zustand['profilFehler'] = $e;

            return $zustand;
        }

        try {
            $ergebnis = new CsvParser()->parse($inhalt, $profil);
        } catch (CsvException $e) {
            $zustand['vorschauFehler'] = $e->getMessage();

            return $zustand;
        }
        $zustand['ergebnis'] = $ergebnis;
        $zustand['zeilen'] = self::vorschauZeilen($ergebnis);
        $zustand['bekannt'] = new CsvProfilErkennung()->finde(
            $inhalt,
            array_values(array_filter($this->profile->all(), static fn (CsvProfil $p): bool => $p->id !== $bestehend?->id)),
        );

        return $zustand;
    }

    /**
     * @param array<string, mixed>                   $zustand
     * @param array{text: string, feld: string}|null $fehler
     */
    private function assistent(?CsvProfil $bestehend, string $name, array $zustand, ?array $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('app/csv-format-assistent', [
            ...$zustand,
            'title' => $bestehend === null ? 'Neues CSV-Format' : 'CSV-Format bearbeiten',
            'flash' => $this->session->pullFlash(),
            'bestehend' => $bestehend,
            'name' => $name,
            'speicherFehler' => $fehler,
        ], Area::App), $status);
    }

    /**
     * @return array{?string, ?string} file content or null, error message
     */
    private function upload(Request $request): array
    {
        $datei = $request->files['datei'] ?? null;
        if (!is_array($datei)) {
            return [null, null];
        }
        $fehler = $datei['error'] ?? \UPLOAD_ERR_NO_FILE;
        $zuGross = 'Die Datei ist größer als 2 MB – bitte einen kürzeren Zeitraum exportieren.';

        return match (true) {
            $fehler === \UPLOAD_ERR_NO_FILE => [null, null],
            $fehler === \UPLOAD_ERR_INI_SIZE, $fehler === \UPLOAD_ERR_FORM_SIZE => [null, $zuGross],
            $fehler !== \UPLOAD_ERR_OK => [null, 'Die Datei konnte nicht hochgeladen werden – bitte erneut wählen.'],
            default => $this->uploadLesen((string) ($datei['tmp_name'] ?? ''), $zuGross),
        };
    }

    /**
     * @return array{?string, ?string}
     */
    private function uploadLesen(string $pfad, string $zuGross): array
    {
        $istUpload = $this->istUpload ?? is_uploaded_file(...);
        if ($pfad === '' || !$istUpload($pfad)) {
            return [null, 'Die Datei konnte nicht hochgeladen werden – bitte erneut wählen.'];
        }
        $groesse = filesize($pfad);
        if ($groesse === false || $groesse > self::MAX_BYTES) {
            return [null, $zuGross];
        }
        $inhalt = file_get_contents($pfad);

        return $inhalt === false || $inhalt === '' ? [null, 'Die Datei ist leer.'] : [$inhalt, null];
    }

    /**
     * Data rows and row errors in file order, the first VORSCHAU_ZEILEN.
     *
     * @return list<array{zeile: int, buchung: ?CsvBuchung, fehler: ?string}>
     */
    private static function vorschauZeilen(CsvErgebnis $ergebnis): array
    {
        $zeilen = [];
        foreach ($ergebnis->buchungen as $buchung) {
            $zeilen[] = ['zeile' => $buchung->zeile, 'buchung' => $buchung, 'fehler' => null];
        }
        foreach ($ergebnis->fehler as $fehler) {
            $zeilen[] = ['zeile' => $fehler->zeile, 'buchung' => null, 'fehler' => $fehler->meldung];
        }
        usort($zeilen, static fn (array $a, array $b): int => $a['zeile'] <=> $b['zeile']);

        return array_slice($zeilen, 0, self::VORSCHAU_ZEILEN);
    }

    /**
     * The columns a mapping select offers: the file's header, plus names
     * the mapping uses that the file lacks (so they are not dropped
     * silently when a profile is edited against a different export).
     *
     * @param array<string, list<string>> $zuordnung
     * @param list<string>                $kopf
     *
     * @return list<string>
     */
    private static function spaltenAus(array $zuordnung, array $kopf): array
    {
        $spalten = array_values(array_filter($kopf, static fn (string $s): bool => $s !== ''));
        foreach ($zuordnung as $namen) {
            foreach ($namen as $name) {
                if (!in_array($name, $spalten, true)) {
                    $spalten[] = $name;
                }
            }
        }

        return $spalten;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function zuordnung(Request $request): array
    {
        $roh = $request->post['zuordnung'] ?? [];
        $zuordnung = [];
        foreach (CsvFeld::cases() as $feld) {
            $werte = is_array($roh) ? ($roh[$feld->value] ?? []) : [];
            $werte = is_array($werte) ? $werte : [$werte];
            $namen = array_values(array_filter($werte, static fn (mixed $w): bool => is_string($w) && trim($w) !== ''));
            if ($namen !== []) {
                $zuordnung[$feld->value] = $namen;
            }
        }

        return $zuordnung;
    }

    /** A club profile, or the response that refuses a shipped or unknown one. */
    private function eigenes(int $id): CsvProfil|ResponseInterface
    {
        $profil = $this->profile->find($id);
        if ($profil === null) {
            return $this->nichtGefunden();
        }
        if ($profil->mitgeliefert) {
            $this->session->flash('Mitgelieferte CSV-Formate lassen sich nicht ändern oder löschen.', FlashArt::Fehler);

            return Response::redirect(self::LISTE . '/' . $id);
        }

        return $profil;
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'CSV-Format nicht gefunden',
            'message' => 'Dieses CSV-Format gibt es nicht (mehr).',
            'startseite' => self::LISTE,
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
