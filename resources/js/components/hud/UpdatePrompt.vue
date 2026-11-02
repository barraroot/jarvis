<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import { usePwaStore } from '@/stores/pwa';

const pwa = usePwaStore();
const { t } = useI18n();
</script>

<template>
    <div
        v-if="pwa.needRefresh"
        class="hud-glass fixed inset-x-3 bottom-36 z-[60] flex flex-col gap-3 rounded-sm border border-cyan/60 bg-bg-navy/95 p-4 text-sm sm:inset-x-auto sm:right-4 sm:bottom-6 sm:w-96 md:bottom-28"
        role="alertdialog"
        aria-live="assertive"
        :aria-label="t('pwa.updateTitle')"
        data-testid="pwa-update-prompt"
    >
        <p class="hud-label text-xs text-cyan-bright">{{ t('pwa.updateTitle') }}</p>
        <p class="text-text">{{ t('pwa.updateBody') }}</p>
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
