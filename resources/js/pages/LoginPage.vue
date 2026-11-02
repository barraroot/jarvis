<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import { HttpError, type ValidationErrors } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = reactive({ email: '', password: '', remember: false });
const errors = ref<ValidationErrors>({});
const generalError = ref<string | null>(null);
const submitting = ref(false);
const throttleSeconds = ref(0);
let throttleTimer: ReturnType<typeof setInterval> | null = null;

const isThrottled = computed(() => throttleSeconds.value > 0);

function stopCountdown(): void {
    if (throttleTimer !== null) {
        clearInterval(throttleTimer);
        throttleTimer = null;
    }
}

function startCountdown(seconds: number): void {
    stopCountdown();
    throttleSeconds.value = Math.max(1, seconds);
    throttleTimer = setInterval(() => {
        throttleSeconds.value = Math.max(0, throttleSeconds.value - 1);

        if (throttleSeconds.value === 0) {
            stopCountdown();
        }
    }, 1000);
}

function redirectTarget(): string {
    const redirect = route.query.redirect;

    // Only same-app paths; never an absolute or protocol-relative URL.
    return typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/';
}

async function submit(): Promise<void> {
    if (submitting.value || isThrottled.value) {
        return;
    }

    submitting.value = true;
    errors.value = {};
    generalError.value = null;

    try {
        await auth.login({ email: form.email, password: form.password, remember: form.remember });
        await router.push(redirectTarget());
    } catch (error) {
        if (error instanceof HttpError && error.status === 422) {
            errors.value = error.validationErrors;
        } else if (error instanceof HttpError && error.status === 429) {
            startCountdown(error.retryAfter ?? 60);
        } else if (error instanceof HttpError && error.serverMessage) {
            generalError.value = error.serverMessage;
        } else {
            generalError.value = t('auth.genericError');
        }
    } finally {
        submitting.value = false;
    }
}

onBeforeUnmount(stopCountdown);
</script>

<template>
    <div class="hud-grid flex min-h-dvh w-full items-center justify-center overflow-x-hidden bg-bg px-4 py-8">
        <div class="flex w-full max-w-md flex-col items-center gap-6">
            <JarvisCore state="IDLE" size="full" />

            <div class="text-center">
                <h1 class="hud-label font-display text-2xl text-cyan hud-glow sm:text-3xl">{{ t('app.name') }}</h1>
                <p class="hud-label mt-1 text-[0.55rem] text-muted">{{ t('app.tagline') }}</p>
            </div>

            <HudPanel :title="t('auth.title')" class="w-full">
                <p class="mb-4 text-xs text-muted">{{ t('auth.subtitle') }}</p>

                <form class="flex flex-col gap-4" novalidate data-testid="login-form" @submit.prevent="submit">
                    <HudInput
                        id="login-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        :label="t('auth.email')"
                        :error="errors.email?.[0] ?? null"
                    />
                    <HudInput
                        id="login-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :label="t('auth.password')"
                        :error="errors.password?.[0] ?? null"
                    />
                    <HudToggle v-model="form.remember" :label="t('auth.remember')" />

                    <p v-if="isThrottled" class="text-xs text-amber" role="alert" data-testid="login-throttled">
                        {{ t('auth.throttled', { seconds: throttleSeconds }) }}
                    </p>
                    <p v-if="generalError" class="text-xs text-danger" role="alert" data-testid="login-error">
                        {{ generalError }}
                    </p>

                    <HudButton type="submit" block :loading="submitting" :disabled="isThrottled">
                        {{ submitting ? t('auth.submitting') : t('auth.submit') }}
                    </HudButton>
                </form>
            </HudPanel>
        </div>
    </div>
</template>
