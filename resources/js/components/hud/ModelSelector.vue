<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import HudSelect, { type HudSelectOption } from '@/components/ui/HudSelect.vue';
import { HttpError } from '@/lib/http';
import { useConversationsStore } from '@/stores/conversations';
import { useModelsStore } from '@/stores/models';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    conversationId: string;
    /** Model pinned to the conversation; null falls back to the brain default. */
    modelId: string | null;
}>();

const { t } = useI18n();
const models = useModelsStore();
const conversations = useConversationsStore();
const toasts = useToastStore();

const selected = ref('');
const error = ref<string | null>(null);
const saving = ref(false);

const options = computed<HudSelectOption[]>(() =>
    models.models.map((model) => ({
        value: model.id,
        label: model.is_default ? `${model.name} · ${t('models.default')}` : model.name,
    })),
);

/** The current selection: the conversation model, or the brain default. */
const currentValue = computed(() => props.modelId ?? models.defaultModel?.id ?? '');

watch(
    currentValue,
    (value) => {
        selected.value = value;
    },
    { immediate: true },
);

onMounted(() => {
    models.load().catch(() => {
        error.value = t('models.loadError');
    });
});

async function onChange(value: string): Promise<void> {
    if (value === '' || value === currentValue.value) {
        return;
    }

    error.value = null;
    saving.value = true;

    try {
        await conversations.setModel(props.conversationId, value);
    } catch (caught) {
        // The model was deactivated between the listing and this choice.
        if (caught instanceof HttpError && caught.status === 422) {
            error.value = t('models.chooseError');
        } else {
            toasts.push(t('errors.network'), { variant: 'danger' });
        }
    } finally {
        saving.value = false;
    }
}

watch(selected, (value) => {
    void onChange(value);
});
</script>

<template>
    <div class="flex w-56 min-w-0 shrink-0 flex-col" data-testid="model-selector">
        <HudSelect
            v-model="selected"
            :options="options"
            :label="t('models.label')"
            :disabled="saving || models.loading"
            :error="error"
        />
    </div>
</template>
