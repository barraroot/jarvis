<?php

namespace App\Models;

use Database\Factories\AiProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'ai_provider_driver_id',
    'name',
    'slug',
    'api_key',
    'base_url',
    'is_active',
    'last_tested_at',
    'last_test_error',
])]
#[Hidden(['api_key'])]
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Masked representation of the API key (US-5.1): `****` followed by the last four characters.
     *
     * @return Attribute<string|null, never>
     */
    protected function maskedApiKey(): Attribute
    {
        return Attribute::get(function (): ?string {
            $key = $this->api_key;

            if ($key === null || $key === '') {
                return null;
            }

            return '****'.substr($key, -4);
        });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<AiProviderDriver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(AiProviderDriver::class, 'ai_provider_driver_id');
    }

    /** @return HasMany<AiModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }
}
