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

/** The partial transcription keeps a caret while the operator is still speaking (UI-10). */
const userSpeaking = computed(() => voice.phase === 'user_speaking');
</script>

<template>
    <section
        v-if="hasCaptions"
        class="flex w-full flex-col gap-1.5 px-3 py-2 font-sans text-caption"
        :aria-label="t('captions.label')"
        aria-live="polite"
        data-testid="captions-bar"
    >
        <p v-if="voice.userCaption !== ''" class="flex min-w-0 items-baseline gap-3" data-testid="captions-user">
            <span class="label-caps shrink-0 text-label text-amber-400">{{ t('captions.you') }}</span>
            <span class="min-w-0 truncate text-amber-400" data-testid="captions-user-text">{{ voice.userCaption }}</span>
            <span
                v-if="userSpeaking"
                class="captions__caret inline-block h-[1em] w-0.5 shrink-0 self-center bg-amber-400"
                :class="{ 'captions__caret--blink': !reducedMotion }"
                aria-hidden="true"
                data-testid="captions-caret"
            ></span>
        </p>

        <p v-if="assistantTokens.length > 0" class="flex min-w-0 items-baseline gap-3" data-testid="captions-assistant">
            <span class="label-caps shrink-0 text-label text-cyan-300">{{ t('captions.jarvis') }}</span>
            <span class="flex min-w-0 flex-wrap items-baseline gap-x-1.5 gap-y-0.5 text-cyan-300">
                <template v-for="token in assistantTokens" :key="token.start">
                    <span
                        v-if="token.kind === 'tag'"
                        class="label-caps chamfer-sm self-center border border-cyan-300/50 bg-cyan-300/10 px-2 py-0.5 text-[0.65rem] text-cyan-300"
                        :class="token.spoken ? 'opacity-100' : 'opacity-30'"
                        :data-spoken="token.spoken"
                        data-testid="captions-tag"
                    >{{ token.text }}</span>
                    <span
                        v-else
                        :class="[token.spoken ? 'opacity-100' : 'opacity-30', reducedMotion ? '' : 'transition-opacity duration-150']"
                        :data-spoken="token.spoken"
                        data-testid="captions-word"
                    >{{ token.text }}</span>
                </template>
            </span>
        </p>
    </section>
</template>

<style scoped>
/* Caret of the partial VOCÊ transcription; static under reduced motion. */
.captions__caret--blink {
    animation: captions-caret 1s steps(2, start) infinite;
}

@keyframes captions-caret {
    to {
        opacity: 0;
    }
}
</style>
