<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TextDiff;
use PHPUnit\Framework\TestCase;

final class TextDiffTest extends TestCase
{
    public function testIdenticalTextsAreAllEqual(): void
    {
        $diff = TextDiff::blocks('<p>Один</p><p>Два</p>', '<p>Один</p><p>Два</p>');
        $this->assertSame(['eq', 'eq'], array_column($diff, 'type'));
    }

    public function testDetectsAddedAndRemovedBlocks(): void
    {
        $diff = TextDiff::blocks('<p>Один</p><p>Два</p><p>Три</p>', '<p>Один</p><p>Три</p><p>Четыре</p>');
        $byType = [];
        foreach ($diff as $block) {
            $byType[$block['type']][] = $block['text'];
        }
        $this->assertSame(['Два'], $byType['del']);
        $this->assertSame(['Четыре'], $byType['add']);
        $this->assertSame(['Один', 'Три'], $byType['eq']);
    }

    public function testChangedParagraphIsDeletedAndAdded(): void
    {
        $diff = TextDiff::blocks('<p>Старый текст</p>', '<p>Новый текст</p>');
        $this->assertEqualsCanonicalizing(['del', 'add'], array_column($diff, 'type'));
    }

    public function testEmptyInputs(): void
    {
        $this->assertSame([], TextDiff::blocks('', ''));
        $this->assertSame(['add'], array_column(TextDiff::blocks('', '<p>Новое</p>'), 'type'));
        $this->assertSame(['del'], array_column(TextDiff::blocks('<p>Старое</p>', ''), 'type'));
    }

    public function testIgnoresMarkupDifferences(): void
    {
        $diff = TextDiff::blocks('<p>Текст</p>', '<p><strong>Текст</strong></p>');
        $this->assertSame(['eq'], array_column($diff, 'type'));
    }
}
