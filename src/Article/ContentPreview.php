<?php

declare(strict_types=1);

namespace App\Article;

use DOMDocument;
use DOMElement;

/** Превью закрытой статьи: первые абзацы (вместе с заголовками и картинками между ними). */
final class ContentPreview
{
    public static function paragraphs(string $html, int $count): string
    {
        $html = trim($html);
        if ($html === '' || $count <= 0) {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><body><div id="__root">' . $html . '</div></body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__root');
        if ($root === null) {
            return '';
        }
        $out = '';
        $paragraphs = 0;
        foreach ($root->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $isParagraph = $node->tagName === 'p' && trim($node->textContent) !== '';
            if ($isParagraph) {
                ++$paragraphs;
            }
            if ($paragraphs > $count) {
                break;
            }
            // Не показываем «хвост» после последнего абзаца: заголовки и цитаты без абзаца в конце бессмысленны
            $out .= $dom->saveHTML($node);
        }

        return $out;
    }
}
