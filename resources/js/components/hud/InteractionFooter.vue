<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import HudTextarea from '@/components/ui/HudTextarea.vue';
import Waveform from '@/components/ui/Waveform.vue';
import { MESSAGE_COUNTER_THRESHOLD, MESSAGE_MAX_CHARS, MESSAGE_MAX_LINES } from '@/components/hud/constants';
import { useAssistantStore } from '@/stores/assistant';
import { useConnectionStore } from '@/stores/connection';

const props = withDefaults(
    defineProps<{
        /** True when the layout blocks sending (e.g. realtime link down). */
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    send: [payload: { content: string; source: 'text' | 'voice' }];
    voice: [];
    interrupt: [];
    command: [payload: { type: 'new' | 'remember' | 'tasks' | 'memories' }];
}>();

const { t } = useI18n();
const assistant = useAssistantStore();
const connection = useConnectionStore();

const text = ref('');
const commandsOpen = ref(false);
const rootRef = ref<HTMLDivElement | null>(null);

function focusComposer(): void {
    rootRef.value?.querySelector<HTMLTextAreaElement>('textarea')?.focus();
}

const voiceSupported =
    typeof window !== 'undefined' && ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

const capturing = computed(() => assistant.state === 'LISTENING' || assistant.state === 'SPEAKING');

const blocked = computed(() => props.disabled || connection.isDisconnected);

const overLimit = computed(() => text.value.length > MESSAGE_MAX_CHARS);

const showCounter = computed(() => text.value.length > MESSAGE_COUNTER_THRESHOLD);

const counterLabel = computed(() =>
    t('footer.characterCount', {
        count: text.value.length.toLocaleString('pt-BR'),
        limit: MESSAGE_MAX_CHARS.toLocaleString('pt-BR'),
    }),
);

const canSend = computed(() => text.value.trim().length > 0 && !overLimit.value && !blocked.value);

function submit(): void {
    const content = text.value.trim();

    if (content.length === 0 || overLimit.value || blocked.value) {
        return;
    }

    emit('send', { content, source: 'text' });
    text.value = '';
    commandsOpen.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Tab') {
        event.preventDefault();
        emit('voice');

        return;
    }

    if (event.key === 'Escape') {
        commandsOpen.value = false;
        emit('interrupt');

        return;
    }

    if (event.key === '/') {
        event.preventDefault();
        commandsOpen.value = true;
        focusComposer();

        return;
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}

function runCommand(type: 'new' | 'remember' | 'tasks' | 'memories'): void {
    if (type === 'new') {
        emit('command', { type });
        commandsOpen.value = false;

        return;
    }

    const prefixes: Record<'remember' | 'tasks' | 'memories', string> = {
        remember: `/${t('footer.commandRemember')} `,
        tasks: `/${t('footer.commandTasks')} `,
        memories: `/${t('footer.commandMemories')} `,
    };

    text.value = prefixes[type];
    commandsOpen.value = false;
    focusComposer();
}

const commands = computed(() => [
    { key: 'new', label: t('footer.commandNew'), action: () => runCommand('new') },
    { key: 'remember', label: t('footer.commandRemember'), action: () => runCommand('remember') },
    { key: 'tasks', label: t('footer.commandTasks'), action: () => runCommand('tasks') },
    { key: 'memories', label: t('footer.commandMemories'), action: () => runCommand('memories') },
]);
</script>

<template>
    <div ref="rootRef" class="relative flex min-w-0 flex-col gap-2" data-testid="interaction-footer" @keydown="onKeydown">
        <p v-if="connection.isDisconnected" class="hud-label text-center text-[0.6rem] text-danger" role="alert">
            {{ t('footer.offline') }}
        </p>

        <div v-if="capturing" class="mx-auto w-full max-w-md" data-testid="footer-capture">
            <Waveform :level="assistant.audioLevel" />
        </div>

        <div class="flex min-w-0 items-end gap-2">
            <div class="min-w-0 flex-1">
                <HudTextarea
                    v-model="text"
                    :placeholder="t('footer.placeholder')"
                    :disabled="blocked"
                    :maxlength="MESSAGE_MAX_CHARS"
                    :auto-expand="true"
                    :max-rows="MESSAGE_MAX_LINES"
                    :rows="1"
                    data-testid="composer"
                />
            </div>

            <HudButton
                variant="primary"
                :disabled="!canSend"
                :aria-label="t('footer.send')"
                data-testid="footer-send"
                @click="submit"
            >
                {{ t('footer.send') }}
            </HudButton>

            <HudButton
                variant="ghost"
                :disabled="!voiceSupported || blocked"
                :title="voiceSupported ? undefined : t('footer.voiceUnsupported')"
                :aria-label="t('footer.voice')"
                :aria-pressed="capturing"
                data-testid="footer-voice"
                @click="emit('voice')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4" aria-hidden="true">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                </svg>
            </HudButton>
        </div>

        <div class="flex min-w-0 items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <span v-if="showCounter" class="font-mono text-[0.6rem]" :class="overLimit ? 'text-danger' : 'text-muted'" data-testid="footer-counter">
                    {{ counterLabel }}
                </span>
                <span v-else-if="overLimit" class="hud-label text-[0.6rem] text-danger" role="alert">
                    {{ t('footer.limitReached') }}
                </span>
            </div>

            <div class="hidden items-center gap-3 font-mono text-[0.6rem] text-muted sm:flex" data-testid="footer-shortcuts">
                <span class="hud-label">{{ t('footer.shortcuts') }}</span>
                <span class="flex items-center gap-1">
                    <kbd class="rounded-sm border border-line px-1">{{ t('footer.keyVoice') }}</kbd>
                    {{ t('footer.shortcutVoice') }}
                </span>
                <span class="flex items-center gap-1">
                    <kbd class="rounded-sm border border-line px-1">{{ t('footer.keyInterrupt') }}</kbd>
                    {{ t('footer.shortcutInterrupt') }}
                </span>
                <span class="flex items-center gap-1">
                    <kbd class="rounded-sm border border-line px-1">{{ t('footer.keyCommands') }}</kbd>
                    {{ t('footer.shortcutCommands') }}
                </span>
            </div>

            <button
                type="button"
                class="hud-label flex items-center gap-1 rounded-sm border border-line px-2 py-1 text-[0.6rem] text-text hover:border-cyan hover:text-cyan-bright"
                :aria-label="t('footer.commandsOpen')"
                :aria-expanded="commandsOpen"
                data-testid="footer-commands-toggle"
                @click="commandsOpen = !commandsOpen"
            >
                <span class="font-mono">{{ t('footer.keyCommands') }}</span>
                {{ t('footer.commands') }}
            </button>
        </div>

        <div
            v-if="commandsOpen"
            class="hud-glass absolute bottom-full left-0 z-40 flex w-64 flex-col gap-1 rounded-sm border border-line p-2"
            data-testid="footer-commands-menu"
        >
            <button
                v-for="command in commands"
                :key="command.key"
                type="button"
                class="hud-label flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-[0.65rem] text-text hover:bg-cyan/10 hover:text-cyan-bright"
                :data-testid="`footer-command-${command.key}`"
                @click="command.action"
            >
                <span class="font-mono">{{ t('footer.keyCommands') }}</span>
                {{ command.label }}
            </button>
        </div>
    </div>
</template>
