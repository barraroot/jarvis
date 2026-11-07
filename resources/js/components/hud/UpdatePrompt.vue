<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import { usePwaStore } from '@/stores/pwa';

/**
 * Service worker update prompt (PWA), in the abyss glass with a chamfer (UI-01);
 * on mobile it floats above the voice strip and the bottom navigation (UI-23).
 */
const pwa = usePwaStore();
const { t } = useI18n();
</script>

<template>
    <div
        v-if="pwa.needRefresh"
        class="glass chamfer-md fixed inset-x-3 bottom-72 z-[60] flex flex-col gap-3 border-cyan-300/60 p-4 text-sm sm:inset-x-auto sm:right-4 sm:w-96 md:bottom-28"
        role="alertdialog"
        aria-live="assertive"
        :aria-label="t('pwa.updateTitle')"
        data-testid="pwa-update-prompt"
    >
        <p class="label-caps flex items-center gap-2 text-xs text-biolum-200">
            <span class="size-1.5 rounded-full bg-cyan-300" data-dot aria-hidden="true"></span>
            {{ t('pwa.updateTitle') }}
        </p>
        <p class="text-text-100">{{ t('pwa.updateBody') }}</p>
        <div class="flex flex-wrap justify-end gap-2">
            <HudButton variant="ghost" data-testid="pwa-update-dismiss" @click="pwa.dismissUpdate">
                {{ t('pwa.updateLater') }}
            </HudButton>
            <HudButton variant="primary" data-testid="pwa-update-apply" @click="pwa.applyUpdate">
                {{ t('pwa.updateApply') }}
            </HudButton>
        </div>
    </div>
</template>
