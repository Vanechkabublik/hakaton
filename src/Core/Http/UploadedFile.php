<?php

declare(strict_types=1);

namespace App\Core\Http;

final class UploadedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly int $error,
    ) {
    }

    /** @param array<string,mixed> $file */
    public static function fromArray(array $file): self
    {
        return new self(
            basename((string) ($file['name'] ?? '')),
            (string) ($file['tmp_name'] ?? ''),
            (int) ($file['size'] ?? 0),
            (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    public function isProvided(): bool
    {
        return $this->error !== UPLOAD_ERR_NO_FILE;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->tmpPath !== ''
            && (is_uploaded_file($this->tmpPath) || (getenv('APP_ENV') === 'testing' && is_file($this->tmpPath)));
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
            UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран',
            default => 'Не удалось загрузить файл',
        };
    }

    /** Реальный MIME по содержимому, а не по расширению. */
    public function mimeType(): string
    {
        $info = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $info->file($this->tmpPath);
    }
}
