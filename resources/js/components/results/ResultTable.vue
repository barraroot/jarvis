<script setup lang="ts">
import { computed, ref } from 'vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import type { TableCell, TableResultPayload } from '@/types/results';

const props = defineProps<{
    payload: TableResultPayload;
}>();

/** Column sort state: -1 none/ascending cycle via direction toggling. */
const sortIndex = ref<number | null>(null);
const sortDirection = ref<'asc' | 'desc'>('asc');

const rows = computed<TableCell[][]>(() => {
    if (sortIndex.value === null) {
        return props.payload.rows;
    }

    return [...props.payload.rows].sort((a, b) => compareCells(a[sortIndex.value ?? 0], b[sortIndex.value ?? 0]));
});

function compareCells(a: TableCell | undefined, b: TableCell | undefined): number {
    const left = String(a ?? '');
    const right = String(b ?? '');

    return left.localeCompare(right, 'pt-BR', { numeric: true }) * (sortDirection.value === 'asc' ? 1 : -1);
}

function toggleSort(index: number): void {
    if (sortIndex.value === index) {
        if (sortDirection.value === 'asc') {
            sortDirection.value = 'desc';

            return;
        }

        sortIndex.value = null;
        sortDirection.value = 'asc';

        return;
    }

    sortIndex.value = index;
    sortDirection.value = 'asc';
}

function cellLabel(cell: TableCell | undefined): string {
    if (cell === null || cell === undefined) {
        return '—';
    }

    if (typeof cell === 'boolean') {
        return cell ? 'sim' : 'não';
    }

    return String(cell);
}
</script>

<template>
    <HudPanel :title="payload.caption" tag="div" data-testid="result-table">
        <!-- Horizontal scrolling on mobile; the table itself never overflows the bubble. -->
        <div class="min-w-0 overflow-x-auto">
            <table class="w-full border-collapse font-mono text-xs text-text-100">
                <thead>
                    <tr class="border-b border-ocean-600">
                        <th
                            v-for="(column, index) in payload.columns"
                            :key="index"
                            scope="col"
                            class="whitespace-nowrap px-2 text-left"
                        >
                            <button
                                type="button"
                                class="label-caps inline-flex min-h-11 items-center gap-1 text-[0.65rem] text-biolum-200 hover:text-cyan-300"
                                :data-testid="`result-table-sort-${index}`"
                                @click="toggleSort(index)"
                            >
                                {{ column }}
                                <span aria-hidden="true" class="text-text-400">{{ sortIndex === index ? (sortDirection === 'asc' ? '▲' : '▼') : '⇅' }}</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, rowIndex) in rows" :key="rowIndex" class="border-b border-ocean-600/40 last:border-0">
                        <td
                            v-for="(cell, columnIndex) in row"
                            :key="columnIndex"
                            class="max-w-64 truncate whitespace-nowrap px-2 py-1.5"
                            :title="cellLabel(cell)"
                        >
                            {{ cellLabel(cell) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </HudPanel>
</template>
