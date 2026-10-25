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
use App\Service\Rechtstexte\Rechtstext;
use App\Service\Rechtstexte\Rechtstexte;
use App\View\Area;
use App\View\FlashArt;
use App\View\View;

/**
 * `/admin/rechtstexte` (issue #80/M13-3, E-46): Impressum,
 * Datenschutzerklärung and the privacy notice on /einreichen, as one form.
 *
 * Permission: `admin.settings` (app/src/routes.php), like the other
 * operating configuration of the public submission (/admin/einreichung).
 * The texts are public by nature and stored as plaintext settings; the
 * audit entry names the changed texts, never their content.
 */
final readonly class RechtstexteController
{
    public function __construct(
        private View $view,
        private Session $session,
        private Rechtstexte $rechtstexte,
        private AuditLog $audit,
        /** For the reminder which usable AI providers the privacy policy must name. */
        private ?AiProviderRepository $kiAnbieter = null,
    ) {
    }

    public function page(Request $request): ResponseInterface
    {
        $this->session->start();

        $texte = [];
        foreach (Rechtstext::cases() as $art) {
            $texte[$art->value] = $this->rechtstexte->lesen($art)->text;
        }

        return $this->formular($texte, null);
    }

    public function save(Request $request): ResponseInterface
    {
        $this->session->start();
        if (!$this->session->checkCsrf($request)) {
            $this->session->flash('Die Sitzung ist abgelaufen – bitte erneut versuchen.', FlashArt::Fehler);

            return Response::redirect('/admin/rechtstexte');
        }

        $eingabe = [];
        foreach (Rechtstext::cases() as $art) {
            $wert = $request->post[$art->value] ?? '';
            $eingabe[$art->value] = is_string($wert) ? $wert : '';
        }

        // Check all before writing any: a too-long second text must not
        // leave the first one saved and the form showing an error.
        foreach (Rechtstext::cases() as $art) {
            $fehler = Rechtstexte::fehler($art, $eingabe[$art->value]);
            if ($fehler !== null) {
                return $this->formular($eingabe, $fehler, 422);
            }
        }

        $geaendert = [];
        foreach (Rechtstext::cases() as $art) {
            $vorher = $this->rechtstexte->lesen($art);
            $this->rechtstexte->speichern($art, $eingabe[$art->value]);
            $nachher = $this->rechtstexte->lesen($art);
            if ($vorher->text !== $nachher->text || $vorher->istVorlage !== $nachher->istVorlage) {
                $geaendert[] = $art->value;
            }
        }

        if ($geaendert !== []) {
            $this->audit->record(AuditAction::EinstellungRechtstexte, $this->session->userId(), $request->ip, details: [
                'texte' => $geaendert,
            ]);
        }
        $this->session->flash($geaendert === [] ? 'Keine Änderung.' : 'Rechtstexte gespeichert.');

        return Response::redirect('/admin/rechtstexte');
    }

    /**
     * @param array<string, string> $texte by Rechtstext value
     */
    private function formular(array $texte, ?string $fehler, int $status = 200): ResponseInterface
    {
        $felder = [];
        foreach (Rechtstext::cases() as $art) {
            $gespeichert = $this->rechtstexte->lesen($art);
            $felder[] = [
                'art' => $art,
                'text' => $texte[$art->value] ?? $gespeichert->text,
                'istVorlage' => $gespeichert->istVorlage,
                'platzhalter' => $gespeichert->platzhalter(),
            ];
        }

        $kiAnbieter = $this->kiAnbieter === null
            ? []
            : array_values(array_filter($this->kiAnbieter->all(), static fn(KiAnbieter $a): bool => $a->nutzbar()));

        return Response::html($this->view->render('admin/rechtstexte', [
            'title' => 'Rechtstexte',
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->pullFlash(),
            'felder' => $felder,
            'kiAnbieter' => $kiAnbieter,
            'fehler' => $fehler,
        ], Area::Admin), $status);
    }
}
