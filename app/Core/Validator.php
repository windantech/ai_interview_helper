<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator.
 *
 * Rules: required, email, min:N, max:N, in:a,b,c, same:field, int, bool
 * Usage: $v = Validator::make($data, ['email' => 'required|email|max:190']);
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    /** @param array<string,mixed> $data @param array<string,string> $rules @param array<string,string> $labels */
    private function __construct(private array $data, private array $rules, private array $labels = [])
    {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $raw = $this->data[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $rules = explode('|', $ruleString);
            $label = $this->label($field);

            $isEmpty = $value === null || $value === '' || $value === [];
            if ($isEmpty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = "$label is required.";
                } else {
                    $this->clean[$field] = in_array('bool', $rules, true) ? false : null;
                }
                continue;
            }
            if (is_array($value)) {
                $this->errors[$field] = "$label is invalid.";
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? "Please enter a valid email address." : null,
                    'min'   => mb_strlen((string) $value) < (int) $param ? "$label must be at least $param characters." : null,
                    'max'   => mb_strlen((string) $value) > (int) $param ? "$label must not exceed $param characters." : null,
                    'in'    => !in_array((string) $value, explode(',', (string) $param), true) ? "$label has an invalid value." : null,
                    'same'  => (string) $value !== (string) ($this->data[$param] ?? '') ? "$label does not match." : null,
                    'int'   => filter_var($value, FILTER_VALIDATE_INT) === false ? "$label must be a whole number." : null,
                    default => null,
                };
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    continue 2;
                }
            }

            if (in_array('int', $rules, true)) {
                $value = (int) $value;
            } elseif (in_array('bool', $rules, true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array('email', $rules, true)) {
                $value = mb_strtolower((string) $value);
            }
            $this->clean[$field] = $value;
        }
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

    public function firstError(): string
    {
        return (string) (array_values($this->errors)[0] ?? '');
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->clean;
    }

    /** Password strength: >= 8 chars, letters and numbers. */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (mb_strlen($password) > 200) {
            return 'Password is too long.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        return null;
    }
}
