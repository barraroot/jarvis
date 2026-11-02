<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useNotificationsStore } from '@/stores/notifications';
import { useTasksStore } from '@/stores/tasks';
import type { NotificationSummary } from '@/types/models';

const { t } = useI18n();
const router = useRouter();
const notifications = useNotificationsStore();
const tasks = useTasksStore();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function toggle(): void {
    open.value = !open.value;

    if (open.value) {
        void notifications.load();
    }
}

function close(): void {
    open.value = false;
}

function onDocumentClick(event: MouseEvent): void {
    if (root.value !== null && !root.value.contains(event.target as Node)) {
        close();
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

const time = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });

function formatTime(timestamp: string): string {
    const date = new Date(timestamp);

    return Number.isNaN(date.getTime()) ? timestamp : time.format(date);
}

/** Deep link via `data`: tasks open their detail sheet, approvals open the page. */
function openNotification(notification: NotificationSummary): void {
    if (notification.read_at === null) {
        void notifications.markRead(notification.id);
    }

    const data = notification.data;

    if (data?.task_id !== undefined && data.task_id !== null) {
        void tasks.openDetail(String(data.task_id));
    } else if (data?.approval_id !== undefined && data.approval_id !== null) {
        void router.push({ name: 'approvals' });
    }

    close();
}
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative inline-flex size-9 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright"
            :aria-label="t('header.notifications')"
            :aria-expanded="open"
            data-testid="header-notifications"
            @click="toggle"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
            </svg>
            <span
                v-if="notifications.unreadCount > 0"
                class="absolute -right-1 -top-1 min-w-4 rounded-full bg-danger px-1 text-center font-mono text-[0.55rem] leading-4 text-bg"
                data-testid="header-notifications-count"
            >
                {{ notifications.unreadCount }}
            </span>
        </button>

        <div
            v-if="open"
            class="hud-border hud-glass absolute right-0 top-11 z-50 flex max-h-[70vh] w-80 flex-col overflow-hidden rounded-sm bg-bg-navy/95"
            data-testid="notification-center"
        >
            <header class="flex items-center justify-between gap-2 border-b border-line/70 px-3 py-2">
                <h2 class="hud-label text-xs text-cyan hud-glow">{{ t('notifications.title') }}</h2>
                <button
                    type="button"
                    class="hud-label text-[0.6rem] text-cyan-bright hover:text-text disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="notifications.unreadCount === 0"
                    data-testid="notification-mark-all"
                    @click="notifications.markAllRead()"
                >
                    {{ t('notifications.markAll') }}
                </button>
            </header>

            <div v-if="notifications.loading" class="hud-label px-3 py-8 text-center text-muted">
                {{ t('common.loading') }}
            </div>
            <EmptyState v-else-if="notifications.items.length === 0" :message="t('notifications.empty')" />

            <ul v-else class="flex min-w-0 flex-col overflow-y-auto" data-testid="notification-list">
                <li v-for="notification in notifications.items" :key="notification.id" class="border-b border-line/40 last:border-none">
                    <button
                        type="button"
                        class="flex w-full min-w-0 flex-col gap-0.5 px-3 py-2 text-left hover:bg-cyan/5"
                        :class="notification.read_at === null ? 'bg-cyan/[0.04]' : 'opacity-70'"
                        :data-unread="notification.read_at === null"
                        :data-testid="`notification-item-${notification.id}`"
                        @click="openNotification(notification)"
                    >
                        <span class="flex items-center gap-2">
                            <span
                                v-if="notification.read_at === null"
                                class="size-1.5 shrink-0 rounded-full bg-cyan-bright"
                                data-testid="notification-unread-dot"
                            ></span>
                            <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ notification.title }}</span>
                            <span class="shrink-0 font-mono text-[0.55rem] text-muted">{{ formatTime(notification.created_at) }}</span>
                        </span>
                        <span v-if="notification.body" class="hud-label min-w-0 truncate pl-3.5 text-[0.6rem] text-muted">
                            {{ notification.body }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
