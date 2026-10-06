<?php

declare(strict_types=1);

namespace App\App;

use App\Domain\BankAccount;
use App\Domain\BankAccountKind;
use App\Domain\BankImportStatus;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\CsvProfileRepository;
use App\Service\Account\SessionVault;
use App\Service\Bank\BankAccountService;
use App\Service\Bank\BankRuleViolation;
use App\Service\Bank\Import\ImportFormat;
use App\Service\Bank\Import\KontoauszugImport;
use App\Service\Bank\Import\KontoauszugUnlesbar;
use App\Service\Crypto\CryptoException;
use App\Service\Crypto\Vault;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * The statement import /app/konten/import (M9-4, issue #62, docs/spec/
 * 04-bank-und-abgleich.md section 4): upload a file, see the preview,
 * confirm, watch the step chain, read the result.
 *
 * Every route needs `bank.import` (Admin, Finanzen) - app/src/routes.php
 * declares it - and every POST a valid CSRF token. The bookings are vault
 * data: without an unlocked vault the pages say so and nothing is written.
 *
 * The file goes through a plain form (at most 2 MB - statements are small)
 * and is stored as an encrypted blob right away; its name and content never
 * appear in a URL, a flash message or the audit log. The step chain is
 * driven by public/js/kontoauszug.js, one `POST …/schritt` at a time.
 */
