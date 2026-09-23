<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AuditAction;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SettingRepository;
use App\Service\Audit\AuditLog;
use App\Service\Submission\EinreichungsEinstellungen;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * Admin page for the public submission's spam defence (issue #25/M4-3,
 * docs/spec/01-sicherheit.md section 5): the rate/size/page limits
 * (App\Service\Submission\EinreichungsEinstellungen) and the pause switch -
 * "Admin kann die Einreichung vorübergehend pausieren".
 *
 * Permission: `admin.settings` (app/src/routes.php), the same right as
 * Mail/Speicher/Kostenstellen - operating configuration, not accounts or
 * their rights. Plaintext settings, no vault involved.
 */
final readonly class SubmissionSettingsController
{
    public function __construct(
        private View $view,
        private Session $session,
        private SettingRepository $settings,
        private AuditLog $audit,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        return $this->formular(EinreichungsEinstellungen::fromSettings($this->settings), null);
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        $bisherig = EinreichungsEinstellungen::fromSettings($this->settings);
        $eingabe = new EinreichungsEinstellungen(
            pausiert: $bisherig->pausiert,
            limitProIpStunde: self::zahl($request, 'limit_ip_stunde'),
            limitGesamtStunde: self::zahl($request, 'limit_gesamt_stunde'),
            maxSeiten: self::zahl($request, 'max_seiten'),
            maxDateiMb: self::zahl($request, 'max_datei_mb'),
            maxEinreichungMb: self::zahl($request, 'max_einreichung_mb'),
        );

        if (self::hatUngueltigenWert($request)) {
            return $this->formular($eingabe, 'Bitte überall eine positive Zahl angeben.', 422);
        }

        $eingabe->speichern($this->settings);
        $gespeichert = EinreichungsEinstellungen::fromSettings($this->settings);

        $this->audit->record(AuditAction::EinstellungEinreichung, $this->session->userId(), $request->ip, details: [
            'limit_ip_stunde' => $gespeichert->limitProIpStunde,
            'limit_gesamt_stunde' => $gespeichert->limitGesamtStunde,
            'max_seiten' => $gespeichert->maxSeiten,
            'max_datei_mb' => $gespeichert->maxDateiMb,
            'max_einreichung_mb' => $gespeichert->maxEinreichungMb,
        ]);
        $this->session->flash('Einstellungen gespeichert.');

        return Response::redirect('/admin/einreichung');
    }

    public function pausieren(Request $request): ResponseInterface
    {
        return $this->pauseUmschalten($request, true, 'Die Einreichung ist pausiert – die öffentliche Seite zeigt nur noch einen Hinweis.');
    }

    public function fortsetzen(Request $request): ResponseInterface
    {
        return $this->pauseUmschalten($request, false, 'Die Einreichung ist wieder freigegeben.');
    }

    private function pauseUmschalten(Request $request, bool $pausiert, string $meldung): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            return $this->csrfFailure();
        }

        EinreichungsEinstellungen::setzePausiert($this->settings, $pausiert);
        $this->audit->record(AuditAction::EinstellungEinreichung, $this->session->userId(), $request->ip, details: ['pausiert' => $pausiert]);
        $this->session->flash($meldung);

        return Response::redirect('/admin/einreichung');
    }

    private function formular(EinreichungsEinstellungen $einstellungen, ?string $fehler, int $status = 200): ResponseInterface
    {
        return Response::html($this->view->render('admin/einreichung', [
            'title' => 'Einreichung',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'einstellungen' => $einstellungen,
            'fehler' => $fehler,
        ], Area::Admin), $status);
    }

    private static function zahl(Request $request, string $feld): int
    {
        $wert = filter_var($request->post[$feld] ?? '', FILTER_VALIDATE_INT);

        return is_int($wert) ? $wert : -1;
    }

    private static function hatUngueltigenWert(Request $request): bool
    {
        foreach (['limit_ip_stunde', 'limit_gesamt_stunde', 'max_seiten', 'max_datei_mb', 'max_einreichung_mb'] as $feld) {
            if (self::zahl($request, $feld) < 1) {
                return true;
            }
        }

        return false;
    }

    private function csrfFailure(): ResponseInterface
    {
        $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

        return Response::redirect('/admin/einreichung');
    }
}
