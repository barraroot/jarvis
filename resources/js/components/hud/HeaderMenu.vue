<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';

const props = withDefaults(
    defineProps<{
        /** Prefix of every `data-testid`, so desktop and mobile menus stay distinguishable. */
        idPrefix?: string;
        /** Accessible name of the trigger button. */
        label?: string;
    }>(),
    { idPrefix: 'header-menu', label: undefined },
);

const { t } = useI18n();
const auth = useAuthStore();
const notifications = useNotificationsStore();

const open = ref(false);
const rootRef = ref<HTMLElement | null>(null);

function close(): void {
    open.value = false;
}

function toggle(): void {
    open.value = !open.value;
}

async function logout(): Promise<void> {
    close();
    await auth.logout();
}

function onDocumentClick(event: MouseEvent): void {
    if (rootRef.value !== null && !rootRef.value.contains(event.target as Node)) {
        close();
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

defineExpose({ close });
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            type="button"
            class="inline-flex size-11 items-center justify-center rounded-sm border border-line text-text hover:border-cyan hover:text-cyan-bright xl:size-9"
            :aria-label="props.label ?? t('header.menu')"
            :aria-expanded="open"
            aria-haspopup="menu"
            :data-testid="props.idPrefix"
            @click="toggle"
        >
            <svg viewBox="0 0 24 24" fill="currentColor" class="size-4" aria-hidden="true">
                <circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" />
            </svg>
        </button>

        <div
            v-if="open"
            class="hud-glass absolute right-0 top-11 z-50 flex w-60 flex-col gap-1 rounded-sm border border-line p-2"
            role="menu"
            :data-testid="`${props.idPrefix}-menu`"
        >
            <div class="hud-label truncate px-2 py-1 text-[0.6rem] text-cyan">{{ auth.user?.name ?? '' }}</div>
            <div class="truncate border-b border-line/70 px-2 pb-2 font-mono text-[0.6rem] text-muted">
                {{ auth.user?.email ?? '' }}
            </div>

            <slot name="extra" :close="close"></slot>

            <RouterLink
                :to="{ name: 'approvals' }"
                class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                role="menuitem"
                :data-testid="`${props.idPrefix}-approvals`"
                @click="close"
            >
                {{ t('header.approvals') }}
                <span
                    v-if="notifications.unreadCount > 0"
                    class="ml-auto min-w-4 rounded-full bg-danger px-1 text-center font-mono text-[0.55rem] text-bg"
                >
                    {{ notifications.unreadCount }}
                </span>
            </RouterLink>
            <RouterLink
                :to="{ name: 'memories' }"
                class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                role="menuitem"
                :data-testid="`${props.idPrefix}-memories`"
                @click="close"
            >
                {{ t('header.memories') }}
            </RouterLink>
            <RouterLink
                :to="{ name: 'activity' }"
                class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                role="menuitem"
                :data-testid="`${props.idPrefix}-activity`"
                @click="close"
            >
                {{ t('header.activity') }}
            </RouterLink>
            <RouterLink
                :to="{ name: 'settings' }"
                class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                role="menuitem"
                :data-testid="`${props.idPrefix}-settings`"
                @click="close"
            >
                {{ t('header.settings') }}
            </RouterLink>
            <button
                type="button"
                class="hud-label flex min-h-11 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                role="menuitem"
                :data-testid="`${props.idPrefix}-logout`"
                @click="logout"
            >
                {{ t('header.logout') }}
            </button>
        </div>
    </div>
</template>