final readonly class KontoauszugController
{
    private const string START = '/app/konten/import';

    private const string TRESOR_GESPERRT = 'Ihr Tresor ist in dieser Sitzung nicht entsperrt. Bitte melden Sie sich neu an.';

    /** How many past imports the start page lists. */
    private const int VERLAUF = 20;

    /**
     * @param (\Closure(string): bool)|null $istUpload replaces
     *        is_uploaded_file() in tests, like App\App\CsvFormatController
     */
    public function __construct(
        private View $view,
        private Session $session,
        private SessionVault $sessionVault,
        private KontoauszugImport $import,
        private BankAccountService $konten,
        private CsvProfileRepository $profile,
        private ?\Closure $istUpload = null,
    ) {
    }

    /** The upload form and the latest imports. */
    public function start(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->startSeite($this->tresor($request), [], null);
    }

    public function hochladen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::START);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        $felder = ['konto' => self::text($request, 'konto'), 'format' => self::text($request, 'format')];
        [$inhalt, $fehler] = $this->upload($request);
        if ($inhalt === null) {
            return $this->startSeite($tresor, $felder, $fehler ?? 'Bitte eine Datei wählen.', 'datei', 422);
        }

        $format = null;
        if ($felder['format'] !== '') {
            try {
                $format = ImportFormat::fromString($felder['format']);
            } catch (\InvalidArgumentException) {
                return $this->startSeite($tresor, $felder, 'Bitte ein Format aus der Liste wählen.', 'format', 422);
            }
        }
        $konto = ctype_digit($felder['konto']) ? (int) $felder['konto'] : null;

        try {
            $id = $this->import->hochladen(
                $tresor,
                $inhalt,
                (string) (($request->files['datei'] ?? [])['name'] ?? ''),
                $konto,
                $format,
                $this->session->userId(),
                new \DateTimeImmutable(),
            );
        } catch (KontoauszugUnlesbar $e) {
            return $this->startSeite($tresor, $felder, $e->getMessage(), 'datei', 422, $e->csvFormatFehlt);
        } catch (BankRuleViolation $e) {
            return $this->startSeite($tresor, $felder, $e->getMessage(), 'konto', 422);
        }

        return Response::redirect(self::START . '/' . $id);
    }

    /**
     * The preview, the running chain or the result - whatever the import's
     * status is.
     *
     * @param array<string, string> $params
     */
    public function ansicht(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        return $this->importSeite($tresor, (int) $params['id'], null);
    }

    /**
     * "Welches Konto?"
     *
     * @param array<string, string> $params
     */
    public function konto(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::START . '/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        $konto = self::text($request, 'konto');
        if (!ctype_digit($konto)) {
            return $this->importSeite($tresor, $id, 'Bitte ein Konto wählen.', 422);
        }
        try {
            $this->import->kontoWaehlen($tresor, $id, (int) $konto, new \DateTimeImmutable());
        } catch (BankRuleViolation | KontoauszugUnlesbar $e) {
            return $this->importSeite($tresor, $id, $e->getMessage(), 422);
        }

        return Response::redirect(self::START . '/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function uebernehmen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::START . '/' . $id);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return $this->gesperrt();
        }

        try {
            $this->import->uebernehmen($tresor, $id, $this->session->userId(), new \DateTimeImmutable());
        } catch (BankRuleViolation | KontoauszugUnlesbar $e) {
            return $this->importSeite($tresor, $id, $e->getMessage(), 422);
        }

        return Response::redirect(self::START . '/' . $id);
    }

    /**
     * One step of the chain, as JSON for public/js/kontoauszug.js.
     *
     * @param array<string, string> $params
     */
    public function schritt(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }
        $tresor = $this->tresor($request);
        if ($tresor === null) {
            return Response::json(['fehler' => self::TRESOR_GESPERRT], 403);
        }

        try {
            $stand = $this->import->schritt($tresor, (int) $params['id'], $request->ip, new \DateTimeImmutable());
        } catch (BankRuleViolation | KontoauszugUnlesbar $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json($stand->toArray());
    }

    /**
     * @param array<string, string> $params
     */
    public function verwerfen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure(self::START . '/' . $id);
        }

        if (!$this->import->verwerfen($id)) {
            $this->session->flash('Diese Vorschau gibt es nicht mehr – ein übernommener Import bleibt erhalten.', FlashArt::Fehler);

            return Response::redirect(self::START);
        }
        $this->session->flash('Vorschau verworfen, die Datei ist gelöscht.');

        return Response::redirect(self::START);
    }

    /**
     * @param array<string, string> $felder
     */
    private function startSeite(
        ?Vault $tresor,
        array $felder,
        ?string $fehler,
        ?string $fehlerFeld = null,
        int $status = 200,
        bool $csvFormatFehlt = false,
    ): ResponseInterface {
        $konten = $tresor === null ? [] : $this->konten->liste($tresor);
        $namen = [];
        foreach ($konten as $konto) {
            $namen[$konto->id] = $konto->data->name;
        }

        return Response::html($this->view->render('app/kontoauszug-import', [
            'title' => 'Kontoauszug importieren',
            'flash' => $this->session->pullFlash(),
            'csrf' => $this->session->csrfToken(),
            'entsperrt' => $tresor !== null,
            'konten' => array_values(array_filter($konten, static fn(BankAccount $k): bool => $k->kind === BankAccountKind::Bank && $k->active)),
            'kontoNamen' => $namen,
            'profile' => $this->profile->all(),
            'importe' => $tresor === null ? [] : $this->import->letzte(self::VERLAUF),
            'felder' => $felder + ['konto' => '', 'format' => ''],
            'fehler' => $fehler,
            'fehlerFeld' => $fehlerFeld,
            'csvFormatFehlt' => $csvFormatFehlt,
            'maxBytes' => KontoauszugImport::MAX_BYTES,
        ], Area::App), $status);
    }

    private function importSeite(Vault $tresor, int $id, ?string $fehler, int $status = 200): ResponseInterface
    {
        try {
            $vorschau = $this->import->vorschau($tresor, $id);
        } catch (KontoauszugUnlesbar $e) {
            $vorschau = null;
            $fehler = $e->getMessage();
            $status = 409;
        }
        if ($vorschau === null && $fehler === null) {
            return $this->nichtGefunden();
        }

        $laeuft = $vorschau?->import->status === BankImportStatus::Laeuft;

        return Response::html($this->view->render('app/kontoauszug', [
            'title' => 'Kontoauszug-Import',
            'flash' => $this->session->pullFlash(),
            'csrf' => $this->session->csrfToken(),
            'id' => $id,
            'vorschau' => $vorschau,
            'konten' => $vorschau?->import->status === BankImportStatus::Vorschau
                ? array_values(array_filter($this->konten->liste($tresor), static fn(BankAccount $k): bool => $k->kind === BankAccountKind::Bank && $k->active))
                : [],
            'fehler' => $fehler,
            'scripts' => $laeuft ? ['/js/kontoauszug.js'] : [],
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
        if ($fehler === \UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($fehler === \UPLOAD_ERR_INI_SIZE || $fehler === \UPLOAD_ERR_FORM_SIZE) {
            return [null, $zuGross];
        }
        $pfad = (string) ($datei['tmp_name'] ?? '');
        $istUpload = $this->istUpload ?? is_uploaded_file(...);
        if ($fehler !== \UPLOAD_ERR_OK || $pfad === '' || !$istUpload($pfad)) {
            return [null, 'Die Datei konnte nicht hochgeladen werden – bitte erneut wählen.'];
        }
        $groesse = filesize($pfad);
        if ($groesse === false || $groesse > KontoauszugImport::MAX_BYTES) {
            return [null, $zuGross];
        }
        $inhalt = file_get_contents($pfad);

        return $inhalt === false || $inhalt === '' ? [null, 'Die Datei ist leer.'] : [$inhalt, null];
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? trim($wert) : '';
    }

    private function tresor(Request $request): ?Vault
    {
        try {
            return $this->sessionVault->unlock(Cookie::vaultKeyFrom($request));
        } catch (CryptoException) {
            return null;
        }
    }

    /** Back to the start page, which explains the locked vault; nothing was written. */
    private function gesperrt(): ResponseInterface
    {
        $this->session->flash(self::TRESOR_GESPERRT, FlashArt::Fehler);

        return Response::redirect(self::START);
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'Import nicht gefunden',
            'message' => 'Diesen Kontoauszug-Import gibt es nicht (mehr).',
            'startseite' => self::START,
        ], Area::App), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
