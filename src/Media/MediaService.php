<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Config;
use App\Core\Http\UploadedFile;
use App\Core\Security\Crypto;
use App\User\User;

/** Загрузка изображений: валидация, ресайз до набора ширин, WebP, запись в медиатеку. */
final class MediaService
{
    /** Ширины адаптивных вариантов. */
    private const WIDTHS = [320, 640, 960, 1280, 1920];
    private const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    public function __construct(
        private readonly ImageProcessor $images,
        private readonly MediaRepository $media,
        private readonly Config $config,
    ) {
    }

    /** @throws ImageException */
    public function upload(UploadedFile $file, ?User $user, string $alt = ''): Media
    {
        $this->assertUploadable($file);

        return $this->importFile($file->tmpPath, $file->name, $user, $alt);
    }

    /**
     * Обработать изображение с диска (загрузка из админки, сидеры, импорт): ресайз и WebP-варианты.
     *
     * @throws ImageException
     */
    public function importFile(string $path, string $originalName, ?User $user, string $alt = ''): Media
    {
        $source = $this->images->load($path);
        $originalWidth = imagesx($source);
        $originalHeight = imagesy($source);

        $base = gmdate('Y/m') . '/' . Crypto::token(8);
        $variants = [];
        $total = 0;
        $widths = array_values(array_filter(self::WIDTHS, static fn (int $w): bool => $w < $originalWidth));
        $widths[] = min($originalWidth, max(self::WIDTHS));
        $widths = array_values(array_unique($widths));
        sort($widths);
        try {
            foreach ($widths as $width) {
                $resized = $width === $originalWidth ? $source : $this->images->resizeToWidth($source, $width);
                $relative = "{$base}-{$width}.webp";
                $total += $this->images->saveWebp($resized, $this->absolute($relative));
                $variants[(string) $width] = $relative;
            }
        } catch (ImageException $e) {
            $this->removeFiles(array_values($variants));
            throw $e;
        }

        $id = $this->media->create([
            'user_id' => $user?->id,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime' => 'image/webp',
            'width' => $originalWidth,
            'height' => $originalHeight,
            'size_bytes' => $total,
            'alt' => mb_substr(trim($alt), 0, 300),
            'variants' => $variants,
        ]);

        return $this->media->find($id) ?? throw new ImageException('Не удалось сохранить запись о файле');
    }

    /**
     * Загрузить аватар: квадрат 256×256 WebP. Возвращает относительный путь.
     *
     * @throws ImageException
     */
    public function uploadAvatar(UploadedFile $file, int $userId): string
    {
        $this->assertUploadable($file);
        $source = $this->images->load($file->tmpPath);
        $avatar = $this->images->cropSquare($source, 256);
        $relative = 'avatars/' . $userId . '-' . Crypto::token(6) . '.webp';
        $this->images->saveWebp($avatar, $this->absolute($relative));

        return $relative;
    }

    /** Сохранить изображение логотипа/OG без изменения формата SVG/PNG (только для админов). */
    public function uploadBranding(UploadedFile $file, string $name): string
    {
        $this->assertUploadable($file, true);
        $mime = $file->mimeType();
        if ($mime === 'image/svg+xml') {
            $svg = (string) file_get_contents($file->tmpPath);
            if (preg_match('/<script|onload\s*=|onerror\s*=|javascript:|<foreignObject/i', $svg)) {
                throw new ImageException('SVG содержит недопустимые элементы');
            }
            $relative = 'branding/' . $name . '-' . Crypto::token(4) . '.svg';
            $this->ensureDir(dirname($this->absolute($relative)));
            file_put_contents($this->absolute($relative), $svg);

            return $relative;
        }
        $image = $this->images->load($file->tmpPath);
        $image = $this->images->resizeToWidth($image, 1200);
        $relative = 'branding/' . $name . '-' . Crypto::token(4) . '.webp';
        $this->images->saveWebp($image, $this->absolute($relative));

        return $relative;
    }

    public function delete(Media $media): void
    {
        $this->removeFiles($media->allPaths());
        $this->media->deleteById($media->id);
    }

    public function deleteFile(string $relative): void
    {
        $this->removeFiles([$relative]);
    }

    public function absolute(string $relative): string
    {
        $relative = ltrim(str_replace(['..', '\\'], '', $relative), '/');

        return $this->config->storagePath('uploads/' . $relative);
    }

    /** @param list<string> $relativePaths */
    private function removeFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $path) {
            $file = $this->absolute($path);
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ImageException('Не удалось создать каталог для загрузок');
        }
    }

    private function assertUploadable(UploadedFile $file, bool $allowSvg = false): void
    {
        if (!$file->isValid()) {
            throw new ImageException($file->errorMessage());
        }
        if ($file->size > self::MAX_UPLOAD_BYTES) {
            throw new ImageException('Файл больше 12 МБ');
        }
        if ($allowSvg && $file->mimeType() === 'image/svg+xml') {
            return;
        }
    }
}
