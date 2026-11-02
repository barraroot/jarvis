<?php

namespace App\Services;

/**
 * Detects sensitive data in free text before it becomes a memory (US-6.2):
 * CPF, card numbers (13-19 digits), password-style assignments, API keys and
 * health-related terms. Never stores the text itself.
 */
class SensitiveDataDetector
{
    /**
     * Health-related terms, matched case-insensitively as whole words.
     *
     * @var list<string>
     */
    public const array HEALTH_TERMS = [
        'diagnóstico',
        'diagnostico',
        'doença',
        'doenca',
        'câncer',
        'cancer',
        'remédio',
        'remedio',
        'medicação',
        'medicacao',
        'sintoma',
        'diabetes',
        'pressão arterial',
        'pressao arterial',
        'glicose',
        'alergia',
        'receita médica',
        'receita medica',
        'exame de sangue',
    ];

    public function containsSensitive(string $text): bool
    {
        return $this->containsCpf($text)
            || $this->containsCardNumber($text)
            || $this->containsPasswordAssignment($text)
            || $this->containsApiKey($text)
            || $this->containsHealthData($text);
    }

    /**
     * Brazilian CPF: 11 digits, optionally masked (123.456.789-01).
     */
    private function containsCpf(string $text): bool
    {
        return preg_match('/\d{3}\.?\d{3}\.?\d{3}-?\d{2}/', $text) === 1;
    }

    /**
     * Card-like numbers: 13-19 digits, optionally separated by single spaces or dashes.
     */
    private function containsCardNumber(string $text): bool
    {
        return preg_match('/\d(?:[ -]?\d){12,18}(?!\d)/', $text) === 1;
    }

    /**
     * Password-style assignments ("senha:", "password=").
     */
    private function containsPasswordAssignment(string $text): bool
    {
        return preg_match('/(?:senha|password|passwd)\s*[:=]/i', $text) === 1;
    }

    /**
     * API keys in the common `sk-...` shape.
     */
    private function containsApiKey(string $text): bool
    {
        return preg_match('/\bsk-[a-z0-9_-]{4,}\b/i', $text) === 1;
    }

    private function containsHealthData(string $text): bool
    {
        $pattern = '/\b(?:'.implode('|', array_map(
            static fn (string $term): string => preg_quote($term, '/'),
            self::HEALTH_TERMS,
        )).')\b/iu';

        return preg_match($pattern, $text) === 1;
    }
}
