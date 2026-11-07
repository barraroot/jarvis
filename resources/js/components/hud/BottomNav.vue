<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';
import PushToTalkButton from '@/components/hud/PushToTalkButton.vue';
import { useApprovalsStore } from '@/stores/approvals';
import { useVoiceStore } from '@/stores/voice';

/**
 * Mobile navigation (UI-23, `board-11-mobile-pwa.html`): the `Digitar` ·
 * push-to-talk · `Dormir` strip (RF-06) above five tabs — HUD, Conversas
 * (drawer), Atividade, Aprovações (pending badge, hidden at 0) and Mais
 * (Memórias, Configurações). Every target is at least 44 × 44 px.
 */
withDefaults(
    defineProps<{
        /** Offline: the voice strip is disabled together with the composer. */
        disabled?: boolean;
    }>(),
    { disabled: false },
);

/** `compose` focuses the composer; the voice events match `AppHeader` (push-to-talk wakes, `Dormir` sleeps). */
const emit = defineEmits<{
    conversations: [];
    compose: [];
    sleep: [];
    wake: [];
    microphone: [];
}>();

const { t } = useI18n();
const route = useRoute();
const approvals = useApprovalsStore();
const voice = useVoiceStore();

const pendingApprovals = computed(() => approvals.items.filter((approval) => approval.status === 'pending').length);
const isSleeping = computed(() => voice.phase === 'sleeping');

const moreOpen = ref(false);
const moreActive = computed(() => route.name === 'memories' || route.name === 'settings');

function onDocumentClick(event: MouseEvent): void {
    const target = event.target as Element | null;

    if (target?.closest('[data-bottom-nav-more]') === null) {
        moreOpen.value = false;
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        moreOpen.value = false;
    }
}

watch(moreOpen, (open) => {
    if (open) {
        document.addEventListener('click', onDocumentClick);
        document.addEventListener('keydown', onKeydown);
    } else {
        document.removeEventListener('click', onDocumentClick);
        document.removeEventListener('keydown', onKeydown);
    }
});

watch(
    () => route.fullPath,
    () => {
        moreOpen.value = false;
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

const item =
    'label-caps relative flex min-h-11 min-w-11 flex-col items-center justify-center gap-1 truncate px-1 py-2 text-[10px] text-text-400 hover:text-biolum-200';
const activeItem = 'text-cyan-300';
const stripButton =
    'chamfer-sm corner-light label-caps inline-flex min-h-11 min-w-11 items-center justify-center border border-ocean-600/50 px-4 text-xs text-text-100 hover:text-biolum-200 disabled:cursor-not-allowed disabled:opacity-40';
const menuItem =
    'label-caps flex min-h-11 min-w-11 items-center px-3 text-left text-[11px] text-text-100 hover:bg-cyan-300/10 hover:text-biolum-200';
</script>

<template>
    <div class="flex flex-col md:hidden" data-testid="mobile-controls">
        <!-- Kept as `bottom-nav-microphone`: the voice strip that replaced the old central microphone. -->
        <fieldset
            class="flex min-w-0 items-center justify-center gap-5 px-3 pt-2 pb-3 disabled:opacity-60"
            :disabled="disabled"
            :aria-label="t('ptt.hold')"
            data-testid="bottom-nav-microphone"
        >
            <button type="button" :class="stripButton" data-testid="bottom-nav-compose" @click="emit('compose')">
                {{ t('ptt.type') }}
            </button>
            <PushToTalkButton @wake="emit('wake')" @microphone="emit('microphone')" />
            <button
                type="button"
                :class="stripButton"
                :disabled="!voice.available || isSleeping"
                data-testid="bottom-nav-sleep"
                @click="emit('sleep')"
            >
                {{ t('ptt.sleep') }}
            </button>
        </fieldset>

        <nav
            class="glass grid grid-cols-5 items-stretch border-x-0 border-b-0 px-1 pb-[env(safe-area-inset-bottom)] md:hidden"
            :aria-label="t('nav.navigation')"
            data-testid="bottom-nav"
        >
            <RouterLink :to="{ name: 'hud' }" :class="item" :exact-active-class="activeItem" data-testid="bottom-nav-hud">
                {{ t('nav.hud') }}
            </RouterLink>

            <button type="button" :class="item" data-testid="bottom-nav-conversations" @click="emit('conversations')">
                {{ t('nav.conversations') }}
            </button>

            <RouterLink :to="{ name: 'activity' }" :class="item" :active-class="activeItem" data-testid="bottom-nav-activity">
                {{ t('nav.activity') }}
            </RouterLink>

            <RouterLink :to="{ name: 'approvals' }" :class="item" :active-class="activeItem" data-testid="bottom-nav-approvals">
                {{ t('nav.approvals') }}
                <span
                    v-if="pendingApprovals > 0"
                    class="absolute top-0.5 right-1 min-w-4 bg-amber-400 px-1 text-center font-mono text-[10px] leading-4 font-semibold text-abyss-950"
                    :aria-label="t('nav.pendingApprovals', pendingApprovals)"
                    data-testid="bottom-nav-approvals-badge"
                >
                    {{ pendingApprovals }}
                </span>
            </RouterLink>

            <div class="relative flex" data-bottom-nav-more>
                <button
                    type="button"
                    class="w-full"
                    :class="[item, moreActive ? activeItem : '']"
                    aria-haspopup="menu"
                    :aria-expanded="moreOpen"
                    data-testid="bottom-nav-more"
                    @click="moreOpen = !moreOpen"
                >
                    {{ t('nav.more') }}
                </button>
                <div
                    v-if="moreOpen"
                    role="menu"
                    class="glass chamfer-sm absolute right-0 bottom-full z-50 mb-2 flex w-48 flex-col py-1"
                    data-testid="bottom-nav-more-menu"
                >
                    <RouterLink :to="{ name: 'memories' }" role="menuitem" :class="menuItem" data-testid="bottom-nav-memories">
                        {{ t('nav.memories') }}
                    </RouterLink>
                    <RouterLink :to="{ name: 'settings' }" role="menuitem" :class="menuItem" data-testid="bottom-nav-settings">
                        {{ t('pages.settings') }}
                    </RouterLink>
                </div>
            </div>
        </nav>
    </div>
</template>
