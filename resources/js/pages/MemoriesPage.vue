<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudModal from '@/components/ui/HudModal.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudSelect from '@/components/ui/HudSelect.vue';
import HudTextarea from '@/components/ui/HudTextarea.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useMemoriesStore, type MemoryCategory } from '@/stores/memories';
import { useToastStore } from '@/stores/toast';
import type { Memory } from '@/types/models';

const { t } = useI18n();
const memories = useMemoriesStore();
const toasts = useToastStore();

const statusOptions = [
    { value: 'confirmed', label: t('status.confirmed') },
    { value: 'hypothesis', label: t('status.hypothesis') },
];

const categoryOptions: { value: MemoryCategory; label: string }[] = [
    { value: 'preference', label: t('memories.categories.preference') },
    { value: 'project', label: t('memories.categories.project') },
    { value: 'context', label: t('memories.categories.context') },
    { value: 'learning', label: t('memories.categories.learning') },
    { value: 'fact', label: t('memories.categories.fact') },
];

const statusFilter = ref('');
const categoryFilter = ref<'' | MemoryCategory>('');
const search = ref('');

/** Editing state: the memory being edited and the draft content. */
const editingId = ref<string | null>(null);
const editingContent = ref('');

/** Delete confirmation target. */
const deleting = ref<Memory | null>(null);

const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deleting.value = null;
        }
    },
});

/** New memory form. */
const creating = ref(false);
const newContent = ref('');
const newCategory = ref<MemoryCategory>('preference');

function applyFilters(): void {
    memories.setStatusFilter(statusFilter.value === '' ? null : statusFilter.value);
    memories.setCategoryFilter(categoryFilter.value === '' ? null : categoryFilter.value);
    memories.setSearch(search.value);

    void memories.refresh().catch(() => {
        toasts.push(t('errors.network'), { variant: 'danger' });
    });
}

function categoryLabel(slug: string): string {
    return t(`memories.categories.${slug}`) as string;
}

