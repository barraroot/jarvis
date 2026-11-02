<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import { usePwaStore } from '@/stores/pwa';
import { useToastStore } from '@/stores/toast';

const pwa = usePwaStore();
const toasts = useToastStore();
const { t } = useI18n();

onMounted(() => {
    pwa.captureInstallPrompt();
});

/** "Instalar JARVIS" (US-2.5): the native prompt captured from `beforeinstallprompt`. */
async function install(): Promise<void> {
    const outcome = await pwa.promptInstall();

    if (outcome === 'accepted') {
        toasts.push(t('pwa.installAccepted'), { variant: 'success' });
    }
}
</script>

<template>
    <HudPanel
        v-if="pwa.canInstall || pwa.showIosInstructions || pwa.installed"
        :title="t('pwa.installTitle')"
        data-testid="install-app-panel"
    >
        <div class="flex flex-col gap-3">
            <p v-if="pwa.installed" class="text-sm text-text" data-testid="install-app-installed">
                {{ t('pwa.installed') }}
            </p>

            <template v-else-if="pwa.canInstall">
                <p class="text-sm text-text">{{ t('pwa.installBody') }}</p>
                <div class="flex justify-end">
                    <HudButton variant="primary" data-testid="install-app-button" @click="install">
                        {{ t('pwa.installButton') }}
                    </HudButton>
                </div>
            </template>

            <div v-else class="flex flex-col gap-2" data-testid="install-app-ios">
                <p class="text-sm text-text">{{ t('pwa.iosTitle') }}</p>
                <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm text-text">
                    <li>{{ t('pwa.iosStepShare') }}</li>
                    <li>{{ t('pwa.iosStepAdd') }}</li>
                    <li>{{ t('pwa.iosStepConfirm') }}</li>
                </ol>
            </div>
        </div>
    </HudPanel>
</template>
