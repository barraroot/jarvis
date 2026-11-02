<?php

namespace App\Results;

use App\Exceptions\ResultValidationException;

/**
 * Validates a structured result payload against the JSON Schema contract of
 * its type (`resources/schemas/results/*.json`).
 *
 * The validator implements the JSON Schema keyword subset the project's own
 * schemas use — `type`, `enum`, `required`, `properties`,
 * `additionalProperties`, `items`, length/item bounds and numeric bounds — so
 * the schema files stay the single source of truth without pulling in a
 * full JSON Schema library.
 */
class ResultValidator
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ResultValidationException
     */
    public function validate(string $type, array $payload): void
    {
        $path = resource_path("schemas/results/{$type}.json");

        if (! is_file($path)) {
            throw new ResultValidationException("Result type [{$type}] has no schema.");
        }

        $schema = json_decode((string) file_get_contents($path), true);

        if (! is_array($schema)) {
            throw new ResultValidationException("Schema for result type [{$type}] is not valid JSON.");
        }

        $this->validateAgainst($payload, $schema, '$');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateAgainst(mixed $value, array $schema, string $path): void
    {
        $expected = $schema['type'] ?? null;

        if (! $this->matchesType($value, $expected)) {
            throw new ResultValidationException(
                sprintf('Value at %s must be %s.', $path, $this->describeType($expected))
            );
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new ResultValidationException(
                sprintf('Value at %s must be one of: %s.', $path, implode(', ', array_map('json_encode', $schema['enum'])))
            );
        }

        if (is_string($value)) {
            $this->validateString($value, $schema, $path);
        } elseif (is_int($value) || is_float($value)) {
            $this->validateNumber($value, $schema, $path);
        } elseif (is_array($value)) {
            if (array_is_list($value)) {
                $this->validateArrayItems($value, $schema, $path);
            } else {
                $this->validateObject($value, $schema, $path);
            }
        }
    }

    /**
     * @param  list<mixed>  $value
     * @param  array<string, mixed>  $schema
     */
    private function validateArrayItems(array $value, array $schema, string $path): void
    {
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            throw new ResultValidationException("Value at {$path} must have at least {$schema['minItems']} item(s).");
        }

        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            throw new ResultValidationException("Value at {$path} must have at most {$schema['maxItems']} item(s).");
        }

        if (! isset($schema['items']) || ! is_array($schema['items'])) {
            return;
        }

        foreach ($value as $index => $item) {
            $this->validateAgainst($item, $schema['items'], "{$path}[{$index}]");
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     */
    private function validateObject(array $value, array $schema, string $path): void
    {
        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $value)) {
                throw new ResultValidationException("Missing required property {$path}.{$required}.");
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $key) {
                if (! isset($schema['properties'][$key])) {
                    throw new ResultValidationException("Unknown property {$path}.{$key}.");
                }
            }
        }

        foreach ($schema['properties'] ?? [] as $key => $propertySchema) {
            if (array_key_exists($key, $value) && is_array($propertySchema)) {
                $this->validateAgainst($value[$key], $propertySchema, "{$path}.{$key}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateString(string $value, array $schema, string $path): void
    {
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            throw new ResultValidationException("Value at {$path} must be at least {$schema['minLength']} character(s).");
        }

        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            throw new ResultValidationException("Value at {$path} must be at most {$schema['maxLength']} character(s).");
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateNumber(int|float $value, array $schema, string $path): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new ResultValidationException("Value at {$path} must be at least {$schema['minimum']}.");
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            throw new ResultValidationException("Value at {$path} must be at most {$schema['maximum']}.");
        }
    }

    /**
     * @param  string|list<string>|null  $expected
     */
    private function matchesType(mixed $value, string|array|null $expected): bool
    {
        if ($expected === null) {
            return true;
        }

        foreach ((array) $expected as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string|list<string>|null  $expected
     */
    private function describeType(string|array|null $expected): string
    {
        if ($expected === null) {
            return 'any type';
        }

        return implode('|', (array) $expected);
    }
}
