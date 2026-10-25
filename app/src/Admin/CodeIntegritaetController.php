<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Audit\AuditLog;
use App\Service\Integritaet\CodeIntegritaet;
use App\Service\Integritaet\IntegritaetPruefungLaeuftBereits;
use App\Service\Integritaet\IntegritaetPruefungNichtMoeglich;
use App\View\Area;
use App\View\View;

/**
 * /admin/integritaet (issue #79/M13-2, docs/spec/01-sicherheit.md section
 * 8): the code integrity check as a step chain driven by
 * public/js/integritaet.js, and the result of the last run.
 *
 * The files of a release hold no business data, so nothing here needs the
 * vault - the page also works for an admin whose vault is locked. Every
 * finished run goes to the audit log, the outcome in the action itself
 * (unchanged / differences), the details only version and counts.
 *
 * ---------------------------------------------------------------------
 * RIGHT REQUIRED: `admin.system` ("Backup, Update, Wartung", 01 section 4),
 * the same right as Update - the check compares against what the updater
 * installed. Declared on every route in app/src/routes.php and checked by
 * App\Http\LoginGuard. CSRF on every write.
 * ---------------------------------------------------------------------
 */
final readonly class CodeIntegritaetController
{
    public function __construct(
        private View $view,
        private Session $session,
        private CodeIntegritaet $pruefung,
        private AuditLog $audit,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return Response::html($this->view->render('admin/integritaet', [
            'title' => 'Code-Integrität',
            // External file, never inline: script-src 'self' without
            // 'unsafe-inline' (CLAUDE.md section 4).
            'scripts' => ['/js/integritaet.js'],
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'hindernis' => $this->pruefung->hindernis(),
            'warnung' => $this->pruefung->warnung(),
            'stand' => $this->pruefung->stand(),
        ], Area::Admin));
    }

    /**
     * Starts a new run; the page's script then calls schritt() until the
     * server reports it finished. Answers JSON.
     */
    public function starten(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        try {
            $stand = $this->pruefung->starten();
        } catch (IntegritaetPruefungNichtMoeglich | IntegritaetPruefungLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        }

        return Response::json(self::antwort($stand));
    }

    /**
     * One step of the chain (CLAUDE.md section 1: no long request).
     */
    public function schritt(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return Response::json(['fehler' => 'Sitzung abgelaufen – bitte die Seite neu laden.'], 403);
        }

        // Only the step that finishes the run writes a row - under the
        // chain's lock and before the result is stored, so a second tab never
        // writes it again and a failed write is retried by the next step.
        $abschluss = function (array $stand) use ($request): void {
            $this->audit->record(
                CodeIntegritaet::unveraendert($stand)
                    ? AuditAction::CodeIntegritaetUnveraendert
                    : AuditAction::CodeIntegritaetAbweichung,
                $this->session->userId(),
                $request->ip,
                details: [
                    'version' => (string) ($stand['version'] ?? ''),
                    'dateien' => (int) $stand['gesamt'],
                    'geaendert' => (int) $stand['anzahl']['geaendert'],
                    'fehlend' => (int) $stand['anzahl']['fehlend'],
                    'zusaetzlich' => (int) $stand['anzahl']['zusaetzlich'],
                ],
            );
        };

        try {
            $stand = $this->pruefung->schritt($abschluss);
        } catch (IntegritaetPruefungNichtMoeglich | IntegritaetPruefungLaeuftBereits $e) {
            return Response::json(['fehler' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            // Class only (CLAUDE.md section 4): a message may name a server path.
            return Response::json(['fehler' => 'Schritt fehlgeschlagen: ' . $e::class], 500);
        }

        return Response::json(self::antwort($stand));
    }

    /**
     * What the script needs for its status line - no paths: the finished
     * result is shown by the page itself after a reload.
     *
     * @param array<string, mixed> $stand
     * @return array<string, mixed>
     */
    private static function antwort(array $stand): array
    {
        return [
            'phase' => (string) $stand['phase'],
            'fertig' => $stand['phase'] === CodeIntegritaet::PHASE_FERTIG,
            'geprueft' => (int) $stand['position'],
            'gesamt' => (int) $stand['gesamt'],
        ];
    }
}
