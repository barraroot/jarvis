<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ResultData from '@/components/results/ResultData.vue';
import ResultDocument from '@/components/results/ResultDocument.vue';
import ResultExecutionStatus from '@/components/results/ResultExecutionStatus.vue';
import ResultList from '@/components/results/ResultList.vue';
import ResultReport from '@/components/results/ResultReport.vue';
import ResultSearchResults from '@/components/results/ResultSearchResults.vue';
import ResultTable from '@/components/results/ResultTable.vue';
import ResultTaskCard from '@/components/results/ResultTaskCard.vue';
import ResultText from '@/components/results/ResultText.vue';
import SuggestedActions from '@/components/results/SuggestedActions.vue';
import type { Message } from '@/types/models';
import type { ResultPayload, TextResultPayload } from '@/types/results';

const props = defineProps<{
    message: Message;
}>();

const { t } = useI18n();

const payload = computed<ResultPayload | null>(() => {
    const raw = props.message.payload;

    return raw !== null && typeof raw === 'object' && typeof (raw as { type?: unknown }).type === 'string'
        ? (raw as unknown as ResultPayload)
        : null;
});

const type = computed(() => payload.value?.type);

/** The backend flagged a fallback payload (`meta.result_invalid`): warn instead of hiding it. */
const isInvalidFallback = computed(() => {
    const current = payload.value;

    return current?.type === 'text' && (current as TextResultPayload).meta?.result_invalid === true;
});

const textPayload = computed<TextResultPayload>(() => {
    const current = payload.value;

    if (current?.type === 'text') {
        return current;
    }

    // Unknown payload shapes render whatever text they carry, or the streamed content.
    return { type: 'text', text: typeof (current as unknown as { text?: unknown } | null)?.text === 'string' ? (current as unknown as { text: string }).text : props.message.content };
});

/** Plain text replies render in the bubble itself; only the actions slot remains. */
const rendersText = computed(() => payload.value === null || payload.value.type === 'text');

const component = computed(() => {
    switch (type.value) {
        case 'list':
            return ResultList;
        case 'table':
            return ResultTable;
        case 'report':
            return ResultReport;
        case 'search_results':
            return ResultSearchResults;
        case 'task_card':
            return ResultTaskCard;
        case 'data':
            return ResultData;
        case 'execution_status':
            return ResultExecutionStatus;
        case 'document':
            return ResultDocument;
        default:
            return ResultText;
    }
});
</script>

<template>
    <div v-if="payload !== null" class="flex min-w-0 flex-col gap-2" data-testid="result-renderer">
        <p
            v-if="isInvalidFallback"
            class="label-caps flex items-center gap-1.5 font-mono text-[0.6rem] text-amber-400"
            data-testid="result-invalid-warning"
        >
            <span aria-hidden="true">⚠</span>
            {{ t('results.invalid') }}
        </p>

        <component :is="component" :payload="(rendersText ? textPayload : payload) as never" />

        <SuggestedActions v-if="payload.suggested_actions !== undefined && payload.suggested_actions.length > 0" :actions="payload.suggested_actions" />
    </div>
</template>
