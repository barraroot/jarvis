<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';

withDefaults(
    defineProps<{
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    select: [key: 'agents' | 'tasks'];
    microphone: [];
}>();

const { t } = useI18n();
</script>

<template>
    <nav
        class="hud-glass grid grid-cols-5 items-end border-t border-line px-1 pb-[env(safe-area-inset-bottom)] md:hidden"
        data-testid="bottom-nav"
    >
        <RouterLink
            :to="{ name: 'hud' }"
            class="hud-label flex min-w-0 flex-col items-center truncate py-3 text-[0.6rem] text-muted"
            active-class="text-cyan-bright"
            data-testid="bottom-nav-chat"
        >
            {{ t('nav.chat') }}
        </RouterLink>

        <button
            type="button"
            class="hud-label flex min-w-0 flex-col items-center truncate py-3 text-[0.6rem] text-muted"
            data-testid="bottom-nav-agents"
            @click="emit('select', 'agents')"
        >
            {{ t('nav.agents') }}
        </button>

        <button
            type="button"
            class="mx-auto -mt-5 mb-1 inline-flex size-14 items-center justify-center rounded-full border-2 border-cyan bg-bg-navy text-cyan-bright shadow-[0_0_18px_rgba(34,211,238,0.55)] disabled:opacity-50"
            :aria-label="t('nav.microphone')"
            data-testid="bottom-nav-microphone"
            :disabled="disabled"
            @click="emit('microphone')"
        >
            <span class="block h-5 w-3 rounded-full border-2 border-current"></span>
        </button>

        <button
            type="button"
            class="hud-label flex min-w-0 flex-col items-center truncate py-3 text-[0.6rem] text-muted"
            data-testid="bottom-nav-tasks"
            @click="emit('select', 'tasks')"
        >
            {{ t('nav.tasks') }}
        </button>

        <RouterLink
            :to="{ name: 'settings' }"
            class="hud-label flex min-w-0 flex-col items-center truncate py-3 text-[0.6rem] text-muted"
            active-class="text-cyan-bright"
            data-testid="bottom-nav-settings"
        >
            {{ t('nav.settings') }}
        </RouterLink>
    </nav>
</template>
