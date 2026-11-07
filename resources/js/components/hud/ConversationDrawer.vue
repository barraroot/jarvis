<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import ModelSelector from '@/components/hud/ModelSelector.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudModal from '@/components/ui/HudModal.vue';
import { useConversationsStore } from '@/stores/conversations';
import { useToastStore } from '@/stores/toast';
import type { Conversation } from '@/types/models';

const open = defineModel<boolean>('open', { default: false });

const { t, locale } = useI18n();
const router = useRouter();
const store = useConversationsStore();
const toasts = useToastStore();

const renamingId = ref<string | null>(null);
const renameText = ref('');
const deleting = ref<Conversation | null>(null);
const creating = ref(false);

const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (isOpen) => {
        if (!isOpen) {
            deleting.value = null;
        }
    },
});

function close(): void {
    open.value = false;
    renamingId.value = null;
}

/** Empty-state action (UI-20, A7): close the drawer and focus the footer composer; the first send creates the conversation. */
function startConversation(): void {
    close();
    void nextTick(() => {
        document.querySelector<HTMLTextAreaElement>('[data-testid="composer"] textarea')?.focus();
    });
}

/** The empty state only shows once a load settled with no conversations (never while loading). */
const showEmpty = computed(() => store.conversations.length === 0 && !store.loading);

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        if (renamingId.value !== null) {
            renamingId.value = null;
        } else {
            close();
        }
    }
}

function formatActivity(timestamp: string): string {
    const date = new Date(timestamp);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
        .format(date)
        .replace(/\./g, '');
}

function openConversation(id: string): void {
    close();
    void router.push({ name: 'conversation', params: { id } });
}

async function createConversation(): Promise<void> {
    if (creating.value) {
        return;
    }

    creating.value = true;

    try {
        const conversation = await store.create();
        close();
        void router.push({ name: 'conversation', params: { id: conversation.id } });
    } catch {
        toasts.push(t('drawer.createError'), { variant: 'danger' });
    } finally {
        creating.value = false;
    }
}

function startRename(id: string, currentTitle: string): void {
    renamingId.value = id;
    renameText.value = currentTitle;
}

async function saveRename(): Promise<void> {
    const id = renamingId.value;
    const title = renameText.value.trim();

    if (id === null || title.length === 0) {
        renamingId.value = null;

        return;
    }

    try {
        await store.rename(id, title);
        renamingId.value = null;
    } catch {
        toasts.push(t('drawer.renameError'), { variant: 'danger' });
    }
}

async function confirmDelete(): Promise<void> {
    if (deleting.value === null) {
        return;
    }

    const id = deleting.value.id;
    const wasActive = store.activeId !== null && String(store.activeId) === String(id);
    deleting.value = null;

    try {
        await store.remove(id);

        // Deleting the open conversation leaves the conversation view.
        if (wasActive) {
            await router.push({ name: 'hud' });
        }
    } catch {
        toasts.push(t('drawer.deleteError'), { variant: 'danger' });
    }
}

