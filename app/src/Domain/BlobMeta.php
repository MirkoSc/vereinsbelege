<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a blob is: MIME type, the name it was uploaded under, pixel size,
 * page count (docs/spec/02-datenmodell.md "Dateien").
 *
 * All of it is business data - a file name alone names the supplier - so it
 * never becomes a plaintext column. It is stored as JSON in `meta_enc`,
 * encrypted under the blob's own data key.
 */
final readonly class BlobMeta
{
    public function __construct(
        public string $mimeType,
        public string $originalName = '',
        public ?int $width = null,
        public ?int $height = null,
        public ?int $pages = null,
    ) {
    }

    public function toJson(): string
    {
        $data = ['mime' => $this->mimeType, 'name' => $this->originalName];
        // Absent instead of null: the ciphertext of a plain JPEG stays short,
        // which keeps meta_enc inside its column.
        foreach (['width' => $this->width, 'height' => $this->height, 'pages' => $this->pages] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \JsonException('Blob metadata is not an object.');
        }

        return new self(
            mimeType: isset($data['mime']) ? (string) $data['mime'] : '',
            originalName: isset($data['name']) ? (string) $data['name'] : '',
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
            pages: isset($data['pages']) ? (int) $data['pages'] : null,
        );
    }
}
