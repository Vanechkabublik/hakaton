<?php

declare(strict_types=1);

namespace App\Core\Validation;

/**
 * Небольшой валидатор форм. Правила: required, string, email, int, min:N / max:N (длина строки), in:a,b, same:field,
 * regex:/.../, url, gte:N, lte:N, nullable, boolean. Сообщения на русском.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $valid = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules поле => 'required|string|max:100'
     * @param array<string,string> $labels поле => название для сообщений
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
    ) {
        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->valid;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($required) {
                    $this->errors[$field] = $this->label($field) . ': обязательное поле';
                } else {
                    $this->valid[$field] = $nullable ? null : ($value ?? '');
                }
                continue;
            }

            $failed = false;
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->check($name, $arg, $value, $field);
                if ($message !== null) {
                    $this->errors[$field] = $message;
                    $failed = true;
                    break;
                }
            }
            if (!$failed) {
                $this->valid[$field] = $value;
            }
        }
    }

    private function check(string $rule, ?string $arg, mixed $value, string $field): ?string
    {
        $label = $this->label($field);
        switch ($rule) {
            case 'string':
                return is_string($value) ? null : "{$label}: некорректное значение";
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) && mb_strlen($value) <= 190
                    ? null : "{$label}: введите корректный адрес";
            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "{$label}: должно быть целым числом";
            case 'boolean':
                return null;
            case 'url':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value)
                    ? null : "{$label}: введите корректную ссылку";
            case 'min':
                return is_string($value) && mb_strlen($value) >= (int) $arg ? null : "{$label}: минимум {$arg} симв.";
            case 'max':
                return is_string($value) && mb_strlen($value) <= (int) $arg ? null : "{$label}: максимум {$arg} симв.";
            case 'gte':
                return is_numeric($value) && $value >= (float) $arg ? null : "{$label}: не меньше {$arg}";
            case 'lte':
                return is_numeric($value) && $value <= (float) $arg ? null : "{$label}: не больше {$arg}";
            case 'in':
                return in_array((string) $value, explode(',', (string) $arg), true) ? null : "{$label}: недопустимое значение";
            case 'same':
                return ($this->data[(string) $arg] ?? null) === $value ? null : "{$label}: значения не совпадают";
            case 'regex':
                return is_string($value) && preg_match((string) $arg, $value) === 1 ? null : "{$label}: неверный формат";
            default:
                return null;
        }
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? $field;
    }
}
