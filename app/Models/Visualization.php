<?php

namespace App\Models;

use Database\Factories\VisualizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Progressive visualization built by voice tasks (RF-16..RF-19b); open while
 * `closed_at` is null.
 */
#[Fillable([
    'user_id',
    'task_id',
    'visualization_status_id',
    'title',
    'source',
    'sections',
    'closed_at',
])]
class Visualization extends Model
{
    /** @use HasFactory<VisualizationFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sections' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Visualizations not closed by the operator yet.
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The task that created or last updated this visualization.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<VisualizationStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(VisualizationStatus::class, 'visualization_status_id');
    }

    /**
     * Every task (initial and follow-ups) linked to this visualization.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