/** First open loads page 1 once; later opens only refresh after a failed start. */
watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        if (store.conversations.length === 0 && !store.loading) {
            store.list(1).catch(() => {
                // Until Phase 7 ships the API the drawer simply stays empty.
            });
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('keydown', onKeydown);
    } else {
        document.removeEventListener('keydown', onKeydown);
    }
});
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex justify-end" data-testid="conversation-drawer">
        <div class="absolute inset-0 bg-abyss-950/80 backdrop-blur-sm" data-testid="conversation-drawer-backdrop" @click="close"></div>

        <aside
            class="glass relative flex h-full w-[min(22rem,90vw)] flex-col border-l border-ocean-600"
            role="dialog"
            aria-modal="true"
            :aria-label="t('drawer.title')"
        >
            <header class="flex items-center justify-between gap-2 border-b border-ocean-600/70 p-4">
                <h2 class="label-caps truncate text-sm text-cyan-300 glow">{{ t('drawer.title') }}</h2>
                <button
                    type="button"
                    class="chamfer-sm flex min-h-11 min-w-11 items-center justify-center text-text-400 hover:text-biolum-200"
                    :aria-label="t('common.close')"
                    data-testid="conversation-drawer-close"
                    @click="close"
                >
                    &times;
                </button>
            </header>

            <div class="flex flex-col gap-3 p-3">
                <ModelSelector
                    v-if="store.active !== null"
                    :conversation-id="String(store.active.id)"
                    :model-id="store.active.ai_model_id ?? null"
                    data-testid="drawer-model-selector"
                />
                <HudButton variant="primary" block :loading="creating" data-testid="drawer-new" @click="createConversation">
                    {{ t('drawer.new') }}
                </HudButton>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-3 pb-3" data-testid="drawer-list">
                <EmptyState
                    v-if="showEmpty"
                    :title="t('edge.empty.conversations.title')"
                    :text="t('edge.empty.conversations.text')"
                >
                    <template #action>
                        <HudButton variant="primary" data-testid="drawer-empty-start" @click="startConversation">
                            {{ t('edge.empty.conversations.action') }}
                        </HudButton>
                    </template>
                </EmptyState>

                <ul v-else-if="store.conversations.length > 0" class="flex min-w-0 flex-col gap-1">
                    <li
                        v-for="conversation in store.conversations"
                        :key="conversation.id"
                        class="chamfer-sm corner-light flex min-w-0 items-center gap-2 border border-transparent px-2 py-1.5 hover:border-ocean-600"
                        data-testid="conversation-item"
                    >
                        <template v-if="renamingId === conversation.id">
                            <HudInput
                                v-model="renameText"
                                :label="t('drawer.renameLabel')"
                                data-testid="conversation-rename-input"
                                @keydown.enter.prevent="saveRename"
                                @keydown.esc.prevent="renamingId = null"
                            />
                            <HudButton variant="primary" data-testid="conversation-rename-save" @click="saveRename">
                                {{ t('common.confirm') }}
                            </HudButton>
                        </template>

                        <template v-else>
                            <button
                                type="button"
                                class="min-h-11 min-w-0 flex-1 text-left"
                                :aria-label="t('drawer.open')"
                                data-testid="conversation-open"
                                @click="openConversation(conversation.id)"
                            >
                                <span class="label-caps block truncate text-[0.65rem] text-text-100">{{ conversation.title }}</span>
                                <span class="block truncate font-mono text-[0.55rem] text-text-400">
                                    {{ formatActivity(conversation.last_activity_at) }}
                                </span>
                            </button>

                            <button
                                type="button"
                                class="chamfer-sm flex size-11 shrink-0 items-center justify-center border border-ocean-600 text-text-400 hover:border-cyan-300 hover:text-biolum-200"
                                :aria-label="t('drawer.rename')"
                                data-testid="conversation-rename"
                                @click="startRename(conversation.id, conversation.title)"
                            >
                                <span class="block h-3 w-2 border-l border-b border-current"></span>
                            </button>
                            <button
                                type="button"
                                class="chamfer-sm flex size-11 shrink-0 items-center justify-center border border-ocean-600 text-text-400 hover:border-danger-400 hover:text-danger-400"
                                :aria-label="t('drawer.delete')"
                                data-testid="conversation-delete"
                                @click="deleting = conversation"
                            >
                                &times;
                            </button>
                        </template>
                    </li>
                </ul>

                <div v-if="store.hasMorePages" class="mt-3">
                    <HudButton
                        variant="ghost"
                        block
                        :loading="store.loading"
                        data-testid="drawer-load-more"
                        @click="store.loadMore()"
                    >
                        {{ t('drawer.loadMore') }}
                    </HudButton>
                </div>
            </div>
        </aside>

        <HudModal v-model:open="deleteOpen" :title="t('drawer.deleteTitle')">
            <p class="font-mono text-sm text-text-100">{{ t('drawer.deleteMessage') }}</p>
            <template #footer>
                <HudButton variant="ghost" data-testid="drawer-delete-cancel" @click="deleting = null">
                    {{ t('common.cancel') }}
                </HudButton>
                <HudButton variant="danger" data-testid="drawer-delete-confirm" @click="confirmDelete">
                    {{ t('drawer.delete') }}
                </HudButton>
            </template>
        </HudModal>
    </div>
</template>
