<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

withDefaults(
    defineProps<{
        showDate?: boolean;
    }>(),
    { showDate: true },
);

const { t, locale } = useI18n();
const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | null = null;

const pad = (value: number): string => String(value).padStart(2, '0');

const time = computed(() => `${pad(now.value.getHours())}:${pad(now.value.getMinutes())}:${pad(now.value.getSeconds())}`);

const date = computed(() =>
    new Intl.DateTimeFormat(locale.value, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
        .format(now.value)
        .replace(/\./g, ''),
);

onMounted(() => {
    timer = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});
</script>

<template>
    <div class="flex min-w-0 flex-col font-mono" role="timer" :aria-label="t('clock.label')">
        <time class="text-2xl tracking-widest text-cyan-bright hud-glow tabular-nums sm:text-3xl" data-testid="clock-time">
            {{ time }}
        </time>
        <span v-if="showDate" class="hud-label truncate text-[0.6rem] text-muted" data-testid="clock-date">{{ date }}</span>
    </div>
</template>
