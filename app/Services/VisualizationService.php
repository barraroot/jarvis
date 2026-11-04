<?php

namespace App\Services;

use App\Events\Visualization\VisualizationUpdated;
use App\Jobs\BroadcastVisualizationUpdate;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Visualization;
use App\Models\VisualizationStatus;
use App\Support\UntrustedContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Progressive visualizations of voice tasks (RF-16..RF-20, CT-05): the
 * internal tool `visualization.update_section` upserts sections into the
 * task's open visualization (created `syncing` on the first section), the
 * task lifecycle finishes it, and the operator closes it. Every change is
 * broadcast as `visualization.updated` on the owner's channel, throttled to
 * one event per 500 ms with the latest state always delivered (RNF-04).
 */
class VisualizationService
{
    /**
     * Throttle window of `visualization.updated`, as `task.updated` (RNF-04).
     */
    public const float THROTTLE_SECONDS = 0.5;

    public const string TYPE_QUESTION = 'question';

    public const string TYPE_RESULTS = 'results';

    public const string TYPE_TEXT = 'text';

    /**
     * Reserved badge rendered as "MELHOR RESULTADO"; its item becomes the
     * question headline (RF-17).
     */
    public const string BEST_BADGE = 'best';

    /**
     * @var list<string>
     */
    public const array SECTION_TYPES = [self::TYPE_QUESTION, self::TYPE_RESULTS, self::TYPE_TEXT];

