<?php

namespace App\Http\Resources;

use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A progressive visualization as seen by its owner (CT-05, CT-08, CT-09): the
 * same object persisted in `visualizations` and broadcast on
 * `visualization.updated`. Every text was neutralized on write (RF-20) and
 * sections come ordered by `position` (RF-17). Requires `status` loaded.
 *
 * @mixin Visualization
 */
class VisualizationResource extends JsonResource
{
    /**
     * Field order of a section in CT-05 (`jsonb` does not keep key order).
     *
     * @var list<string>
     */
    private const array SECTION_FIELDS = ['key', 'position', 'label', 'type', 'headline', 'status', 'items', 'text'];

    /**
     * @var list<string>
     */
    private const array ITEM_FIELDS = ['title', 'subtitle', 'badges'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sections = $this->sections ?? [];

        usort($sections, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'title' => $this->title,
            'source' => $this->source,
            'status' => $this->status->slug,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'sections' => array_map($this->section(...), array_values($sections)),
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function section(array $section): array
    {
        $ordered = self::ordered($section, self::SECTION_FIELDS);

        if (isset($ordered['items'])) {
            $ordered['items'] = array_map(
                fn (array $item): array => self::ordered($item, self::ITEM_FIELDS),
                $ordered['items'],
            );
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function ordered(array $values, array $fields): array
    {
        return array_intersect_key(array_replace(array_flip($fields), $values), $values);
    }
}
