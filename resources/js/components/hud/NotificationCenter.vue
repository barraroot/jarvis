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
            class="chamfer-sm corner-light relative inline-flex size-11 items-center justify-center border border-ocean-600/40 text-text-400 hover:text-biolum-200 xl:size-10"
            :aria-label="t('header.notifications')"
            :aria-expanded="open"
            data-testid="header-notifications"
            @click="toggle"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-[18px]" aria-hidden="true">
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
            </svg>
            <span
                v-if="notifications.unreadCount > 0"
                class="absolute right-0.5 top-0.5 min-w-4 bg-danger-400 px-1 text-center font-mono text-[10px] font-semibold leading-4 text-white"
                data-testid="header-notifications-count"
            >
                {{ notifications.unreadCount }}
            </span>
        </button>

        <div
            v-if="open"
            class="glass chamfer-md absolute right-0 top-12 z-50 flex max-h-[70vh] w-80 flex-col overflow-hidden"
            data-testid="notification-center"
        >
            <header class="flex items-center justify-between gap-2 border-b border-ocean-600/50 px-3 py-2">
                <h2 class="label-caps text-xs text-biolum-200">{{ t('notifications.title') }}</h2>
                <button
                    type="button"
                    class="label-caps min-h-11 text-[11px] text-cyan-300 hover:text-biolum-200 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="notifications.unreadCount === 0"
                    data-testid="notification-mark-all"
                    @click="notifications.markAllRead()"
                >
                    {{ t('notifications.markAll') }}
                </button>
            </header>

            <div v-if="notifications.loading" class="label-caps px-3 py-8 text-center text-[11px] text-text-400">
                {{ t('common.loading') }}
            </div>
            <EmptyState v-else-if="notifications.items.length === 0" :message="t('notifications.empty')" />

            <ul v-else class="flex min-w-0 flex-col overflow-y-auto" data-testid="notification-list">
                <li v-for="notification in notifications.items" :key="notification.id" class="border-b border-ocean-600/30 last:border-none">
                    <button
                        type="button"
                        class="flex w-full min-w-0 flex-col gap-0.5 px-3 py-2 text-left hover:bg-cyan-300/5"
                        :class="notification.read_at === null ? 'bg-cyan-300/[0.04]' : 'opacity-70'"
                        :data-unread="notification.read_at === null"
                        :data-testid="`notification-item-${notification.id}`"
                        @click="openNotification(notification)"
                    >
                        <span class="flex items-center gap-2">
                            <span
                                v-if="notification.read_at === null"
                                class="notification-center__dot size-1.5 shrink-0 bg-cyan-300"
                                data-dot
                                data-testid="notification-unread-dot"
                            ></span>
                            <span class="label-caps min-w-0 flex-1 truncate text-[11px] text-text-100">{{ notification.title }}</span>
                            <span class="shrink-0 font-mono text-[10px] text-text-400">{{ formatTime(notification.created_at) }}</span>
                        </span>
                        <span v-if="notification.body" class="min-w-0 truncate pl-3.5 text-xs text-text-400">
                            {{ notification.body }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.notification-center__dot {
    border-radius: 50%;
}
</style>
