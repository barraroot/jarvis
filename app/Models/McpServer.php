<?php

namespace App\Models;

use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'connector_status_id',
    'name',
    'url',
    'credential',
    'is_active',
    'last_synced_at',
    'last_checked_at',
    'last_error',
])]
#[Hidden(['credential'])]
class McpServer extends Model
{
    /** @use HasFactory<McpServerFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credential' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * Global connectors (admin-managed) have no owner.
     */
    public function isGlobal(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Slug used in catalogue identifiers (`mcp.<server_slug>.<tool>`, US-9.3).
     */
    public function slug(): string
    {
        return Str::slug($this->name, '_') ?: 'server_'.$this->getKey();
    }

    /**
     * Users who can see this connector: the owner (personal) or every active user (global).
     *
     * @return Collection<int, User>
     */
    public function audience(): Collection
    {
        if (! $this->isGlobal()) {
            return User::query()->whereKey($this->user_id)->where('is_active', true)->get();
        }

        return User::query()->where('is_active', true)->get();
    }

    /**
     * Masked representation of the credential: `****` followed by the last four characters.
     */
    public function maskedCredential(): ?string
    {
        $credential = $this->credential;

        if ($credential === null || $credential === '') {
            return null;
        }

        return '****'.substr($credential, -4);
    }

    #[Scope]
    protected function global(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * Connectors visible to a user: every global one plus their own.
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('user_id')->orWhere('user_id', $user->id);
        });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ConnectorStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ConnectorStatus::class, 'connector_status_id');
    }

    /** @return HasMany<McpToolDefinition, $this> */
    public function toolDefinitions(): HasMany
    {
        return $this->hasMany(McpToolDefinition::class);
    }
}
