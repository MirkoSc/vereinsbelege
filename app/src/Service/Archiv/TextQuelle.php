<?php

declare(strict_types=1);

namespace App\Service\Archiv;

/**
 * A ZIP that is already a string in memory - for the tests and for small
 * archives built on the fly. The import itself reads the upload chunks
 * (App\Service\Archiv\UploadQuelle).
 */
final readonly class TextQuelle implements ByteQuelle
{
    public function __construct(private string $inhalt)
    {
    }

    public function laenge(): int
    {
        return strlen($this->inhalt);
    }

    public function lesen(int $offset, int $laenge): string
    {
        if ($offset < 0 || $laenge < 1 || $offset >= strlen($this->inhalt)) {
            return '';
        }

        return substr($this->inhalt, $offset, $laenge);
    }
}
