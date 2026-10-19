<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testTransliteratesRussian(): void
    {
        $this->assertSame('privet-mir', Slug::make('Привет, мир!'));
        $this->assertSame('shchuka-i-ezh', Slug::make('Щука и Ёж'));
        $this->assertSame('obem-rynka-2026', Slug::make('Объём рынка — 2026'));
    }

    public function testCollapsesSeparatorsAndTrims(): void
    {
        $this->assertSame('a-b-c', Slug::make('  --a___b   c!!  '));
        $this->assertSame('', Slug::make('!!!'));
    }

    public function testCutsLongTextOnWordBoundary(): void
    {
        $slug = Slug::make(str_repeat('слово ', 30), 40);
        $this->assertLessThanOrEqual(40, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
        $this->assertStringEndsWith('slovo', $slug);
    }

    public function testUniqueAppendsSuffixOnCollision(): void
    {
        $taken = ['novosti', 'novosti-2'];
        $slug = Slug::unique('Новости', static fn (string $s): bool => in_array($s, $taken, true));
        $this->assertSame('novosti-3', $slug);
    }

    public function testUniqueUsesFallbackForEmptySlug(): void
    {
        $this->assertSame('item', Slug::unique('???', static fn (): bool => false));
        $this->assertSame('statya', Slug::unique('???', static fn (): bool => false, 'statya'));
    }
}
