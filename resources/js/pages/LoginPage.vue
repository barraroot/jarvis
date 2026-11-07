<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import BootSequence from '@/components/hud/BootSequence.vue';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import { BOOT_ANIMATION, useBootSequence } from '@/composables/useBootSequence';
import { useReducedMotion } from '@/composables/useReducedMotion';
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

/**
 * Boot sequence (RF-03): only right after an accepted login — a session
 * restored through `GET /auth/me` never reaches this page's submit.
 */
const boot = useBootSequence();
const booting = ref(false);
const reducedMotion = useReducedMotion();

/** The login card sinks (scale .98 + blur) while the boot checklist takes over (UI-14). */
const cardSinkStyle = computed(() =>
    booting.value && !reducedMotion.value
        ? { animation: `boot-sink ${BOOT_ANIMATION.card.durationMs}ms var(--ease-fly) ${BOOT_ANIMATION.card.delayMs}ms both` }
        : undefined,
);

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
        booting.value = true;
        await boot.run();
        await router.push(redirectTarget());
    } catch (error) {
        if (error instanceof HttpError && error.status === 422) {
            errors.value = error.validationErrors;
            generalError.value = t('auth.invalidCredentials');
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
    <div
        class="abyss-bg relative flex min-h-dvh w-full items-center justify-center overflow-hidden px-4 py-8"
        :data-booting="booting ? 'true' : 'false'"
        data-testid="login-page"
    >
        <!-- SLEEPING core in the background (UI-14). -->
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center opacity-60" aria-hidden="true">
            <JarvisCore state="SLEEPING" size="full" />
        </div>

        <div class="glow absolute top-5 left-6 font-logo text-[15px] font-bold tracking-[.32em] text-biolum-200 sm:left-7">
            {{ t('app.name') }}
        </div>
        <p class="label-caps absolute top-6 right-6 hidden text-label text-text-400 sm:right-7 sm:block">
            {{ t('auth.systemTagline') }}
        </p>

        <div class="relative z-10 grid w-full max-w-[380px] place-items-center">
            <form
                v-if="!booting || !reducedMotion"
                class="glass chamfer-md col-start-1 row-start-1 flex w-full flex-col gap-4 px-7 pt-7 pb-6"
                :class="{ 'pointer-events-none': booting }"
                :style="cardSinkStyle"
                :inert="booting"
                :aria-hidden="booting ? 'true' : undefined"
                novalidate
                data-testid="login-form"
                @submit.prevent="submit"
            >
                <h1 class="label-caps text-sm text-text-100" data-testid="login-title">{{ t('auth.restricted') }}</h1>

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

                <p v-if="isThrottled" class="text-xs text-amber-400" role="alert" data-testid="login-throttled">
                    {{ t('auth.throttled', { seconds: throttleSeconds }) }}
                </p>
                <p v-if="generalError" class="text-[13px] text-danger-400" role="alert" data-testid="login-error">
                    {{ generalError }}
                </p>

                <HudButton type="submit" block class="mt-1" :loading="submitting" :disabled="isThrottled">
                    {{ submitting && !booting ? t('auth.submitting') : t('auth.submit') }}
                </HudButton>

                <p class="text-xs leading-normal text-text-400" data-testid="login-no-registration">
                    {{ t('auth.noSelfRegistration') }}
                </p>
            </form>

            <BootSequence
                v-if="booting"
                class="col-start-1 row-start-1"
                :items="boot.items.value"
                :reduced-motion="reducedMotion"
            />
        </div>
    </div>
</template>
