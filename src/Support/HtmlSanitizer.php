<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Очистка HTML редакции по белому списку тегов и атрибутов (защита от XSS).
 * Всё, чего нет в списках, удаляется; опасные теги удаляются вместе с содержимым.
 */
final class HtmlSanitizer
{
    /** @var array<string,list<string>> тег => разрешённые атрибуты */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'sub' => [], 'sup' => [], 'mark' => [], 'small' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'hr' => [], 'code' => [], 'pre' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height', 'srcset', 'sizes'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen'],
    ];

    /** Теги, которые удаляются вместе с содержимым. */
    private const DROP = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'option',
        'svg', 'math', 'link', 'meta', 'noscript', 'template', 'applet', 'frame', 'frameset', 'base', 'title', 'head'];

    private const HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const IFRAME_HOSTS = [
        'www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'vk.com', 'rutube.ru',
    ];

    public function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><body><div id="__root">' . $html . '</div></body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__root');
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $this->sanitizeChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        // Пустые абзацы, оставшиеся после чистки
        $out = preg_replace('#<p>(\s|&nbsp;|\x{00A0}|<br\s*/?>)*</p>#iu', '', $out) ?? $out;

        return trim($out);
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        // Копия списка: дерево меняется по ходу обхода
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $this->sanitizeElement($node);
            } elseif ($node->nodeType !== XML_TEXT_NODE) {
                $parent->removeChild($node); // комментарии, CDATA, PI
            }
        }
    }

    private function sanitizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        if (in_array($tag, self::DROP, true)) {
            $parent->removeChild($element);

            return;
        }
        if (!array_key_exists($tag, self::ALLOWED)) {
            // Неизвестный тег: оставляем содержимое, убираем обёртку
            $this->sanitizeChildren($element);
            while ($element->firstChild !== null) {
                $parent->insertBefore($element->firstChild, $element);
            }
            $parent->removeChild($element);

            return;
        }

        $this->filterAttributes($element, $tag);
        if (!$this->validateElement($element, $tag)) {
            $parent->removeChild($element);

            return;
        }
        $this->sanitizeChildren($element);
    }

    private function filterAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    private function validateElement(DOMElement $element, string $tag): bool
    {
        switch ($tag) {
            case 'a':
                $href = $element->getAttribute('href');
                if ($href === '' || !$this->isSafeUrl($href, self::HREF_SCHEMES, true)) {
                    $element->removeAttribute('href');

                    return true;
                }
                $external = preg_match('#^https?://#i', $href) === 1;
                if ($external) {
                    $element->setAttribute('rel', 'noopener noreferrer');
                    $element->setAttribute('target', '_blank');
                }

                return true;
            case 'img':
                $src = $element->getAttribute('src');
                if ($src === '' || !$this->isSafeUrl($src, ['http', 'https'], false)) {
                    return false;
                }
                $element->setAttribute('loading', 'lazy');
                $element->setAttribute('decoding', 'async');
                if (!$element->hasAttribute('alt')) {
                    $element->setAttribute('alt', '');
                }
                foreach (['width', 'height'] as $dimension) {
                    if ($element->hasAttribute($dimension) && !ctype_digit($element->getAttribute($dimension))) {
                        $element->removeAttribute($dimension);
                    }
                }
                if ($element->hasAttribute('srcset') && !preg_match('#^[\w/%.:,\s\-]+$#', $element->getAttribute('srcset'))) {
                    $element->removeAttribute('srcset');
                }

                return true;
            case 'iframe':
                $src = $element->getAttribute('src');
                $host = strtolower((string) parse_url($src, PHP_URL_HOST));
                if (!preg_match('#^https://#i', $src) || !in_array($host, self::IFRAME_HOSTS, true)) {
                    return false;
                }
                $element->setAttribute('loading', 'lazy');
                $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                $element->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
                $element->setAttribute('allowfullscreen', 'allowfullscreen');

                return true;
            case 'h2':
            case 'h3':
            case 'h4':
                if ($element->hasAttribute('id') && !preg_match('/^[a-z0-9_-]{1,80}$/i', $element->getAttribute('id'))) {
                    $element->removeAttribute('id');
                }

                return true;
            case 'th':
            case 'td':
                foreach (['colspan', 'rowspan'] as $span) {
                    if ($element->hasAttribute($span) && !preg_match('/^\d{1,2}$/', $element->getAttribute($span))) {
                        $element->removeAttribute($span);
                    }
                }

                return true;
            default:
                return true;
        }
    }

    /**
     * @param list<string> $schemes
     */
    private function isSafeUrl(string $url, array $schemes, bool $allowAnchor): bool
    {
        // Нормализация против обходов вида "java\tscript:" и HTML-сущностей
        $normalized = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/[\x00-\x20\x7F]+/u', '', $normalized) ?? '';
        if ($normalized === '') {
            return false;
        }
        if ($normalized[0] === '#') {
            return $allowAnchor;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $m)) {
            return in_array(strtolower($m[1]), $schemes, true);
        }
        if (str_starts_with($normalized, '//')) {
            return false;
        }

        return true; // относительная ссылка
    }
}
