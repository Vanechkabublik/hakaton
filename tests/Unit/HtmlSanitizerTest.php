<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testKeepsAllowedFormatting(): void
    {
        $html = '<h2 id="intro">Заголовок</h2><p>Текст с <strong>жирным</strong> и <em>курсивом</em>.</p><ul><li>раз</li><li>два</li></ul><blockquote>Цитата</blockquote>';
        $this->assertSame($html, $this->sanitizer->clean($html));
    }

    #[DataProvider('dangerous')]
    public function testStripsDangerousMarkup(string $input, string $mustNotContain): void
    {
        $clean = $this->sanitizer->clean($input);
        $this->assertStringNotContainsStringIgnoringCase($mustNotContain, $clean, "Осталось: {$clean}");
    }

    /** @return array<string,array{string,string}> */
    public static function dangerous(): array
    {
        return [
            'script' => ['<p>a</p><script>alert(1)</script>', 'script'],
            'содержимое script тоже удаляется' => ['<script>alert(1)</script><p>ok</p>', 'alert'],
            'обработчик события' => ['<p onclick="alert(1)">a</p>', 'onclick'],
            'img onerror' => ['<img src="https://e.test/a.png" onerror="alert(1)">', 'onerror'],
            'javascript: в ссылке' => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
            'javascript: с пробелами и регистром' => ['<a href="  JaVaScRiPt:alert(1)">x</a>', 'script:'],
            'javascript: с табуляцией внутри схемы' => ["<a href=\"java\tscript:alert(1)\">x</a>", 'script:'],
            'data: в ссылке' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', 'data:'],
            'iframe с чужого хоста' => ['<iframe src="https://evil.test/x"></iframe>', 'evil.test'],
            'iframe javascript:' => ['<iframe src="javascript:alert(1)"></iframe>', 'javascript'],
            'style атрибут' => ['<p style="background:url(javascript:alert(1))">a</p>', 'style'],
            'style тег' => ['<style>body{display:none}</style><p>a</p>', 'display'],
            'svg с onload' => ['<svg onload="alert(1)"><circle/></svg>', 'svg'],
            'form' => ['<form action="https://evil.test"><input name="x"></form>', 'evil.test'],
            'object/embed' => ['<object data="x.swf"></object><embed src="x.swf">', 'swf'],
            'комментарий-обход' => ['<!--><script>alert(1)</script>-->', 'alert'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', 'evil.test'],
            'base' => ['<base href="https://evil.test/"><p>a</p>', 'evil.test'],
        ];
    }

    public function testExternalLinksGetNoopener(): void
    {
        $clean = $this->sanitizer->clean('<a href="https://example.org/page" target="_self" class="x">ссылка</a>');
        $this->assertStringContainsString('href="https://example.org/page"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
        $this->assertStringNotContainsString('class=', $clean);
    }

    public function testInternalAndMailtoLinksAreKept(): void
    {
        $this->assertStringContainsString('href="/category/tech"', $this->sanitizer->clean('<a href="/category/tech">x</a>'));
        $this->assertStringContainsString('href="mailto:info@example.test"', $this->sanitizer->clean('<a href="mailto:info@example.test">x</a>'));
    }

    public function testImageGetsLazyLoadingAndAlt(): void
    {
        $clean = $this->sanitizer->clean('<img src="/uploads/2026/10/a.webp" width="800" height="abc">');
        $this->assertStringContainsString('loading="lazy"', $clean);
        $this->assertStringContainsString('alt=""', $clean);
        $this->assertStringContainsString('width="800"', $clean);
        $this->assertStringNotContainsString('height=', $clean);
    }

    public function testImageWithUnsafeSourceIsRemoved(): void
    {
        $this->assertSame('', $this->sanitizer->clean('<img src="javascript:alert(1)">'));
    }

    public function testAllowedIframeIsSandboxed(): void
    {
        $clean = $this->sanitizer->clean('<iframe src="https://www.youtube.com/embed/abc" width="560" height="315"></iframe>');
        $this->assertStringContainsString('www.youtube.com/embed/abc', $clean);
        $this->assertStringContainsString('sandbox=', $clean);
        $this->assertStringContainsString('loading="lazy"', $clean);
    }

    public function testHttpIframeIsRejected(): void
    {
        $this->assertSame('', $this->sanitizer->clean('<iframe src="http://www.youtube.com/embed/abc"></iframe>'));
    }

    public function testUnknownTagsAreUnwrappedKeepingText(): void
    {
        $clean = $this->sanitizer->clean('<p><span class="x">внутри</span> <font color="red">текст</font></p>');
        $this->assertSame('<p>внутри текст</p>', $clean);
    }

    public function testRemovesEmptyParagraphs(): void
    {
        $this->assertSame('<p>да</p>', $this->sanitizer->clean('<p>&nbsp;</p><p>да</p><p><br></p>'));
    }

    public function testEmptyAndPlainText(): void
    {
        $this->assertSame('', $this->sanitizer->clean('   '));
        $this->assertSame('просто текст', $this->sanitizer->clean('просто текст'));
    }

    public function testCleaningIsIdempotent(): void
    {
        $dirty = '<p onclick="x">a <a href="https://e.test" onmouseover="y">b</a></p><script>z</script>';
        $once = $this->sanitizer->clean($dirty);
        $this->assertSame($once, $this->sanitizer->clean($once));
    }

    public function testKeepsCyrillicIntact(): void
    {
        $this->assertSame('<p>Привет, «мир» — ёлка!</p>', $this->sanitizer->clean('<p>Привет, «мир» — ёлка!</p>'));
    }
}
