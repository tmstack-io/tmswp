<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use finfo;

final readonly class UploadedFile
{
    public function __construct(
        public string $tmpPath,
        public string $clientName,
        public string $clientMime,
        public int $size,
        public int $error,
    ) {}

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $entry */
    public static function fromFilesEntry(array $entry): self
    {
        return new self($entry['tmp_name'], $entry['name'], $entry['type'], $entry['size'], $entry['error']);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpPath);
    }

    public function detectedMime(): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($this->tmpPath);

        return \is_string($mime) ? $mime : 'application/octet-stream';
    }

    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('The uploaded file is too large.', 'tmswp'),
            UPLOAD_ERR_PARTIAL => __('The file was only partially uploaded.', 'tmswp'),
            UPLOAD_ERR_NO_FILE => __('Please select a file.', 'tmswp'),
            UPLOAD_ERR_NO_TMP_DIR => __('The temporary upload folder is missing.', 'tmswp'),
            UPLOAD_ERR_CANT_WRITE => __('The file could not be written.', 'tmswp'),
            UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'tmswp'),
            default => __('The file upload failed.', 'tmswp'),
        };
    }
}
