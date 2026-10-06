<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\AiProviderRepository;
use App\Service\Audit\AuditLog;
use App\Service\Ki\KiAnbieter;
use App\Service\Ki\KiAnbieterRegelverstoss;
use App\Service\Ki\KiAnbieterService;
use App\Service\Ki\KiVorlage;
use App\Service\Ki\Verbindungstest;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin pages "KI-Anbieter" (M7-1, issue #41, docs/spec/
 * 03-erfassung-und-ki.md section 6 "Client"): the profiles, a new one from
 * a template, changing and deleting one, choosing the default and
 * "Verbindung testen".
 *
 * Behind `admin.settings` (docs/spec/01-sicherheit.md section 4: "KI, Mail,
 * Speicher, ..."), declared in app/src/routes.php; CSRF on every write.
 * Profiles and keys are operating data under the server key, no vault
 * involved (like MailController).
 *
 * The API key never leaves the server again once entered: the form field
 * stays empty (empty = unchanged, a checkbox removes it), audit details
 * name changed fields only, and the connection test result is stripped of
 * the key (App\Service\Ki\Verbindungstest).
 */
final readonly class KiAnbieterController
{
    public function __construct(
        private View $view,
        private Session $session,
        private AiProviderRepository $anbieter,
        private KiAnbieterService $service,
        private Verbindungstest $verbindungstest,
        private AuditLog $audit,
    ) {
    }

    public function liste(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/ki-anbieter', [
            'title' => 'KI-Anbieter',
            'flash' => $this->session->pullFlash(),
            'profile' => $this->anbieter->all(),
            'vorlagen' => KiVorlage::cases(),
        ], Area::Admin));
    }

    public function neu(Request $request): ResponseInterface
    {
        $this->session->start();
        $vorlage = KiVorlage::tryFrom((string) ($request->query['vorlage'] ?? '')) ?? KiVorlage::OpenAi;

        return $this->formular(null, self::ausVorlage($vorlage), null);
    }

    public function anlegen(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/ki-anbieter/neu');
        }

        $felder = self::felder($request);
        try {
            $id = $this->service->anlegen($felder, self::neuerKey($request));
        } catch (KiAnbieterRegelverstoss $e) {
            return $this->formular(null, $felder, $e->getMessage(), 422);
        }

        $gespeichert = $this->anbieter->find($id);
        $this->audit->record(AuditAction::KiAnbieterAngelegt, $this->session->userId(), $request->ip, $id, $gespeichert === null ? [] : self::details($gespeichert));
        $this->session->flash(sprintf('KI-Anbieter „%s“ angelegt.', trim($felder['name'])));

        return Response::redirect('/admin/ki-anbieter');
    }

    /**
     * @param array<string, string> $params
     */
    public function bearbeiten(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $profil = $this->anbieter->find((int) $params['id']);
        if ($profil === null) {
            return $this->nichtGefunden();
        }

        return $this->formular($profil, [
            'name' => $profil->name,
            'base_url' => $profil->baseUrl,
            'model' => $profil->model,
            'vision' => $profil->faehigkeiten->vision,
            'json_schema' => $profil->faehigkeiten->jsonSchema,
            'max_images' => (string) $profil->faehigkeiten->maxImages,
            'max_tokens' => (string) $profil->faehigkeiten->maxTokens,
            'timeout_s' => (string) $profil->timeoutS,
            'active' => $profil->active,
        ], null);
    }

    /**
     * @param array<string, string> $params
     */
    public function speichern(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/ki-anbieter/' . $id);
        }
        $vorher = $this->anbieter->find($id);
        if ($vorher === null) {
            return $this->nichtGefunden();
        }

        $felder = self::felder($request);
        $neuerKey = self::neuerKey($request);
        try {
            $this->service->aendern($id, $felder, $neuerKey);
        } catch (KiAnbieterRegelverstoss $e) {
            return $this->formular($vorher, $felder, $e->getMessage(), 422);
        }

        $nachher = $this->anbieter->find($id) ?? $vorher;
        $this->audit->record(AuditAction::KiAnbieterGeaendert, $this->session->userId(), $request->ip, $id, [
            ...self::details($nachher),
            'felder' => self::geaendert($vorher, $nachher, $neuerKey),
        ]);
        $this->session->flash(sprintf('KI-Anbieter „%s“ gespeichert.', $nachher->name));

        return Response::redirect('/admin/ki-anbieter');
    }

    /**
     * @param array<string, string> $params
     */
    public function loeschen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/ki-anbieter/' . $id);
        }

        $profil = $this->anbieter->find($id);
        try {
            $this->service->loeschen($id);
        } catch (KiAnbieterRegelverstoss $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect($profil === null ? '/admin/ki-anbieter' : '/admin/ki-anbieter/' . $id);
        }

        $this->audit->record(AuditAction::KiAnbieterGeloescht, $this->session->userId(), $request->ip, $id, ['name' => (string) $profil?->name]);
        $this->session->flash(sprintf('KI-Anbieter „%s“ gelöscht.', (string) $profil?->name));

        return Response::redirect('/admin/ki-anbieter');
    }

    /**
     * @param array<string, string> $params
     */
    public function standard(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/ki-anbieter');
        }

        try {
            $this->service->alsStandard($id);
        } catch (KiAnbieterRegelverstoss $e) {
            $this->session->flash($e->getMessage(), FlashArt::Fehler);

            return Response::redirect('/admin/ki-anbieter');
        }

        $profil = $this->anbieter->find($id);
        $this->audit->record(AuditAction::KiAnbieterStandard, $this->session->userId(), $request->ip, $id, ['name' => (string) $profil?->name]);
        $this->session->flash(sprintf('„%s“ ist jetzt das Standardprofil.', (string) $profil?->name));

        return Response::redirect('/admin/ki-anbieter');
    }

    /**
     * One short call to the provider (App\Service\Ki\Verbindungstest), the
     * outcome as flash message. Not audited: it changes nothing.
     *
     * @param array<string, string> $params
     */
    public function testen(Request $request, array $params): ResponseInterface
    {
        $this->session->start();
        $id = (int) $params['id'];
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure('/admin/ki-anbieter');
        }
        $profil = $this->anbieter->find($id);
        if ($profil === null) {
            return $this->nichtGefunden();
        }

        $apiKey = $this->anbieter->apiKey($id);
        if ($apiKey === null && $profil->keyGesetzt) {
            $this->session->flash(
                sprintf('„%s“: Der gespeicherte API-Key lässt sich nicht entschlüsseln (anderer Server-Schlüssel?) – bitte neu eingeben.', $profil->name),
                FlashArt::Fehler,
            );

            return Response::redirect('/admin/ki-anbieter');
        }

        $ergebnis = $this->verbindungstest->pruefe($profil, $apiKey ?? '');
        $this->session->flash(
            sprintf('„%s“: %s', $profil->name, $ergebnis->text()),
            $ergebnis->ok ? FlashArt::Ok : FlashArt::Fehler,
        );

        return Response::redirect('/admin/ki-anbieter');
    }

    /**
     * @param array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool} $felder
     */
    private function formular(?KiAnbieter $profil, array $felder, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/ki-anbieter-form', [
            'title' => $profil === null ? 'Neuer KI-Anbieter' : 'KI-Anbieter ' . $profil->name,
            'profil' => $profil,
            'felder' => $felder,
            'fehler' => $fehler,
            'flash' => $this->session->pullFlash(),
        ], Area::Admin), $status);
    }

    /**
     * @return array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool}
     */
    private static function ausVorlage(KiVorlage $vorlage): array
    {
        $faehigkeiten = $vorlage->faehigkeiten();

        return [
            'name' => $vorlage->label(),
            'base_url' => $vorlage->baseUrl(),
            'model' => $vorlage->modell(),
            'vision' => $faehigkeiten->vision,
            'json_schema' => $faehigkeiten->jsonSchema,
            'max_images' => (string) $faehigkeiten->maxImages,
            'max_tokens' => (string) $faehigkeiten->maxTokens,
            'timeout_s' => (string) $vorlage->timeoutS(),
            'active' => true,
        ];
    }

    /**
     * @return array{name: string, base_url: string, model: string, vision: bool, json_schema: bool, max_images: string, max_tokens: string, timeout_s: string, active: bool}
     */
    private static function felder(Request $request): array
    {
        return [
            'name' => self::text($request, 'name'),
            'base_url' => self::text($request, 'base_url'),
            'model' => self::text($request, 'model'),
            'vision' => ($request->post['vision'] ?? '') === '1',
            'json_schema' => ($request->post['json_schema'] ?? '') === '1',
            'max_images' => self::text($request, 'max_images'),
            'max_tokens' => self::text($request, 'max_tokens'),
            'timeout_s' => self::text($request, 'timeout_s'),
            'active' => ($request->post['active'] ?? '') === '1',
        ];
    }

    /**
     * null = keep, '' = remove, anything else = replace (the form never
     * carries the stored key back, like the SMTP password).
     */
    private static function neuerKey(Request $request): ?string
    {
        if (($request->post['api_key_entfernen'] ?? '') === '1') {
            return '';
        }
        $key = trim(self::text($request, 'api_key'));

        return $key === '' ? null : $key;
    }

    /**
     * What the audit log keeps of a profile: no key, only whether one is set.
     *
     * @return array<string, mixed>
     */
    private static function details(KiAnbieter $profil): array
    {
        return [
            'name' => $profil->name,
            'base_url' => $profil->baseUrl,
            'modell' => $profil->model,
            'aktiv' => $profil->active,
            'key_gesetzt' => $profil->keyGesetzt,
        ];
    }

    /**
     * @return list<string> which fields changed - names only
     */
    private static function geaendert(KiAnbieter $vorher, KiAnbieter $nachher, ?string $neuerKey): array
    {
        return array_keys(array_filter([
            'name' => $vorher->name !== $nachher->name,
            'base_url' => $vorher->baseUrl !== $nachher->baseUrl,
            'modell' => $vorher->model !== $nachher->model,
            'faehigkeiten' => $vorher->faehigkeiten != $nachher->faehigkeiten,
            'zeitlimit' => $vorher->timeoutS !== $nachher->timeoutS,
            'aktiv' => $vorher->active !== $nachher->active,
            'api_key' => $neuerKey !== null && ($neuerKey !== '' || $vorher->keyGesetzt),
        ]));
    }

    private static function text(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function nichtGefunden(): ResponseInterface
    {
        return Response::html($this->view->render('error', [
            'title' => 'KI-Anbieter nicht gefunden',
            'message' => 'Dieses Profil gibt es nicht (mehr).',
            'startseite' => '/admin/ki-anbieter',
        ], Area::Admin), 404);
    }

    private function csrfFailure(string $ziel): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect($ziel);
    }
}
