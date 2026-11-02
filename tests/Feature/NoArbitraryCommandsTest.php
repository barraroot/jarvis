<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class NoArbitraryCommandsTest extends TestCase
{
    public function test_internal_tools_do_not_invoke_process_or_shell(): void
    {
        $files = Finder::create()->files()->in(app_path('Tools/Internal'))->name('*.php');

        $this->assertGreaterThan(0, iterator_count($files));

        $pattern = '/shell_exec|system\s*\(|passthru\s*\(|proc_open|popen\s*\(|exec\s*\(|Symfony\\\\Component\\\\Process|Facades\\\\Process/';

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $file->getContents(),
                "{$file->getPathname()} must not invoke shell or process commands with model input.",
            );
        }
    }
}
