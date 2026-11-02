<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudModal from '@/components/ui/HudModal.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { HttpError, type ValidationErrors } from '@/lib/http';
import { useConnectorsStore, type ToolClassification } from '@/stores/connectors';
import { useToastStore } from '@/stores/toast';
import type { ConnectorSummary, ConnectorTool } from '@/types/models';

const { t, locale } = useI18n();
const connectors = useConnectorsStore();
const toasts = useToastStore();

const formOpen = ref(false);
const editingId = ref<number | null>(null);
const saving = ref(false);
const errors = ref<ValidationErrors>({});
const form = reactive({ name: '', url: '', credential: '' });

const expandedId = ref<number | null>(null);
const loadingToolsId = ref<number | null>(null);
const removing = ref<ConnectorSummary | null>(null);
const removeOpen = computed({
    get: () => removing.value !== null,
    set: (open: boolean) => {
        if (!open) {
            removing.value = null;
        }
    },
});

const editing = computed(() => connectors.connectors.find((connector) => connector.id === editingId.value) ?? null);

function formatDate(value: string | null): string {
    if (value === null) {
        return t('connectors.never');
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

function openCreate(): void {
    editingId.value = null;
    Object.assign(form, { name: '', url: '', credential: '' });
    errors.value = {};
    formOpen.value = true;
}

function openEdit(connector: ConnectorSummary): void {
    editingId.value = connector.id;
    Object.assign(form, { name: connector.name, url: connector.url, credential: '' });
    errors.value = {};
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingId.value = null;
    errors.value = {};
}

async function submit(): Promise<void> {
    saving.value = true;
    errors.value = {};

    const payload = { name: form.name.trim(), url: form.url.trim(), credential: form.credential === '' ? null : form.credential };

    try {
        if (editingId.value === null) {
            await connectors.create(payload);
            toasts.push(t('connectors.created'), { variant: 'success' });
        } else {
            await connectors.update(editingId.value, payload);
            toasts.push(t('connectors.updated'), { variant: 'success' });
        }

        closeForm();
    } catch (error) {
        if (error instanceof HttpError && error.status === 422) {
            errors.value = error.validationErrors;
        } else {
            toasts.push(t('connectors.saveFailed'), { variant: 'danger' });
        }
    } finally {
        saving.value = false;
    }
}

async function resync(connector: ConnectorSummary): Promise<void> {
    try {
        await connectors.resync(connector.id);
        toasts.push(t('connectors.resyncQueued'), { variant: 'info' });
    } catch {
        toasts.push(t('connectors.actionFailed'), { variant: 'danger' });
    }
}

async function confirmRemove(): Promise<void> {
    const connector = removing.value;

    if (connector === null) {
        return;
    }

    try {
        await connectors.remove(connector.id);
        toasts.push(t('connectors.removed'), { variant: 'success' });
    } catch {
        toasts.push(t('connectors.actionFailed'), { variant: 'danger' });
    } finally {
        removing.value = null;
    }
}

async function toggleTools(connector: ConnectorSummary): Promise<void> {
    if (expandedId.value === connector.id) {
        expandedId.value = null;

        return;
    }

    expandedId.value = connector.id;
    loadingToolsId.value = connector.id;

    try {
        await connectors.loadTools(connector.id);
    } catch {
        toasts.push(t('connectors.actionFailed'), { variant: 'danger' });
    } finally {
        loadingToolsId.value = null;
    }
}

async function classify(connector: ConnectorSummary, tool: ConnectorTool, classification: ToolClassification): Promise<void> {
    if (tool.id === null) {
        return;
    }

    try {
        await connectors.classifyTool(connector.id, tool.id, classification);
    } catch {
        toasts.push(t('connectors.actionFailed'), { variant: 'danger' });
    }
}

onMounted(() => {
    void connectors.load().catch(() => undefined);
});
</script>

<template>
    <HudPanel id="connectors" :title="t('connectors.title')" data-testid="connectors-panel">
        <template #actions>
            <span class="hud-label text-[0.6rem] text-muted" data-testid="connectors-summary">
                {{ t('header.connectorsSummary', { connected: connectors.connectedCount, errors: connectors.errorCount }) }}
            </span>
            <HudButton variant="ghost" data-testid="connectors-add" @click="openCreate">
                {{ t('connectors.add') }}
            </HudButton>
        </template>

        <div class="flex flex-col gap-4">
            <form
                v-if="formOpen"
                class="flex flex-col gap-3 rounded-sm border border-line/70 p-3"
                data-testid="connectors-form"
                @submit.prevent="submit"
            >
                <p class="hud-label text-[0.65rem] text-cyan">
                    {{ editing ? t('connectors.editTitle', { name: editing.name }) : t('connectors.createTitle') }}
                </p>
                <div class="grid gap-3 md:grid-cols-2">
                    <HudInput
                        v-model="form.name"
                        :label="t('connectors.name')"
                        :error="errors.name?.[0] ?? null"
                        required
                        data-testid="connectors-name"
                    />
                    <HudInput
                        v-model="form.url"
                        type="url"
                        :label="t('connectors.url')"
                        :placeholder="t('connectors.urlPlaceholder')"
                        :error="errors.url?.[0] ?? null"
                        required
                        data-testid="connectors-url"
                    />
                </div>
                <HudInput
                    v-model="form.credential"
                    type="password"
                    autocomplete="off"
                    :label="t('connectors.credential')"
                    :placeholder="editing?.has_credential ? t('connectors.credentialKeep') : t('connectors.credentialOptional')"
                    :error="errors.credential?.[0] ?? null"
                    data-testid="connectors-credential"
                />
                <p class="hud-label text-[0.6rem] text-muted">{{ t('connectors.urlHint') }}</p>
                <div class="flex justify-end gap-2">
                    <HudButton variant="ghost" data-testid="connectors-cancel" @click="closeForm">
                        {{ t('common.cancel') }}
                    </HudButton>
                    <HudButton type="submit" variant="primary" :loading="saving" data-testid="connectors-submit">
                        {{ t('connectors.save') }}
                    </HudButton>
                </div>
            </form>

            <p
                v-if="connectors.loaded && connectors.connectors.length === 0"
                class="hud-label text-[0.65rem] text-muted"
                data-testid="connectors-empty"
            >
                {{ t('connectors.empty') }}
            </p>

            <ul class="grid gap-3 md:grid-cols-2" data-testid="connectors-list">
                <li
                    v-for="connector in connectors.connectors"
                    :key="connector.id"
                    class="flex min-w-0 flex-col gap-2 rounded-sm border border-line/70 bg-bg-navy/40 p-3"
                    :data-testid="`connector-${connector.id}`"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-text">{{ connector.name }}</p>
                            <p class="hud-label truncate text-[0.6rem] text-muted">
                                {{ connector.scope === 'global' ? t('connectors.scopeGlobal') : t('connectors.scopePersonal') }}
                                · {{ t('connectors.toolsCount', { count: connector.tools_count, active: connector.active_tools_count }) }}
                            </p>
                        </div>
                        <StatusBadge :status="connector.is_active ? connector.status : 'disconnected'" data-testid="connector-status" />
                    </div>

                    <p class="hud-label text-[0.6rem] text-muted" data-testid="connector-last-sync">
                        {{ t('connectors.lastSynced', { date: formatDate(connector.last_synced_at) }) }}
                    </p>
                    <p
                        v-if="connector.status === 'error' && connector.last_error"
                        class="text-xs break-words text-danger"
                        role="alert"
                        data-testid="connector-error"
                    >
                        {{ connector.last_error }}
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <HudButton variant="ghost" :data-testid="`connector-tools-${connector.id}`" @click="toggleTools(connector)">
                            {{ expandedId === connector.id ? t('connectors.hideTools') : t('connectors.showTools') }}
                        </HudButton>
                        <template v-if="connector.can_manage && connector.scope === 'personal'">
                            <HudButton variant="ghost" :data-testid="`connector-resync-${connector.id}`" @click="resync(connector)">
                                {{ t('connectors.resync') }}
                            </HudButton>
                            <HudButton variant="ghost" :data-testid="`connector-edit-${connector.id}`" @click="openEdit(connector)">
                                {{ t('connectors.edit') }}
                            </HudButton>
                            <HudButton variant="danger" :data-testid="`connector-remove-${connector.id}`" @click="removing = connector">
                                {{ t('connectors.remove') }}
                            </HudButton>
                        </template>
                    </div>

                    <div v-if="expandedId === connector.id" class="border-t border-line/70 pt-2" :data-testid="`connector-tool-list-${connector.id}`">
                        <p v-if="loadingToolsId === connector.id" class="hud-label text-[0.6rem] text-muted">
                            {{ t('common.loading') }}
                        </p>
                        <p
                            v-else-if="(connectors.toolsByConnector[connector.id] ?? []).length === 0"
                            class="hud-label text-[0.6rem] text-muted"
                        >
                            {{ t('connectors.noTools') }}
                        </p>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="tool in connectors.toolsByConnector[connector.id]"
                                :key="tool.definition_id"
                                class="flex flex-col gap-1 rounded-sm border border-line/50 p-2"
                                :data-testid="`connector-tool-${tool.definition_id}`"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate font-mono text-xs text-cyan-bright">{{ tool.name }}</span>
                                    <span v-if="!tool.is_available" class="hud-label text-[0.6rem] text-danger">
                                        {{ t('connectors.toolUnavailable') }}
                                    </span>
                                </div>
                                <p v-if="tool.description" class="text-xs text-muted">{{ tool.description }}</p>
                                <div class="flex flex-wrap gap-4">
                                    <HudToggle
                                        :model-value="tool.is_write"
                                        :label="t('connectors.toolWrite')"
                                        :disabled="!connector.can_classify"
                                        @update:model-value="(value: boolean) => classify(connector, tool, { is_write: value })"
                                    />
                                    <HudToggle
                                        :model-value="tool.is_sensitive"
                                        :label="t('connectors.toolSensitive')"
                                        :disabled="!connector.can_classify"
                                        @update:model-value="(value: boolean) => classify(connector, tool, { is_sensitive: value })"
                                    />
                                    <HudToggle
                                        :model-value="tool.is_active"
                                        :label="t('connectors.toolActive')"
                                        :disabled="!connector.can_classify || (!tool.is_available && !tool.is_active)"
                                        @update:model-value="(value: boolean) => classify(connector, tool, { is_active: value })"
                                    />
                                </div>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </div>

        <HudModal v-model:open="removeOpen" :title="t('connectors.removeTitle')" data-testid="connectors-remove-modal">
            <p class="text-sm text-text">{{ t('connectors.removeBody', { name: removing?.name ?? '' }) }}</p>
            <template #footer>
                <HudButton variant="ghost" data-testid="connectors-remove-cancel" @click="removing = null">
                    {{ t('common.cancel') }}
                </HudButton>
                <HudButton variant="danger" data-testid="connectors-remove-confirm" @click="confirmRemove">
                    {{ t('connectors.remove') }}
                </HudButton>
            </template>
        </HudModal>
    </HudPanel>
</template>