    /**
     * Upsert one section into the visualization of a voice task (RF-16,
     * RF-17, RF-19). The task owner is the only possible owner: a linked
     * visualization of another user, or a closed one, is never touched — a
     * new one is created instead.
     *
     * @param  array{title?: string|null, source?: string|null, section: array<string, mixed>}  $input
     *
     * @throws InvalidArgumentException
     */
    public function upsertSection(Task $task, array $input): Visualization
    {
        if ($task->voice_session_id === null) {
            throw new InvalidArgumentException(__('voice.visualization_requires_voice_task'));
        }

        $section = $this->normalizeSection($input['section'] ?? []);
        $title = $this->clean($input['title'] ?? null, 160);
        $source = $this->clean($input['source'] ?? null, 160);

        $visualization = DB::transaction(function () use ($task, $section, $title, $source): Visualization {
            $visualization = $task->visualization_id === null ? null : Visualization::query()
                ->whereKey($task->visualization_id)
                ->where('user_id', $task->user_id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($visualization === null) {
                $visualization = Visualization::query()->create([
                    'user_id' => $task->user_id,
                    'task_id' => $task->id,
                    'visualization_status_id' => VisualizationStatus::idFor(VisualizationStatus::SYNCING),
                    'title' => $title ?? $this->clean($task->title, 160) ?? '',
                    'source' => $source,
                    'sections' => [],
                ]);

                $task->update(['visualization_id' => $visualization->id]);
            }

            $visualization->fill([
                'task_id' => $task->id,
                'visualization_status_id' => VisualizationStatus::idFor(VisualizationStatus::SYNCING),
                'title' => $title ?? $visualization->title,
                'source' => $source ?? $visualization->source,
                'sections' => $this->mergeSection($visualization->sections ?? [], $section),
            ]);

            // A follow-up section always moves the panel clock (RF-17).
            $visualization->updated_at = now();
            $visualization->save();

            return $visualization;
        });

        $this->broadcast($visualization->load('status'));

        return $visualization;
    }

    /**
     * End of the task that last updated the visualization (RF-18):
     * `completed` → `updated`, `failed`/`cancelled` → `failed`. A follow-up
     * task that took the visualization over keeps it; terminal states
     * bypass the throttle so the final state is never delayed.
     */
    public function finishForTask(Task $task): ?Visualization
    {
        if ($task->visualization_id === null) {
            return null;
        }

        // Compared by id: a `status` relation loaded before the transition is stale.
        $statusSlug = match ($task->task_status_id) {
            TaskStatus::idFor(TaskStatus::COMPLETED) => VisualizationStatus::UPDATED,
            TaskStatus::idFor(TaskStatus::FAILED), TaskStatus::idFor(TaskStatus::CANCELLED) => VisualizationStatus::FAILED,
            default => null,
        };

        $visualization = Visualization::query()
            ->whereKey($task->visualization_id)
            ->where('user_id', $task->user_id)
            ->first();

        if ($statusSlug === null || $visualization === null) {
            return $visualization;
        }

        if ($visualization->task_id !== null && $visualization->task_id !== $task->id) {
            return $visualization;
        }

        $visualization->visualization_status_id = VisualizationStatus::idFor($statusSlug);
        $visualization->updated_at = now();
        $visualization->save();

        $this->broadcastNow($visualization->load('status'));

        return $visualization;
    }

    /**
     * Close the panel (RF-19a, CT-09): idempotent, never touches the task.
     */
    public function close(Visualization $visualization): Visualization
    {
        if ($visualization->closed_at === null) {
            $visualization->update(['closed_at' => now()]);

            $this->broadcastNow($visualization->loadMissing('status'));
        }

        return $visualization->loadMissing('status');
    }

    /**
     * Throttled broadcast (RNF-04): inside the window the event is
     * suppressed and a unique trailing job delivers the latest state.
     */
    public function broadcast(Visualization $visualization): void
    {
        $last = Cache::get($this->throttleKey($visualization));

        if (is_numeric($last) && (microtime(true) - (float) $last) < self::THROTTLE_SECONDS) {
            BroadcastVisualizationUpdate::dispatch($visualization->id);

            return;
        }

        $this->broadcastNow($visualization);
    }

    /**
     * Immediate broadcast that also opens a new throttle window.
     */
    public function broadcastNow(Visualization $visualization): void
    {
        Cache::put($this->throttleKey($visualization), microtime(true), 60);

        VisualizationUpdated::dispatch($visualization);
    }

    private function throttleKey(Visualization $visualization): string
    {
        return "visualization:{$visualization->id}:broadcast_at";
    }

    /**
     * Existing keys keep their position; new keys go to `max + 1`, so
     * positions stay stable and gapless (RF-17, RF-19). A `best` result
     * becomes the question headline.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $section
     * @return list<array<string, mixed>>
     */
    private function mergeSection(array $sections, array $section): array
    {
        $index = array_search($section['key'], array_column($sections, 'key'), true);

        if ($index === false) {
            $section['position'] = count($sections) === 0 ? 1 : max(array_column($sections, 'position')) + 1;
            $sections[] = $section;
        } else {
            $section['position'] = $sections[$index]['position'];
            $sections[$index] = $section;
        }

        $sections = $this->applyBestHeadline($sections);

        usort($sections, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        return array_values($sections);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function applyBestHeadline(array $sections): array
    {
        $best = null;

        foreach ($sections as $section) {
            if ($section['type'] !== self::TYPE_RESULTS) {
                continue;
            }

            foreach ($section['items'] as $item) {
                if (in_array(self::BEST_BADGE, $item['badges'], true)) {
                    $best = $item['title'];
                }
            }
        }

        if ($best === null || $best === '') {
            return $sections;
        }

        foreach ($sections as $index => $section) {
            if ($section['type'] === self::TYPE_QUESTION) {
                $sections[$index]['headline'] = Str::limit($best, 300, '');
            }
        }

        return $sections;
    }

    /**
     * Closed section shape per type, every text neutralized (RF-20).
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function normalizeSection(array $section): array
    {
        $key = is_string($section['key'] ?? null) ? $section['key'] : '';
        $type = $section['type'] ?? null;

        if (preg_match('/^[a-z_]{1,32}$/', $key) !== 1 || ! in_array($type, self::SECTION_TYPES, true)) {
            throw new InvalidArgumentException('Seção de visualização inválida.');
        }

        $normalized = [
            'key' => $key,
            'label' => $this->clean($section['label'] ?? null, 40) ?? Str::upper(str_replace('_', ' ', $key)),
            'type' => $type,
        ];

        return match ($type) {
            self::TYPE_QUESTION => $normalized + [
                'headline' => $this->clean($section['headline'] ?? null, 300) ?? '',
            ],
            self::TYPE_RESULTS => $normalized + [
                'status' => ($section['status'] ?? null) === VisualizationStatus::UPDATED
                    ? VisualizationStatus::UPDATED
                    : VisualizationStatus::SYNCING,
                'items' => $this->normalizeItems(is_array($section['items'] ?? null) ? $section['items'] : []),
            ],
            default => $normalized + [
                'text' => $this->clean($section['text'] ?? null, 4000) ?? '',
            ],
        };
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<array{title: string, subtitle: string, badges: list<string>}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach (array_slice(array_values(array_filter($items, 'is_array')), 0, 10) as $item) {
            $badges = [];

            foreach (is_array($item['badges'] ?? null) ? $item['badges'] : [] as $badge) {
                $badge = $badge === self::BEST_BADGE ? $badge : $this->clean(is_string($badge) ? $badge : null, 20);

                if ($badge !== null && ! in_array($badge, $badges, true)) {
                    $badges[] = $badge;
                }
            }

            $normalized[] = [
                'title' => $this->clean($item['title'] ?? null, 200) ?? '',
                'subtitle' => $this->clean($item['subtitle'] ?? null, 200) ?? '',
                'badges' => array_slice($badges, 0, 4),
            ];
        }

        return $normalized;
    }

    /**
     * Untrusted text (RF-20): system-like markup neutralized, HTML tags
     * stripped, trimmed and limited; empty becomes null.
     */
    private function clean(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(strip_tags(UntrustedContent::neutralize($value)));

        return $clean === '' ? null : Str::limit($clean, $limit, '');
    }
}
