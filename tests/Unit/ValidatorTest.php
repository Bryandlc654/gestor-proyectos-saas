<?php

namespace Tests\Unit;

use App\Support\ValidationException;
use App\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyDeclaredFieldsNormalized(): void
    {
        $data = Validator::validate(
            ['title' => '  Hola  ', 'amount' => '12.345', 'count' => '7', 'extra' => 'x', 'email' => 'A@B.COM'],
            ['title' => 'required|string|max:10', 'amount' => 'numeric', 'count' => 'int', 'email' => 'email', 'note' => 'nullable|string']
        );

        $this->assertSame(['title' => 'Hola', 'amount' => 12.35, 'count' => 7, 'email' => 'a@b.com', 'note' => null], $data);
    }

    public function testRequiredFieldMessageUsesLabel(): void
    {
        try {
            Validator::validate(['title' => '   '], ['title' => 'required|string'], ['title' => 'el título']);
            $this->fail('Debía fallar');
        } catch (ValidationException $e) {
            $this->assertSame('El título es obligatorio.', $e->getMessage());
            $this->assertArrayHasKey('title', $e->errors);
        }
    }

    public function testValidationExceptionIsADomainExceptionFor422(): void
    {
        $this->assertInstanceOf(\DomainException::class, new ValidationException(['x' => 'mal']));
    }

    /** @dataProvider invalidInputs */
    public function testRejectsInvalidValues(array $input, string $rule): void
    {
        $this->expectException(ValidationException::class);
        Validator::validate($input, ['v' => $rule]);
    }

    public static function invalidInputs(): array
    {
        return [
            'fecha imposible' => [['v' => '2026-02-31'], 'date'],
            'fecha formato' => [['v' => '31/12/2026'], 'date'],
            'email' => [['v' => 'no-es-email'], 'email'],
            'url javascript' => [['v' => 'javascript:alert(1)'], 'url'],
            'color' => [['v' => 'red'], 'color'],
            'fuera de lista' => [['v' => 'x'], 'in:a,b'],
            'texto largo' => [['v' => str_repeat('a', 11)], 'string|max:10'],
            'importe cero' => [['v' => '0'], 'numeric|gt:0'],
            'importe negativo' => [['v' => '-5'], 'numeric|gt:0'],
            'entero' => [['v' => '1.5'], 'int'],
            'rango' => [['v' => '101'], 'numeric|between:0,100'],
            'array esperado' => [['v' => 'x'], 'array'],
        ];
    }

    public function testDefaultsApplyToEmptyAndUnknownListValues(): void
    {
        $rules = ['priority' => 'in:baja,media,alta|default:media'];
        $this->assertSame(['priority' => 'media'], Validator::validate([], $rules));
        $this->assertSame(['priority' => 'media'], Validator::validate(['priority' => 'urgentisima'], $rules));
        $this->assertSame(['priority' => 'alta'], Validator::validate(['priority' => 'alta'], $rules));
    }

    public function testCollectsAllErrorsAndReportsTheFirst(): void
    {
        try {
            Validator::validate(['a' => '', 'b' => 'x'], ['a' => 'required', 'b' => 'int']);
            $this->fail('Debía fallar');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->errors);
            $this->assertSame($e->errors['a'], $e->getMessage());
        }
    }
}
