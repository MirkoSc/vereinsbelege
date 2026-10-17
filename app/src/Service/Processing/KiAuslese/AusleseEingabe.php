<?php

declare(strict_types=1);

namespace App\Service\Processing\KiAuslese;

use App\Domain\Category;

/**
 * Everything the AI gets to read for one receipt (issue #47/M7-5,
 * docs/spec/03-erfassung-und-ki.md section 6 "Auslese-Schema") - gathered
 * by whoever runs the job: the session's App\Service\Document\KiAuslesung
 * now, the optional worker one day (CLAUDE.md section 6a). Business data:
 * never log it.
 *
 * Either $text (the PDF text layer reaches - `ocr_status = fertig`, the
 * page images are not evaluated) or $bilder carries the receipt; $eRechnung
 * comes on top when the document holds a read e-invoice (section 3: the AI
 * then only adds category and purpose).
 */
final readonly class AusleseEingabe
{
    /**
     * @param ?string $text the text layer, pages separated by
     *        App\Service\Processing\Textlayer::SEITENWECHSEL
     * @param ?array<string, mixed> $eRechnung the read e-invoice in the shape
     *        of `extract-v1`
     * @param list<string> $bilder JPEG page images in page order - already
     *        capped by Auslesung::bilderGrenze() (empty when only the
     *        page count is needed, Auslesung::nichtLesbar())
     * @param int $seitenGesamt how many page images the receipt has, sent
     *        or not
     * @param string $freitext what the submitter wrote (public submission,
     *        so: untrusted)
     * @param list<Category> $kategorien the active categories
     * @param array<int, string> $kostenstellen the active cost centers, id => name
     *        (App\Repository\CostCenterRepository::active())
     */
    public function __construct(
        public ?string $text,
        public ?array $eRechnung,
        public array $bilder,
        public int $seitenGesamt,
        public string $freitext,
        public array $kategorien,
        public array $kostenstellen,
    ) {
    }
}
