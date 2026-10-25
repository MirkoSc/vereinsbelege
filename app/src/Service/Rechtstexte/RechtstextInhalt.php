<?php

declare(strict_types=1);

namespace App\Service\Rechtstexte;

use App\View\EinfachesMarkdown;

/**
 * One maintained text as it is shown: the stored text, or the shipped
 * template while the club has not saved one.
 */
final readonly class RechtstextInhalt
{
    public function __construct(
        public Rechtstext $art,
        public string $text,
        /** True while nothing is stored - the template is shown. */
        public bool $istVorlage,
    ) {
    }

    /** Safe HTML (App\View\EinfachesMarkdown escapes every input character). */
    public function html(): string
    {
        return EinfachesMarkdown::html($this->text);
    }

    /** @return list<string> `[...]` placeholders still in the text */
    public function platzhalter(): array
    {
        return EinfachesMarkdown::platzhalter($this->text);
    }

    /** Whether the page should say "template, not yet checked by the club". */
    public function unfertig(): bool
    {
        return $this->istVorlage || $this->platzhalter() !== [];
    }
}
