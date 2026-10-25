<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Http\Request;
use App\Http\Response;
use App\Service\Rechtstexte\Rechtstext;
use App\Service\Rechtstexte\Rechtstexte;
use App\View\Area;
use App\View\View;

/**
 * `/impressum` and `/datenschutz` (issue #80/M13-3, E-46): the texts the
 * club maintains under /admin/rechtstexte. Public, without a session
 * (App\View\Area::Oeffentlich) - like /einreichen, nothing here reads or
 * writes one.
 */
final readonly class RechtstextController
{
    public function __construct(
        private View $view,
        private Rechtstexte $rechtstexte,
    ) {
    }

    public function impressum(Request $request): Response
    {
        return $this->seite(Rechtstext::Impressum);
    }

    public function datenschutz(Request $request): Response
    {
        return $this->seite(Rechtstext::Datenschutz);
    }

    private function seite(Rechtstext $art): Response
    {
        return Response::html($this->view->render('rechtstext', [
            'title' => $art->titel(),
            'inhalt' => $this->rechtstexte->lesen($art),
        ], Area::Oeffentlich));
    }
}
