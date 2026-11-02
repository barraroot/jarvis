<?php

namespace App\Support;

/**
 * Validates payloads against the JSON Schema subset used by the tool
 * catalogue (US-8.2): object/string/integer/number/boolean/array/null with
 * `properties`, `required`, `items`, `enum`, `const`, length/range bounds and
 * `pattern`. Unknown keywords are ignored (forward compatible).
 */
class JsonSchemaValidator
{
    /**
     * Validate `$data` against `$schema`; returns every violation found.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function validate(array $schema, mixed $data): array
    {
        $errors = [];

        $this->checkValue($schema, $data, '', $errors);

        return $errors;
    }

    /**
     * Whether `$schema` parses as a supported JSON Schema (used by the admin
     * form before persisting; US-8.1).
     *
     * @param  array<string, mixed>  $schema
     */
    public function isValidSchema(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        if (! is_string($type) && ! is_array($type)) {
            return false;
        }

        $types = is_array($type) ? $type : [$type];

        foreach ($types as $entry) {
            if (! in_array($entry, ['object', 'string', 'integer', 'number', 'boolean', 'array', 'null'], true)) {
                return false;
            }
        }

        if (array_key_exists('properties', $schema) && ! is_array($schema['properties'])) {
            return false;
        }

        if (array_key_exists('required', $schema) && ! is_array($schema['required'])) {
            return false;
        }

        foreach (($schema['properties'] ?? []) as $name => $property) {
            if (! is_array($property) || ! $this->isValidSchema($property)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkValue(array $schema, mixed $value, string $path, array &$errors): void
    {
        $label = $path === '' ? 'input' : $path;

        if (($enum = $schema['enum'] ?? null) !== null) {
            if (! in_array($value, (array) $enum, true)) {
                $errors[] = "{$label} must be one of: ".implode(', ', array_map('json_encode', (array) $enum));
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$label} must equal ".json_encode($schema['const']);
        }

        $types = (array) ($schema['type'] ?? []);

        if ($types === []) {
            return;
        }

        $matches = array_filter($types, fn (string $type): bool => $this->typeMatches($type, $value));

        if ($matches === []) {
            $errors[] = "{$label} must be of type ".implode('|', $types).', got '.get_debug_type($value);

            return;
        }

        $this->checkByType($schema, $types, $value, $path, $errors);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $errors
     */
    private function checkByType(array $schema, array $types, mixed $value, string $path, array &$errors): void
    {
        $label = $path === '' ? 'input' : $path;

        if (in_array('string', $types, true) && is_string($value)) {
            $this->checkBounds($schema, $value, $label, $errors, ['minLength', 'min'], ['maxLength', 'max']);

            if (isset($schema['pattern']) && preg_match('/'.$schema['pattern'].'/', $value) !== 1) {
                $errors[] = "{$label} must match pattern {$schema['pattern']}";
            }
        }

        if ((in_array('integer', $types, true) || in_array('number', $types, true)) && (is_int($value) || is_float($value))) {
            if (in_array('integer', $types, true) && is_float($value) && floor($value) !== $value) {
                $errors[] = "{$label} must be an integer";
            }

            foreach (['minimum' => '>=', 'maximum' => '<='] as $keyword => $operator) {
                if (array_key_exists($keyword, $schema) && ! $this->compare($value, $schema[$keyword], $operator)) {
                    $errors[] = "{$label} must be {$operator} {$schema[$keyword]}";
                }
            }

            if (array_key_exists('exclusiveMinimum', $schema) && ! $this->compare($value, $schema['exclusiveMinimum'], '>')) {
                $errors[] = "{$label} must be > {$schema['exclusiveMinimum']}";
            }

            if (array_key_exists('exclusiveMaximum', $schema) && ! $this->compare($value, $schema['exclusiveMaximum'], '<')) {
                $errors[] = "{$label} must be < {$schema['exclusiveMaximum']}";
            }
        }

        if (in_array('array', $types, true) && is_array($value)) {
            $this->checkBounds($schema, count($value), $label, $errors, ['minItems'], ['maxItems']);

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) {
                    $this->checkValue($schema['items'], $item, $path.'['.$index.']', $errors);
                }
            }
        }

        if (in_array('object', $types, true) && is_array($value)) {
            foreach (($schema['properties'] ?? []) as $property => $propertySchema) {
                if (! is_array($propertySchema)) {
                    continue;
                }

                $propertyPath = $path === '' ? $property : $path.'.'.$property;

                if (array_key_exists($property, $value)) {
                    $this->checkValue($propertySchema, $value[$property], $propertyPath, $errors);
                } elseif (in_array($property, (array) ($schema['required'] ?? []), true)) {
                    $errors[] = "{$propertyPath} is required";
                }
            }

            if (($schema['additionalProperties'] ?? null) === false) {
                $allowed = array_keys($schema['properties'] ?? []);

                foreach (array_keys($value) as $key) {
                    if (! in_array((string) $key, $allowed, true)) {
                        $errors[] = "{$label} contains unexpected property {$key}";
                    }
                }
            }
        }
    }

    private function typeMatches(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && $this->isAssociative($value),
            'array' => is_array($value) && ! $this->isAssociative($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private function isAssociative(array $value): bool
    {
        return $value === [] || array_is_list($value) === false;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $minKeywords
     * @param  list<string>  $maxKeywords
     * @param  list<string>  $errors
     */
    private function checkBounds(array $schema, int|string $value, string $label, array &$errors, array $minKeywords, array $maxKeywords): void
    {
        $length = is_string($value) ? mb_strlen($value) : $value;

        foreach ($minKeywords as $keyword) {
            if (array_key_exists($keyword, $schema) && $length < $schema[$keyword]) {
                $errors[] = "{$label} must have at least {$schema[$keyword]} ".($keyword === 'minLength' ? 'characters' : 'items');
            }
        }

        foreach ($maxKeywords as $keyword) {
            if (array_key_exists($keyword, $schema) && $length > $schema[$keyword]) {
                $errors[] = "{$label} must have at most {$schema[$keyword]} ".($keyword === 'maxLength' ? 'characters' : 'items');
            }
        }
    }

    private function compare(int|float $value, mixed $bound, string $operator): bool
    {
        if (! is_int($bound) && ! is_float($bound)) {
            return true;
        }

        return match ($operator) {
            '>=' => $value >= $bound,
            '<=' => $value <= $bound,
            '>' => $value > $bound,
            '<' => $value < $bound,
        };
    }
}
