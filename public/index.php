<?php

declare(strict_types=1);

use App\Core\Application;

require __DIR__ . '/../vendor/autoload.php';

// Встроенный сервер PHP (php -S): статические файлы отдаёт сам
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
    if (is_string($path) && str_starts_with($path, '/uploads/')) {
        $file = realpath(dirname(__DIR__) . '/storage' . $path);
        if ($file !== false && str_starts_with($file, dirname(__DIR__) . '/storage/uploads/') && is_file($file)) {
            header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
            readfile($file);

            return true;
        }
    }
}

(new Application(dirname(__DIR__)))->run();
