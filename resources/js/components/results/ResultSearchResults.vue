<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import HudPanel from '@/components/ui/HudPanel.vue';
import type { SearchResultHit, SearchResultsResultPayload } from '@/types/results';

defineProps<{
    payload: SearchResultsResultPayload;
}>();

const { t } = useI18n();

function isSafeUrl(url: string | undefined): url is string {
    return url !== undefined && /^https?:\/\//i.test(url);
}
</script>

<template>
    <HudPanel :title="`${t('results.searchResultsFor')} “${payload.query}”`" tag="div" data-testid="result-search-results">
        <div class="flex flex-col gap-2">
            <article
                v-for="(hit, index) in payload.results"
                :key="index"
                class="hud-glass flex min-w-0 flex-col gap-1 rounded-sm border border-line/60 px-3 py-2"
            >
                <a
                    v-if="isSafeUrl(hit.url)"
                    :href="hit.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="break-words font-mono text-xs text-cyan-bright underline decoration-cyan/40 hover:text-cyan"
                >
                    {{ hit.title }}
                </a>
                <span v-else class="break-words font-mono text-xs text-cyan-bright">{{ hit.title }}</span>
                <p class="break-words font-mono text-xs text-text/80">{{ hit.snippet }}</p>
                <span class="hud-label self-start rounded-sm border border-line bg-bg-navy px-1.5 py-0.5 text-[0.6rem] text-muted" data-testid="result-search-source">
                    {{ hit.source }}
                </span>
            </article>
        </div>
    </HudPanel>
</template>
