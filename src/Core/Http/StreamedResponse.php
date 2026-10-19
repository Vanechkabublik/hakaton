<?php

declare(strict_types=1);

namespace App\Core\Http;

use Closure;

/** Ответ, тело которого генерируется потоково (CSV-экспорт, дамп БД). */
final class StreamedResponse extends Response
{
    public function __construct(private readonly Closure $callback, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);
    }

    public static function csv(string $filename, callable $writer): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.csv';

        return new self(function () use ($writer): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, чтобы Excel корректно открыл UTF-8
            $writer(static function (array $row) use ($out): void {
                fputcsv($out, array_map(self::csvSafe(...), $row), ';', '"', '\\');
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Защита от CSV-инъекций: ячейки, начинающиеся с = + - @, экранируются. */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    protected function sendBody(): void
    {
        ($this->callback)();
    }
}
