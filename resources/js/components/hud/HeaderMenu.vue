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
            class="chamfer-sm corner-light inline-flex size-11 items-center justify-center border border-ocean-600/40 text-text-400 hover:text-biolum-200 xl:size-10"
            :aria-label="props.label ?? t('header.menu')"
            :aria-expanded="open"
            aria-haspopup="menu"
            :data-testid="props.idPrefix"
            @click="toggle"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-[18px]" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
        </button>

        <div
            v-if="open"
            class="glass chamfer-md absolute right-0 top-12 z-50 flex w-64 flex-col gap-1 p-2"
            role="menu"
            :data-testid="`${props.idPrefix}-menu`"
        >
            <div class="label-caps truncate px-2 py-1 text-[11px] text-biolum-200">{{ auth.user?.name ?? '' }}</div>
            <div class="truncate border-b border-ocean-600/50 px-2 pb-2 font-mono text-[11px] text-text-400">
                {{ auth.user?.email ?? '' }}
            </div>

            <slot name="extra" :close="close"></slot>

            <RouterLink
                :to="{ name: 'approvals' }"
                class="label-caps flex min-h-11 items-center gap-2 px-2 py-1.5 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200"
                role="menuitem"
                :data-testid="`${props.idPrefix}-approvals`"
                @click="close"
            >
                {{ t('header.approvals') }}
                <span
                    v-if="notifications.unreadCount > 0"
                    class="ml-auto min-w-4 bg-danger-400 px-1 text-center font-mono text-[10px] text-white"
                >
                    {{ notifications.unreadCount }}
                </span>
            </RouterLink>
            <RouterLink
                :to="{ name: 'memories' }"
                class="label-caps flex min-h-11 items-center gap-2 px-2 py-1.5 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200"
                role="menuitem"
                :data-testid="`${props.idPrefix}-memories`"
                @click="close"
            >
                {{ t('header.memories') }}
            </RouterLink>
            <RouterLink
                :to="{ name: 'activity' }"
                class="label-caps flex min-h-11 items-center gap-2 px-2 py-1.5 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200"
                role="menuitem"
                :data-testid="`${props.idPrefix}-activity`"
                @click="close"
            >
                {{ t('header.activity') }}
            </RouterLink>
            <RouterLink
                :to="{ name: 'settings' }"
                class="label-caps flex min-h-11 items-center gap-2 px-2 py-1.5 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200"
                role="menuitem"
                :data-testid="`${props.idPrefix}-settings`"
                @click="close"
            >
                {{ t('header.settings') }}
            </RouterLink>
            <button
                type="button"
                class="label-caps flex min-h-11 items-center gap-2 px-2 py-1.5 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200"
                role="menuitem"
                :data-testid="`${props.idPrefix}-logout`"
                @click="logout"
            >
                {{ t('header.logout') }}
            </button>
        </div>
    </div>
</template>
