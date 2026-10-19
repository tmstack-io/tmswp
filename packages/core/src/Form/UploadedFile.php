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
        $message = match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'Please select a file.',
            UPLOAD_ERR_NO_TMP_DIR => 'The temporary upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The file could not be written.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'The file upload failed.',
        };

        return \function_exists('__') ? __($message, 'tmswp') : $message;
    }
}
