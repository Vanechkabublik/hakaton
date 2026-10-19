<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndTrim(): void
    {
        $v = Validator::make(['name' => '   ', 'email' => ' a@b.co '], ['name' => 'required|string', 'email' => 'required|email'], ['name' => 'Имя']);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('name', $v->errors());
        $this->assertStringContainsString('Имя', $v->errors()['name']);
        $this->assertSame('a@b.co', $v->validated()['email']);
    }

    public function testLengthRulesCountCharactersNotBytes(): void
    {
        $this->assertFalse(Validator::make(['t' => 'Привет'], ['t' => 'string|max:6'])->fails());
        $this->assertTrue(Validator::make(['t' => 'Привет!'], ['t' => 'string|max:6'])->fails());
        $this->assertTrue(Validator::make(['t' => 'ab'], ['t' => 'string|min:3'])->fails());
    }

    public function testOptionalEmptyFieldSkipsOtherRules(): void
    {
        $v = Validator::make(['url' => ''], ['url' => 'nullable|url']);
        $this->assertFalse($v->fails());
        $this->assertNull($v->validated()['url']);

        $v = Validator::make([], ['note' => 'string|max:5']);
        $this->assertFalse($v->fails());
        $this->assertSame('', $v->validated()['note']);
    }

    public function testNumericAndEnumRules(): void
    {
        $this->assertFalse(Validator::make(['n' => '5'], ['n' => 'int|gte:1|lte:10'])->fails());
        $this->assertTrue(Validator::make(['n' => '11'], ['n' => 'int|lte:10'])->fails());
        $this->assertTrue(Validator::make(['n' => '1.5'], ['n' => 'int'])->fails());
        $this->assertFalse(Validator::make(['r' => 'admin'], ['r' => 'in:reader,admin'])->fails());
        $this->assertTrue(Validator::make(['r' => 'root'], ['r' => 'in:reader,admin'])->fails());
    }

    public function testSameRule(): void
    {
        $this->assertFalse(Validator::make(['p' => 'x1', 'p2' => 'x1'], ['p2' => 'required|same:p'])->fails());
        $this->assertTrue(Validator::make(['p' => 'x1', 'p2' => 'x2'], ['p2' => 'required|same:p'])->fails());
    }

    public function testUrlAcceptsOnlyHttp(): void
    {
        $this->assertFalse(Validator::make(['u' => 'https://example.org/a?b=1'], ['u' => 'url'])->fails());
        $this->assertTrue(Validator::make(['u' => 'javascript:alert(1)'], ['u' => 'url'])->fails());
        $this->assertTrue(Validator::make(['u' => 'ftp://example.org'], ['u' => 'url'])->fails());
    }

    public function testRegexRule(): void
    {
        $this->assertFalse(Validator::make(['c' => 'AB-12'], ['c' => 'regex:/^[A-Z]{2}-\d{2}$/'])->fails());
        $this->assertTrue(Validator::make(['c' => 'ab-12'], ['c' => 'regex:/^[A-Z]{2}-\d{2}$/'])->fails());
    }

    public function testOnlyFirstErrorPerFieldAndInvalidFieldsNotValidated(): void
    {
        $v = Validator::make(['e' => 'nope'], ['e' => 'required|email|max:3']);
        $this->assertCount(1, $v->errors());
        $this->assertArrayNotHasKey('e', $v->validated());
    }
}
