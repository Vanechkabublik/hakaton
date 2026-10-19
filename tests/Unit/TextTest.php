<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Article\ContentPreview;
use App\Article\ReadingTime;
use App\Support\Format;
use App\Support\HtmlText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    #[DataProvider('plurals')]
    public function testRussianPlural(int $n, string $expected): void
    {
        $this->assertSame($expected, Format::plural($n, ['статья', 'статьи', 'статей']));
    }

    /** @return array<string,array{int,string}> */
    public static function plurals(): array
    {
        return [
            '0' => [0, 'статей'], '1' => [1, 'статья'], '2' => [2, 'статьи'], '4' => [4, 'статьи'], '5' => [5, 'статей'],
            '11' => [11, 'статей'], '12' => [12, 'статей'], '14' => [14, 'статей'], '21' => [21, 'статья'],
            '22' => [22, 'статьи'], '25' => [25, 'статей'], '101' => [101, 'статья'], '111' => [111, 'статей'],
            '112' => [112, 'статей'], '1002' => [1002, 'статьи'], 'отрицательное' => [-3, 'статьи'],
        ];
    }

    public function testReadingTimeIsAtLeastOneMinute(): void
    {
        $this->assertSame(1, ReadingTime::minutes('<p>Коротко.</p>'));
        $this->assertSame(1, ReadingTime::minutes(''));
    }

    public function testReadingTimeScalesWithWordsAndImages(): void
    {
        $words = '<p>' . implode(' ', array_fill(0, 950, 'слово')) . '</p>';
        $this->assertSame(5, ReadingTime::minutes($words));
        $this->assertGreaterThan(ReadingTime::minutes($words), ReadingTime::minutes($words . str_repeat('<img src="/a.png">', 10)));
    }

    public function testPreviewKeepsRequestedNumberOfParagraphs(): void
    {
        $html = '<p>Один</p><p>Два</p><p>Три</p><p>Четыре</p>';
        $this->assertSame('<p>Один</p><p>Два</p>', ContentPreview::paragraphs($html, 2));
    }

    public function testPreviewKeepsInterleavedHeadingsButNotTrailingContent(): void
    {
        $html = '<p>Один</p><h2>Раздел</h2><p>Два</p><h2>Хвост</h2><p>Три</p>';
        $preview = ContentPreview::paragraphs($html, 2);
        $this->assertStringContainsString('Раздел', $preview);
        $this->assertStringContainsString('Два', $preview);
        $this->assertStringNotContainsString('Три', $preview);
    }

    public function testPreviewOfZeroOrEmpty(): void
    {
        $this->assertSame('', ContentPreview::paragraphs('<p>Текст</p>', 0));
        $this->assertSame('', ContentPreview::paragraphs('', 3));
    }

    public function testPreviewNeverLeaksPremiumTextWhenFewParagraphs(): void
    {
        $this->assertSame('<p>Единственный</p>', ContentPreview::paragraphs('<p>Единственный</p>', 5));
    }

    public function testTruncationNeverBreaksCyrillicLetters(): void
    {
        // «р» в UTF-8 — байты D1 80; rtrim() с многобайтовой маской отрезал бы 0x80 и ломал строку
        $text = str_repeat('Ставки растут вместе с ценой на нефть, ', 10) . 'главный вывод — не торопиться с масштабированием, но смотреть на опыт коллег';
        for ($length = 40; $length < 160; ++$length) {
            $article = new \App\Article\Article();
            $article->lead = $text;
            $excerpt = $article->excerpt($length);
            $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'), "Битый UTF-8 при длине {$length}: {$excerpt}");
            $this->assertStringEndsWith('…', $excerpt);
        }
    }

    public function testTrimTrailingPunctuation(): void
    {
        $this->assertSame('Привет', HtmlText::trimTrailingPunctuation('Привет, – '));
        $this->assertSame('Мир', HtmlText::trimTrailingPunctuation('Мир—'));
        $this->assertSame('кубер', HtmlText::trimTrailingPunctuation('кубер'), 'Буква «р» остаётся целой');
        $this->assertSame('', HtmlText::trimTrailingPunctuation(' ,.; '));
    }
}
