<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';
import Clock from '@/components/ui/Clock.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useConnectionStore } from '@/stores/connection';
import { usePersistentFlag } from '@/composables/usePersistentFlag';
import { SIDEBAR_COLLAPSED_STORAGE_KEY } from '@/layouts/constants';

const emit = defineEmits<{
    microphone: [];
}>();

const { t } = useI18n();
const route = useRoute();
const connection = useConnectionStore();

/** Sending (text, voice) is blocked while the realtime link is down. */
const sendingDisabled = computed(() => connection.isDisconnected);

/** Tablet (768–1279px) collapse state, persisted between visits. */
const sidebarCollapsed = usePersistentFlag(SIDEBAR_COLLAPSED_STORAGE_KEY);
/** Mobile (<768px) drawer, transient. */
const drawerOpen = ref(false);

watch(
    () => route.fullPath,
    () => {
        drawerOpen.value = false;
    },
);

const sidebarClasses = computed(() => [
    drawerOpen.value ? 'flex' : 'hidden',
    sidebarCollapsed.value ? 'md:hidden xl:flex' : 'md:flex',
]);

const offsetClasses = computed(() => (sidebarCollapsed.value ? 'xl:pl-80' : 'md:pl-80'));

function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value;
}

function openDrawer(): void {
    drawerOpen.value = true;
}

const navItems = computed(() => [
    { key: 'chat', label: t('nav.chat'), to: { name: 'hud' } },
    { key: 'agents', label: t('nav.agents'), to: null },
    { key: 'tasks', label: t('nav.tasks'), to: null },
    { key: 'settings', label: t('nav.settings'), to: { name: 'settings' } },
]);
</script>

<template>
    <div class="hud-grid relative min-h-dvh w-full overflow-x-hidden bg-bg text-text" data-testid="hud-layout">
        <header
            class="hud-glass fixed inset-x-0 top-0 z-40 flex h-14 items-center gap-3 border-b border-line px-3 sm:px-5"
            data-testid="layout-header"
        >
            <button
                type="button"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm border border-line text-cyan md:hidden"
                :aria-label="drawerOpen ? t('nav.closeMenu') : t('nav.openMenu')"
                :aria-expanded="drawerOpen"
                data-testid="drawer-toggle"
                @click="drawerOpen = !drawerOpen"
            >
                <span class="flex w-4 flex-col gap-1">
                    <span class="h-px w-full bg-current"></span>
                    <span class="h-px w-full bg-current"></span>
                    <span class="h-px w-full bg-current"></span>
                </span>
            </button>
            <button
                type="button"
                class="hidden size-9 shrink-0 items-center justify-center rounded-sm border border-line text-cyan md:inline-flex xl:hidden"
                :aria-label="t('nav.toggleSidebar')"
                :aria-expanded="!sidebarCollapsed"
                data-testid="sidebar-toggle"
                @click="toggleSidebar"
            >
                <span class="block h-4 w-3 border-l-2 border-current"></span>
            </button>
            <slot name="header">
                <span class="hud-label min-w-0 truncate font-display text-sm text-cyan hud-glow">{{ t('app.name') }}</span>
                <div class="ml-auto hidden sm:block">
                    <Clock :show-date="false" />
                </div>
            </slot>
        </header>

        <div
            v-if="drawerOpen"
            class="fixed inset-0 top-14 z-20 bg-bg/70 md:hidden"
            data-testid="drawer-backdrop"
            @click="drawerOpen = false"
        ></div>

        <aside
            class="hud-glass fixed bottom-0 left-0 top-14 z-30 w-[min(20rem,85vw)] flex-col gap-4 overflow-y-auto border-r border-line p-4 md:w-80"
            :class="sidebarClasses"
            :aria-label="t('layout.sidebar')"
            data-testid="layout-sidebar"
        >
            <slot name="sidebar">
                <EmptyState />
            </slot>
        </aside>

        <div class="flex min-h-dvh min-w-0 flex-col pt-14" :class="offsetClasses">
            <div
                v-if="sendingDisabled"
                class="sticky top-14 z-20 border-b border-danger/60 bg-danger/15 px-4 py-2 text-center text-xs text-danger"
                role="alert"
                data-testid="disconnected-banner"
            >
                {{ t('layout.disconnectedBanner') }}
                <span v-if="connection.nextRetryDelayMs !== null" class="ml-2 text-muted">
                    {{ t('layout.reconnecting', { seconds: Math.ceil(connection.nextRetryDelayMs / 1000) }) }}
                </span>
            </div>
            <slot name="banner" />

            <main
                class="min-w-0 flex-1 px-3 pt-4 pb-40 sm:px-5 md:pb-28"
                :aria-label="t('layout.main')"
                data-testid="layout-main"
            >
                <slot name="main">
                    <EmptyState />
                </slot>
            </main>
        </div>

        <footer
            class="fixed inset-x-0 bottom-0 z-30 flex flex-col"
            :class="offsetClasses"
            :aria-label="t('layout.footer')"
            data-testid="layout-footer"
        >
            <fieldset
                class="hud-glass min-w-0 border-t border-line px-3 py-3 disabled:opacity-60 sm:px-5"
                :disabled="sendingDisabled"
                data-testid="layout-footer-controls"
            >
                <slot name="footer" :sending-disabled="sendingDisabled">
                    <EmptyState />
                </slot>
            </fieldset>

            <nav
                class="hud-glass grid grid-cols-5 items-end border-t border-line px-1 pb-[env(safe-area-inset-bottom)] md:hidden"
                data-testid="bottom-nav"
            >
                <template v-for="(item, index) in navItems" :key="item.key">
                    <button
                        v-if="index === 2"
                        type="button"
                        class="mx-auto -mt-5 mb-1 inline-flex size-14 items-center justify-center rounded-full border-2 border-cyan bg-bg-navy text-cyan-bright shadow-[0_0_18px_rgba(34,211,238,0.55)]"
                        :aria-label="t('nav.microphone')"
                        data-testid="bottom-nav-microphone"
                        :disabled="sendingDisabled"
                        @click="emit('microphone')"
                    >
                        <span class="block h-5 w-3 rounded-full border-2 border-current"></span>
                    </button>
                    <RouterLink
                        v-if="item.to"
                        :to="item.to"
                        class="hud-label flex min-w-0 flex-col items-center truncate py-3 text-[0.6rem] text-muted"
                        active-class="text-cyan-bright"
                        :data-testid="`bottom-nav-${item.key}`"
                    >
                        {{ item.label }}
                    </RouterLink>
                    <button
                        v-else
                        type="button"
                        class="hud-label flex min-w-0 flex-col items-center truncate py-3 text-[0.6rem] text-muted"
                        :data-testid="`bottom-nav-${item.key}`"
                        @click="openDrawer"
                    >
                        {{ item.label }}
                    </button>
                </template>
            </nav>
        </footer>
    </div>
</template>
