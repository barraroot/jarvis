<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

abstract class LookupTableSeeder extends Seeder
{
    /**
     * The lookup table populated by this seeder.
     */
    abstract protected function table(): string;

    /**
     * Rows keyed by slug.
     *
     * @return array<string, array{name: string, description?: string|null}>
     */
    abstract protected function rows(): array;

    /**
     * Upsert every row by slug so the seeder can run repeatedly without duplicating lines.
     */
    public function run(): void
    {
        $now = now();

        foreach ($this->rows() as $slug => $row) {
            $attributes = [
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'updated_at' => $now,
            ];

            $existing = DB::table($this->table())->where('slug', $slug);

            if ($existing->exists()) {
                $existing->update($attributes);

                continue;
            }

            DB::table($this->table())->insert($attributes + [
                'slug' => $slug,
                'is_active' => true,
                'created_at' => $now,
            ]);
        }
    }
}
