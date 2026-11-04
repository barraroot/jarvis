<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { VISUALIZATION_TYPING_MAX_MS, VISUALIZATION_TYPING_MS_PER_CHAR } from '@/components/hud/constants';
import { useReducedMotion } from '@/composables/useReducedMotion';
import type { VisualizationTextSection } from '@/types/models';

interface TextSegment {
    kind: 'text' | 'link';
    text: string;
    href: string | null;
}

const props = withDefaults(
    defineProps<{
        section: VisualizationTextSection;
        /** New sections are typed in; restored ones appear at once (UI-12). */
        animate?: boolean;
    }>(),
    { animate: false },
);

const reducedMotion = useReducedMotion();

const URL_PATTERN = /https?:\/\/[^\s<>"']+/gi;
const TRAILING_PUNCTUATION = /[.,;:!?)\]]+$/;

/** Only absolute http(s) URLs become links; everything else stays text (RF-20). */
function safeHref(candidate: string): string | null {
    try {
        const url = new URL(candidate);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

function segment(text: string): TextSegment[] {
    const segments: TextSegment[] = [];
    let cursor = 0;

    for (const match of text.matchAll(URL_PATTERN)) {
        const start = match.index ?? 0;
        const raw = match[0].replace(TRAILING_PUNCTUATION, '');
        const href = safeHref(raw);

        if (href === null || raw === '') {
            continue;
        }

        if (start > cursor) {
            segments.push({ kind: 'text', text: text.slice(cursor, start), href: null });
        }

        segments.push({ kind: 'link', text: raw, href });
        cursor = start + raw.length;
    }

    if (cursor < text.length) {
        segments.push({ kind: 'text', text: text.slice(cursor), href: null });
    }

    return segments;
}

const typed = ref(props.animate ? 0 : props.section.text.length);
let timer: ReturnType<typeof setInterval> | null = null;

function stopTyping(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function startTyping(): void {
    stopTyping();

    const total = props.section.text.length;

    if (reducedMotion.value || typed.value >= total) {
        typed.value = total;

        return;
    }

    const remaining = total - typed.value;
    const step = Math.max(1, Math.ceil(remaining / (VISUALIZATION_TYPING_MAX_MS / VISUALIZATION_TYPING_MS_PER_CHAR)));

    timer = setInterval(() => {
        typed.value = Math.min(total, typed.value + step);

        if (typed.value >= total) {
            stopTyping();
        }
    }, VISUALIZATION_TYPING_MS_PER_CHAR);
}

/** An updated text keeps the typed prefix when it only grew; otherwise it restarts. */
watch(
    () => props.section.text,
    (next, previous) => {
        if (!next.startsWith(previous.slice(0, typed.value))) {
            typed.value = 0;
        }

        startTyping();
    },
);

watch(reducedMotion, (reduced) => {
    if (reduced) {
        stopTyping();
        typed.value = props.section.text.length;
    }
});

onMounted(startTyping);
onBeforeUnmount(stopTyping);

/** Segments cut at the typed length; interpolated as text, never as HTML. */
const visibleSegments = computed<TextSegment[]>(() => {
    let budget = typed.value;
    const visible: TextSegment[] = [];

    for (const part of segment(props.section.text)) {
        if (budget <= 0) {
            break;
        }

        visible.push({ ...part, text: part.text.slice(0, budget) });
        budget -= part.text.length;
    }

    return visible;
});

const typing = computed(() => typed.value < props.section.text.length);
</script>

<template>
    <p class="whitespace-pre-wrap break-words text-sm text-text" :data-typing="typing" data-testid="viz-text">
        <template v-for="(part, index) in visibleSegments" :key="index">
            <a
                v-if="part.kind === 'link' && part.href !== null"
                :href="part.href"
                target="_blank"
                rel="noopener noreferrer"
                class="text-cyan-bright underline decoration-cyan/50 underline-offset-2 hover:text-cyan"
                data-testid="viz-text-link"
            >{{ part.text }}</a>
            <span v-else>{{ part.text }}</span>
        </template>
        <span v-if="typing" class="ml-0.5 inline-block h-3 w-1.5 animate-pulse bg-cyan-bright align-middle" aria-hidden="true"></span>
    </p>
</template>
