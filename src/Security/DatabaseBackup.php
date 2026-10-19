<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Logger;

/** Дамп базы данных через pg_dump (gzip). Используется админкой («Экспорт БД») и ночным бэкапом. */
final class DatabaseBackup
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    private function binary(): ?string
    {
        foreach (['/usr/bin/pg_dump', '/usr/local/bin/pg_dump', '/usr/lib/postgresql/16/bin/pg_dump'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = trim((string) @shell_exec('command -v pg_dump 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    /** @return resource|null процесс pg_dump; stdout читается вызывающим */
    private function open(&$pipes)
    {
        $binary = $this->binary();
        if ($binary === null) {
            return null;
        }
        $command = [
            $binary, '--no-owner', '--no-privileges', '--format=plain',
            '-h', $this->config->string('DB_HOST', 'db'),
            '-p', (string) $this->config->int('DB_PORT', 5432),
            '-U', $this->config->string('DB_USER', 'news'),
            $this->config->string('DB_NAME', 'news'),
        ];
        $env = ['PGPASSWORD' => $this->config->string('DB_PASSWORD'), 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

        return is_resource($process) ? $process : null;
    }

    /** Потоково отдать gzip-дамп в php://output. */
    public function stream(): bool
    {
        $process = $this->open($pipes);
        if ($process === null) {
            return false;
        }
        $out = fopen('php://output', 'wb');
        $gz = deflate_init(ZLIB_ENCODING_GZIP);
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            fwrite($out, (string) deflate_add($gz, $chunk, ZLIB_NO_FLUSH));
        }
        fwrite($out, (string) deflate_add($gz, '', ZLIB_FINISH));
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $this->logger->error('pg_dump завершился с ошибкой', ['code' => $code, 'stderr' => substr((string) $error, 0, 500)]);
        }

        return $code === 0;
    }

    /** Сохранить дамп в storage/backups, оставив $keep последних. Возвращает путь к файлу. */
    public function createFile(int $keep = 7): ?string
    {
        $dir = $this->config->storagePath('backups');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        $file = $dir . '/db-' . gmdate('Ymd-His') . '.sql.gz';
        $process = $this->open($pipes);
        if ($process === null) {
            return null;
        }
        $target = gzopen($file, 'wb6');
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                gzwrite($target, $chunk);
            }
        }
        gzclose($target);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            @unlink($file);
            $this->logger->error('Бэкап БД не создан', ['stderr' => substr((string) $error, 0, 500)]);

            return null;
        }
        @chmod($file, 0640);
        $files = glob($dir . '/db-*.sql.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
        $this->logger->info('Бэкап БД создан', ['file' => basename($file), 'size' => filesize($file)]);

        return $file;
    }

    /** @return list<array{name:string,size:int,time:int}> */
    public function listFiles(): array
    {
        $files = glob($this->config->storagePath('backups') . '/db-*.sql.gz') ?: [];
        rsort($files);

        return array_map(static fn (string $f): array => ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)], $files);
    }
}
