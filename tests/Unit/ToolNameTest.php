<?php

namespace Tests\Unit;

use App\Support\ToolName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ToolNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function identifiers(): array
    {
        return [
            'memory tool' => ['memory.remember', 'memory_remember'],
            'internal tool' => ['task.set_progress', 'task_set_progress'],
            'delegation tool' => ['delegate.personal-assistant', 'delegate_personal-assistant'],
            'mcp tool' => ['mcp.github_3.search issues', 'mcp_github_3_search_issues'],
            'already valid' => ['current_time', 'current_time'],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_identifier_becomes_provider_safe_name(string $identifier, string $expected): void
    {
        $this->assertSame($expected, ToolName::forProvider($identifier));
        $this->assertMatchesRegularExpression(ToolName::PATTERN, ToolName::forProvider($identifier));
    }

    public function test_long_identifiers_are_truncated_without_colliding(): void
    {
        $prefix = 'mcp.'.str_repeat('server', 12).'.';

        $first = ToolName::forProvider($prefix.'list_issues');
        $second = ToolName::forProvider($prefix.'list_pulls');

        $this->assertSame(ToolName::MAX_LENGTH, strlen($first));
        $this->assertMatchesRegularExpression(ToolName::PATTERN, $first);
        $this->assertMatchesRegularExpression(ToolName::PATTERN, $second);
        $this->assertNotSame($first, $second);
    }
}
