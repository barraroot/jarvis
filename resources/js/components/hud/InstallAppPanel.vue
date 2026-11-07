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

/** iOS has no install prompt: the Safari steps, in order. */
const iosSteps = ['pwa.iosStepShare', 'pwa.iosStepAdd', 'pwa.iosStepConfirm'] as const;

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
            <p v-if="pwa.installed" class="flex items-center gap-2 text-sm text-text-100" data-testid="install-app-installed">
                <span class="size-1.5 shrink-0 bg-success-400" aria-hidden="true" data-dot></span>
                {{ t('pwa.installed') }}
            </p>

            <template v-else-if="pwa.canInstall">
                <p class="text-sm text-text-400">{{ t('pwa.installBody') }}</p>
                <div class="flex justify-end">
                    <HudButton variant="primary" data-testid="install-app-button" @click="install">
                        {{ t('pwa.installButton') }}
                    </HudButton>
                </div>
            </template>

            <div v-else class="flex flex-col gap-2" data-testid="install-app-ios">
                <p class="text-sm text-text-100">{{ t('pwa.iosTitle') }}</p>
                <ol class="flex flex-col gap-2 text-sm text-text-100">
                    <li
                        v-for="(step, index) in iosSteps"
                        :key="step"
                        class="chamfer-sm flex items-center gap-3 border border-ocean-600/70 bg-abyss-900/40 px-3 py-2"
                    >
                        <span class="font-mono text-xs text-cyan-300" aria-hidden="true">{{ String(index + 1).padStart(2, '0') }}</span>
                        <span class="min-w-0">{{ t(step) }}</span>
                    </li>
                </ol>
            </div>
        </div>
    </HudPanel>
</template>
