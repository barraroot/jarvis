import { defineStore } from 'pinia';
import { ref } from 'vue';

export type ToastVariant = 'info' | 'success' | 'warning' | 'danger';

export interface Toast {
    id: number;
    message: string;
    variant: ToastVariant;
}

export interface ToastOptions {
    variant?: ToastVariant;
    timeoutMs?: number;
}

export const useToastStore = defineStore('toast', () => {
    const toasts = ref<Toast[]>([]);
    let nextId = 1;

    function dismiss(id: number): void {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    function push(message: string, options: ToastOptions = {}): number {
        const id = nextId++;
        const timeoutMs = options.timeoutMs ?? 5000;

        toasts.value.push({ id, message, variant: options.variant ?? 'info' });

        if (timeoutMs > 0) {
            setTimeout(() => dismiss(id), timeoutMs);
        }

        return id;
    }

    return { toasts, push, dismiss };
});
