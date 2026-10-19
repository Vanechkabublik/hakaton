<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Config;
use App\Core\Router;
use App\Support\Format;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * Шаблонизатор на чистом PHP: наследование макетов (layout), секции, partials.
 * Внутри шаблона $this — экземпляр View, все данные доступны как переменные.
 * Весь пользовательский вывод обязан проходить через $this->e().
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    /** @var list<ViewFrame> */
    private array $frames = [];

    /** @var list<string> */
    private array $sectionStack = [];

    /** @var array<string,string> */
    private array $assetVersions = [];

    private ?\Closure $csrfProvider = null;
    private ?string $csrfToken = null;

    public function __construct(
        private readonly Config $config,
        private readonly Router $router,
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @return mixed */
    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $data += $this->shared;
        $frame = new ViewFrame();
        $this->frames[] = $frame;
        try {
            $content = $this->evaluate($template, $data);
            while ($frame->layout !== null) {
                $layout = $frame->layout;
                $layoutData = $frame->layoutData + $data;
                $frame->layout = null;
                $frame->sections['content'] = $content;
                $content = $this->evaluate($layout, $layoutData);
            }

            return $content;
        } finally {
            array_pop($this->frames);
        }
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function evaluate(string $template, array $data): string
    {
        $file = $this->config->basePath('templates/' . $template . '.php');
        if (!is_file($file)) {
            throw new RuntimeException("Шаблон не найден: {$template}");
        }
        $level = ob_get_level();
        ob_start();
        try {
            (function () use ($file, $data): void {
                extract($data, EXTR_SKIP);
                include $file;
            })();

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    // ---- наследование шаблонов ------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function layout(string $layout, array $data = []): void
    {
        $frame = end($this->frames);
        if ($frame instanceof ViewFrame) {
            $frame->layout = $layout;
            $frame->layoutData = $data;
        }
    }

    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    public function end(): void
    {
        $section = array_pop($this->sectionStack);
        $content = (string) ob_get_clean();
        $frame = end($this->frames);
        if ($frame instanceof ViewFrame && $section !== null) {
            $frame->sections[$section] = $content;
        }
    }

    /** Вывести секцию (в макете). Содержимое секций — доверенный HTML. */
    public function section(string $name, string $default = ''): string
    {
        $frame = end($this->frames);

        return $frame instanceof ViewFrame ? ($frame->sections[$name] ?? $default) : $default;
    }

    public function hasSection(string $name): bool
    {
        $frame = end($this->frames);

        return $frame instanceof ViewFrame && isset($frame->sections[$name]) && trim($frame->sections[$name]) !== '';
    }

    // ---- помощники для шаблонов -----------------------------------------------------------

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** JSON для вставки внутрь HTML-атрибута или <script type="application/json">. */
    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** @param array<string,scalar> $params @param array<string,mixed> $query */
    public function route(string $name, array $params = [], array $query = []): string
    {
        return $this->router->url($name, $params, $query);
    }

    public function url(string $path = ''): string
    {
        return $this->config->appUrl() . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (!isset($this->assetVersions[$path])) {
            $file = $this->config->basePath('public/assets/' . $path);
            $this->assetVersions[$path] = is_file($file) ? (string) filemtime($file) : '0';
        }

        return '/assets/' . $path . '?v=' . $this->assetVersions[$path];
    }

    /** CSRF-токен запрашивается лениво, чтобы анонимные просмотры не создавали сессий. */
    public function setCsrfProvider(\Closure $provider): void
    {
        $this->csrfProvider = $provider;
        $this->csrfToken = null;
    }

    public function csrfToken(): string
    {
        if ($this->csrfToken === null) {
            $this->csrfToken = $this->csrfProvider !== null ? (string) ($this->csrfProvider)() : '';
        }

        return $this->csrfToken;
    }

    public function csrfResolved(): bool
    {
        return $this->csrfToken !== null;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . $this->e($this->csrfToken()) . '">';
    }

    public function method(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . $this->e(strtoupper($method)) . '">';
    }

    public function old(string $key, mixed $default = ''): string
    {
        $old = $this->shared('old', []);

        return $this->e(is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default);
    }

    public function error(string $key): string
    {
        $errors = $this->shared('errors', []);
        if (!is_array($errors) || !isset($errors[$key])) {
            return '';
        }

        return '<p class="field-error" role="alert">' . $this->e($errors[$key]) . '</p>';
    }

    public function hasError(string $key): bool
    {
        $errors = $this->shared('errors', []);

        return is_array($errors) && isset($errors[$key]);
    }

    public function selected(mixed $a, mixed $b): string
    {
        return (string) $a === (string) $b ? ' selected' : '';
    }

    public function checked(mixed $condition): string
    {
        return $condition ? ' checked' : '';
    }

    public function date(?DateTimeInterface $date, bool $withYear = true): string
    {
        return Format::date($date, $withYear);
    }

    public function dateTime(?DateTimeInterface $date): string
    {
        return Format::dateTime($date);
    }

    public function short(?DateTimeInterface $date): string
    {
        return Format::short($date);
    }

    public function ago(?DateTimeInterface $date): string
    {
        return Format::ago($date);
    }

    /** Значение для <time datetime="..."> в ISO 8601. */
    public function iso(?DateTimeInterface $date): string
    {
        return $date === null ? '' : $date->format(DATE_ATOM);
    }

    public function money(int $kopecks, bool $decimals = false): string
    {
        return Format::money($kopecks, $decimals);
    }

    public function number(int|float $n, int $decimals = 0): string
    {
        return Format::number($n, $decimals);
    }

    /** @param array{0:string,1:string,2:string} $forms */
    public function plural(int $n, array $forms): string
    {
        return Format::plural($n, $forms);
    }

    public function truncate(string $text, int $length, string $suffix = '…'): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:–-") . $suffix;
    }

    /** @param array<string,mixed> $attrs */
    public function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $out .= ' ' . $this->e($name) . ($value === true ? '' : '="' . $this->e($value) . '"');
        }

        return $out;
    }
}
