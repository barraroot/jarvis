<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

/**
 * Union schema for the final structured result block (US-11.1): exactly one of
 * the nine result types, mirroring `resources/schemas/results/*.json`. Built
 * with the `laravel/ai` JsonSchema factory so the model receives the same
 * contracts the backend validates against.
 */
class StructuredResultSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema): array
    {
        $speech = $schema->string()->max(300);
        $suggestedActions = $schema->array()->max(4)->items($schema->object([
            'label' => $schema->string()->min(1)->max(40)->required(),
            'request' => $schema->string()->min(1)->max(500)->required(),
        ]));

        $common = static fn (): array => [
            'speech' => $speech,
            'suggested_actions' => $suggestedActions,
        ];

        $branches = [
            static::branch($schema, 'text', [
                'text' => $schema->string()->required(),
            ], $common),
            static::branch($schema, 'list', [
                'title' => $schema->string(),
                'items' => $schema->array()->items($schema->object([
                    'label' => $schema->string()->required(),
                    'detail' => $schema->string(),
                ]))->required(),
            ], $common),
            static::branch($schema, 'table', [
                'columns' => $schema->array()->items($schema->string())->required(),
                'rows' => $schema->array()->items($schema->array())->required(),
                'caption' => $schema->string(),
            ], $common),
            static::branch($schema, 'report', [
                'title' => $schema->string()->required(),
                'sections' => $schema->array()->items($schema->object([
                    'heading' => $schema->string()->required(),
                    'body' => $schema->string()->required(),
                ]))->required(),
            ], $common),
            static::branch($schema, 'search_results', [
                'query' => $schema->string()->required(),
                'results' => $schema->array()->items($schema->object([
                    'title' => $schema->string()->required(),
                    'url' => $schema->string(),
                    'snippet' => $schema->string()->required(),
                    'source' => $schema->string()->required(),
                ]))->required(),
            ], $common),
            static::branch($schema, 'task_card', [
                'task_id' => $schema->integer()->min(0)->required(),
                'title' => $schema->string()->required(),
                'status' => $schema->string()->enum(['pending', 'queued', 'running', 'waiting_approval', 'completed', 'failed', 'cancelled'])->required(),
                'progress' => $schema->integer()->min(0)->max(100)->required(),
            ], $common),
            static::branch($schema, 'data', [
                'label' => $schema->string()->required(),
                'value' => $schema->union(['string', 'number', 'integer'])->required(),
                'unit' => $schema->string(),
                'series' => $schema->object([
                    'label' => $schema->string(),
                    'values' => $schema->array()->items($schema->union(['number', 'integer'])),
                ]),
            ], $common),
            static::branch($schema, 'execution_status', [
                'entity' => $schema->string()->enum(['tool', 'agent'])->required(),
                'name' => $schema->string(),
                'status' => $schema->string()->required(),
                'message' => $schema->string()->required(),
            ], $common),
            static::branch($schema, 'document', [
                'title' => $schema->string()->required(),
                'format' => $schema->string()->enum(['markdown', 'plain'])->required(),
                'body' => $schema->string()->required(),
            ], $common),
        ];

        return [
            'result' => $schema->anyOf($branches)->required(),
        ];
    }

    /**
     * One union branch: the type discriminator plus the type-specific fields.
     *
     * @param  array<string, mixed>  $fields
     * @param  (callable(): array<string, mixed>)  $common
     */
    private static function branch(JsonSchema $schema, string $type, array $fields, callable $common): ObjectType
    {
        return $schema->object([
            'type' => $schema->string()->enum([$type])->required(),
            ...$fields,
            ...$common(),
        ]);
    }
}
