<?php

namespace App\Tools;

use App\Tools\Contracts\JarvisTool;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Discovers the internal tool classes in `app/Tools/Internal` (US-8.1).
 *
 * Resolution is cached for the request; classes are container-resolved so
 * tools may inject their own services.
 */
class InternalToolRegistry
{
    /**
     * @var array<string, JarvisTool>|null identifier → instance
     */
    private ?array $tools = null;

    /**
     * @return array<string, JarvisTool> identifier → instance, sorted by identifier
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = [];

        foreach ($this->discoverClasses() as $class) {
            /** @var JarvisTool $tool */
            $tool = app($class);
            $identifier = $tool->identifier();

            if (isset($this->tools[$identifier])) {
                throw new RuntimeException("Duplicate internal tool identifier [{$identifier}].");
            }

            $this->tools[$identifier] = $tool;
        }

        ksort($this->tools);

        return $this->tools;
    }

    /**
     * @return array<string, class-string<JarvisTool>> identifier → FQCN
     */
    public function classes(): array
    {
        return array_map(
            fn (JarvisTool $tool): string => $tool::class,
            $this->all(),
        );
    }

    /**
     * @return list<class-string<JarvisTool>>
     */
    private function discoverClasses(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Tools/Internal'))->name('*Tool.php') as $file) {
            $class = 'App\\Tools\\Internal\\'.$file->getBasename('.php');

            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract() || ! is_subclass_of($class, JarvisTool::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