function formatDate(timestamp: string): string {
    const date = new Date(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

function startEdit(memory: Memory): void {
    editingId.value = memory.id;
    editingContent.value = memory.content;
}

function cancelEdit(): void {
    editingId.value = null;
    editingContent.value = '';
}

async function saveEdit(memory: Memory): Promise<void> {
    try {
        await memories.update(memory.id, editingContent.value.trim());
        toasts.push(t('memories.updated'), { variant: 'success' });
        cancelEdit();
    } catch {
        toasts.push(t('memories.updateError'), { variant: 'danger' });
    }
}

async function removeMemory(): Promise<void> {
    if (deleting.value === null) {
        return;
    }

    try {
        await memories.remove(deleting.value.id);
        toasts.push(t('memories.deleted'), { variant: 'success' });
    } catch {
        toasts.push(t('memories.deleteError'), { variant: 'danger' });
    } finally {
        deleting.value = null;
    }
}

async function confirmMemory(memory: Memory): Promise<void> {
    try {
        await memories.confirm(memory.id);
        toasts.push(t('memories.confirmed'), { variant: 'success' });
    } catch {
        toasts.push(t('memories.actionError'), { variant: 'danger' });
    }
}

async function discardMemory(memory: Memory): Promise<void> {
    try {
        await memories.discard(memory.id);
        toasts.push(t('memories.discarded'), { variant: 'success' });
    } catch {
        toasts.push(t('memories.actionError'), { variant: 'danger' });
    }
}

async function createMemory(): Promise<void> {
    try {
        await memories.create(newContent.value.trim(), newCategory.value);
        toasts.push(t('memories.saved'), { variant: 'success' });
        newContent.value = '';
        creating.value = false;
    } catch {
        toasts.push(t('memories.createError'), { variant: 'danger' });
    }
}

const newMemoryValid = computed(() => newContent.value.trim().length > 0 && newContent.value.trim().length <= 2000);

onMounted(() => {
    void memories.refresh().catch(() => {
        toasts.push(t('errors.network'), { variant: 'danger' });
    });
});
</script>

<template>
    <div class="mx-auto flex h-full min-h-0 max-w-3xl flex-col gap-3" data-testid="memories-page">
        <div class="flex items-center justify-between gap-3 border-b border-line pb-2" data-testid="memories-header">
            <h1 class="hud-label text-sm text-cyan hud-glow">{{ t('memories.title') }}</h1>
            <HudButton data-testid="memories-create" @click="creating = !creating">
                {{ t('memories.create') }}
            </HudButton>
        </div>

        <div v-if="creating" class="hud-glass flex flex-col gap-2 rounded-sm border border-line p-3" data-testid="memories-create-form">
            <HudTextarea
                v-model="newContent"
                :placeholder="t('memories.createPlaceholder')"
                :maxlength="2000"
                :rows="2"
                :auto-expand="true"
                data-testid="memories-create-input"
            />
            <div class="flex flex-wrap items-end justify-between gap-2">
                <HudSelect
                    v-model="newCategory"
                    :options="categoryOptions"
                    :label="t('memories.filterCategory')"
                    class="w-44"
                    data-testid="memories-create-category"
                />
                <div class="flex gap-2">
                    <HudButton variant="ghost" data-testid="memories-create-cancel" @click="creating = false">
                        {{ t('memories.cancel') }}
                    </HudButton>
                    <HudButton :disabled="!newMemoryValid" data-testid="memories-create-save" @click="createMemory">
                        {{ t('memories.save') }}
                    </HudButton>
                </div>
            </div>
            <p class="hud-label text-[0.6rem] text-muted">{{ t('memories.createHint') }}</p>
        </div>

        <div class="flex flex-wrap items-end gap-2" data-testid="memories-filters">
            <HudInput
                v-model="search"
                :placeholder="t('memories.searchPlaceholder')"
                class="min-w-44 flex-1"
                data-testid="memories-search"
                @keyup.enter="applyFilters"
            />
            <label class="flex min-w-0 flex-col gap-1">
                <span class="hud-label text-[0.65rem] text-muted">{{ t('memories.filterStatus') }}</span>
                <select
                    v-model="statusFilter"
                    class="w-full min-w-0 rounded-sm border border-line bg-bg-navy px-3 py-2 font-mono text-sm text-text focus:border-cyan focus:outline-none"
                    data-testid="memories-filter-status"
                    @change="applyFilters"
                >
                    <option value="">{{ t('memories.allStatuses') }}</option>
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label class="flex min-w-0 flex-col gap-1">
                <span class="hud-label text-[0.65rem] text-muted">{{ t('memories.filterCategory') }}</span>
                <select
                    v-model="categoryFilter"
                    class="w-full min-w-0 rounded-sm border border-line bg-bg-navy px-3 py-2 font-mono text-sm text-text focus:border-cyan focus:outline-none"
                    data-testid="memories-filter-category"
                    @change="applyFilters"
                >
                    <option value="">{{ t('memories.allCategories') }}</option>
                    <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <HudButton variant="ghost" data-testid="memories-filter-apply" @click="applyFilters">
                {{ t('memories.filter') }}
            </HudButton>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto" data-testid="memories-list">
            <EmptyState v-if="memories.memories.length === 0 && !memories.loading">
                {{ t('memories.emptyHint') }}
            </EmptyState>

            <ul v-else class="flex min-w-0 flex-col gap-2 pb-2">
                <li
                    v-for="memory in memories.memories"
                    :key="memory.id"
                    class="hud-glass flex min-w-0 flex-col gap-2 rounded-sm border border-line p-3"
                    data-testid="memory-item"
                >
                    <div class="flex items-start justify-between gap-2">
                        <StatusBadge :status="memory.status" />
                        <span class="hud-label font-mono text-[0.6rem] text-muted" data-testid="memory-category">
                            {{ categoryLabel(memory.category) }}
                        </span>
                    </div>

                    <p
                        v-if="editingId !== memory.id"
                        class="min-w-0 whitespace-pre-wrap break-words font-mono text-sm text-text"
                        data-testid="memory-content"
                    >
                        {{ memory.content }}
                    </p>

                    <HudTextarea
                        v-else
                        v-model="editingContent"
                        :maxlength="2000"
                        :rows="3"
                        :auto-expand="true"
                        data-testid="memory-edit-input"
                    />

                    <div class="flex flex-wrap items-center gap-2 border-t border-line/50 pt-2">
                        <RouterLink
                            v-if="memory.source_conversation_id !== null"
                            :to="{ name: 'conversation', params: { id: memory.source_conversation_id } }"
                            class="hud-label rounded-sm border border-line px-2 py-1 text-[0.6rem] text-cyan-bright hover:border-cyan"
                            data-testid="memory-source"
                        >
                            {{ t('memories.source') }}: {{ t('memories.sourceConversation', { id: memory.source_conversation_id }) }}
                        </RouterLink>

                        <span v-else class="hud-label text-[0.6rem] text-muted" data-testid="memory-source-manual">
                            {{ t('memories.source') }}: —
                        </span>

                        <span class="hud-label ml-auto font-mono text-[0.55rem] text-muted" data-testid="memory-date">
                            {{ formatDate(memory.created_at) }}
                        </span>

                        <template v-if="editingId === memory.id">
                            <HudButton data-testid="memory-edit-save" @click="saveEdit(memory)">
                                {{ t('memories.save') }}
                            </HudButton>
                            <HudButton variant="ghost" data-testid="memory-edit-cancel" @click="cancelEdit">
                                {{ t('memories.cancel') }}
                            </HudButton>
                        </template>

                        <template v-else>
                            <HudButton variant="ghost" data-testid="memory-edit" @click="startEdit(memory)">
                                {{ t('memories.edit') }}
                            </HudButton>

                            <template v-if="memory.status === 'hypothesis'">
                                <HudButton data-testid="memory-confirm" @click="confirmMemory(memory)">
                                    {{ t('memories.confirm') }}
                                </HudButton>
                                <HudButton variant="danger" data-testid="memory-discard" @click="discardMemory(memory)">
                                    {{ t('memories.discard') }}
                                </HudButton>
                            </template>

                            <HudButton variant="danger" data-testid="memory-delete" @click="deleting = memory">
                                {{ t('memories.delete') }}
                            </HudButton>
                        </template>
                    </div>
                </li>
            </ul>

            <div v-if="memories.hasMorePages" class="flex justify-center py-3" data-testid="memories-load-more">
                <HudButton variant="ghost" :loading="memories.loading" @click="memories.loadMore">
                    {{ t('memories.loadMore') }}
                </HudButton>
            </div>
        </div>

        <HudModal v-model:open="deleteOpen" :title="t('memories.deleteTitle')">
            <template v-if="deleting !== null">
                <p class="font-mono text-sm text-text">{{ t('memories.deleteMessage') }}</p>
                <p class="hud-label mt-3 max-h-40 overflow-y-auto break-words border border-line p-2 text-[0.65rem] text-muted">
                    {{ deleting.content }}
                </p>
            </template>
            <template #footer>
                <HudButton variant="ghost" data-testid="memories-delete-cancel" @click="deleting = null">
                    {{ t('memories.cancel') }}
                </HudButton>
                <HudButton variant="danger" data-testid="memories-delete-confirm" @click="removeMemory">
                    {{ t('memories.delete') }}
                </HudButton>
            </template>
        </HudModal>
    </div>
</template>
