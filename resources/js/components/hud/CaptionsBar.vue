<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { buildCaption } from '@/lib/voice/captions';
import { useVoiceStore } from '@/stores/voice';

const { t } = useI18n();
const voice = useVoiceStore();
const reducedMotion = useReducedMotion();

/** Reduced motion reveals the whole JARVIS line at once (RNF-06). */
const assistantTokens = computed(() =>
    buildCaption(voice.assistantText, reducedMotion.value ? Number.POSITIVE_INFINITY : voice.spokenWordCount),
);

const hasCaptions = computed(() => voice.userCaption !== '' || assistantTokens.value.length > 0);
</script>

<template>
    <section
        v-if="hasCaptions"
        class="flex w-full flex-col gap-1.5 px-3 py-2 font-mono text-sm"
        :aria-label="t('captions.label')"
        aria-live="polite"
        data-testid="captions-bar"
    >
        <p v-if="voice.userCaption !== ''" class="flex min-w-0 items-baseline gap-3" data-testid="captions-user">
            <span class="hud-label shrink-0 text-[0.6rem] text-amber">{{ t('captions.user') }}</span>
            <span class="min-w-0 truncate text-text/80" data-testid="captions-user-text">{{ voice.userCaption }}</span>
        </p>

        <p v-if="assistantTokens.length > 0" class="flex min-w-0 items-baseline gap-3" data-testid="captions-assistant">
            <span class="hud-label shrink-0 text-[0.6rem] text-cyan">{{ t('captions.assistant') }}</span>
            <span class="flex min-w-0 flex-wrap items-baseline gap-x-1.5 gap-y-0.5 text-cyan-bright">
                <template v-for="token in assistantTokens" :key="token.start">
                    <span
                        v-if="token.kind === 'tag'"
                        class="hud-label rounded-sm border border-cyan/50 px-1 text-[0.55rem] text-cyan"
                        :class="token.spoken ? 'opacity-100' : 'opacity-40'"
                        :data-spoken="token.spoken"
                        data-testid="captions-tag"
                    >{{ token.text }}</span>
                    <span
                        v-else
                        :class="[token.spoken ? 'opacity-100' : 'opacity-35', reducedMotion ? '' : 'transition-opacity duration-150']"
                        :data-spoken="token.spoken"
                        data-testid="captions-word"
                    >{{ token.text }}</span>
                </template>
            </span>
        </p>
    </section>
</template>
