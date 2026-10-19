<?php

declare(strict_types=1);

namespace App\Media;

use GdImage;

/**
 * Ресайз и конвертация изображений в WebP средствами GD.
 * Исходный файл нигде не сохраняется: на диск попадает только перекодированный результат,
 * поэтому подмена содержимого (polyglot-файлы, метаданные EXIF) не доходит до пользователей.
 */
final class ImageProcessor
{
    public const MAX_PIXELS = 40_000_000;
    public const QUALITY = 82;

    /** @var array<string,string> */
    private const ALLOWED = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif' => 'imagecreatefromgif',
    ];

    /**
     * Прочитать изображение, проверить и применить EXIF-ориентацию.
     *
     * @throws ImageException
     */
    public function load(string $path): GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new ImageException('Файл не является изображением');
        }
        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? '');
        $loader = self::ALLOWED[$mime] ?? null;
        if ($loader === null) {
            throw new ImageException('Допустимы JPEG, PNG, WebP и GIF');
        }
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new ImageException('Изображение слишком большое (максимум 40 мегапикселей)');
        }
        $image = @$loader($path);
        if (!$image instanceof GdImage) {
            throw new ImageException('Не удалось прочитать изображение');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $image = $this->applyOrientation($image, $path);
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /** Масштабирование по ширине с сохранением пропорций (без увеличения). */
    public function resizeToWidth(GdImage $source, int $width): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        if ($width >= $sw) {
            $width = $sw;
        }
        $height = max(1, (int) round($sh * $width / $sw));
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sw, $sh);

        return $target;
    }

    /** Квадратная обрезка по центру (аватары). */
    public function cropSquare(GdImage $source, int $size): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $side = min($sw, $sh);
        $target = imagecreatetruecolor($size, $size);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, intdiv($sw - $side, 2), intdiv($sh - $side, 2), $size, $size, $side, $side);

        return $target;
    }

    /** @throws ImageException */
    public function saveWebp(GdImage $image, string $path): int
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ImageException('Не удалось создать каталог для загрузок');
        }
        if (!imagewebp($image, $path, self::QUALITY)) {
            throw new ImageException('Не удалось сохранить изображение');
        }
        @chmod($path, 0644);

        return (int) filesize($path);
    }

    private function applyOrientation(GdImage $image, string $path): GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
