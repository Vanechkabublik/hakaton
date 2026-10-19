<?php

declare(strict_types=1);

namespace App\Support;

/** Построчное (поабзацное) сравнение двух HTML-текстов для истории правок (LCS). */
final class TextDiff
{
    /**
     * @return list<array{type:'eq'|'add'|'del',text:string}>
     */
    public static function blocks(string $oldHtml, string $newHtml): array
    {
        $a = self::split($oldHtml);
        $b = self::split($newHtml);
        $n = count($a);
        $m = count($b);
        if ($n * $m > 250000) { // защита от огромных текстов
            return [['type' => 'del', 'text' => '(текст слишком велик для сравнения)']];
        }
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $result = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $result[] = ['type' => 'eq', 'text' => $a[$i]];
                ++$i;
                ++$j;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $result[] = ['type' => 'del', 'text' => $a[$i++]];
            } else {
                $result[] = ['type' => 'add', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $result[] = ['type' => 'del', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $result[] = ['type' => 'add', 'text' => $b[$j++]];
        }

        return $result;
    }

    /** @return list<string> текстовые блоки без тегов */
    private static function split(string $html): array
    {
        $parts = preg_split('#</(?:p|h[1-6]|li|blockquote|figure|tr)>|<br\s*/?>#i', $html) ?: [];
        $blocks = [];
        foreach ($parts as $part) {
            $text = HtmlText::strip($part);
            if ($text !== '') {
                $blocks[] = $text;
            }
        }

        return $blocks;
    }
}
