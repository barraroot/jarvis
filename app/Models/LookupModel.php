<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Base class for every lookup table (categorical values keyed by a unique slug).
 */
#[Fillable(['name', 'slug', 'description', 'is_active'])]
abstract class LookupModel extends Model
{
    /**
     * In-memory cache of slug → id, keyed by concrete model class.
     *
     * @var array<class-string<static>, array<string, int>>
     */
    protected static array $slugIdCache = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Find a lookup row by its slug.
     */
    public static function findBySlug(string $slug): ?static
    {
        return static::query()->where('slug', $slug)->first();
    }

    /**
     * Resolve the primary key for a slug, caching the result in memory for the process lifetime.
     *
     * @throws ModelNotFoundException
     */
    public static function idFor(string $slug): int
    {
        if (isset(static::$slugIdCache[static::class][$slug])) {
            return static::$slugIdCache[static::class][$slug];
        }

        $id = static::query()->where('slug', $slug)->value('id');

        if ($id === null) {
            throw (new ModelNotFoundException)->setModel(static::class, [$slug]);
        }

        return static::$slugIdCache[static::class][$slug] = (int) $id;
    }

    /**
     * Forget every cached slug → id mapping (all lookup models).
     */
    public static function flushCache(): void
    {
        static::$slugIdCache = [];
    }
}
