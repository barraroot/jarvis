import { vi } from 'vitest';

/**
 * Vitest stand-in for vite-plugin-pwa's `virtual:pwa-register` (only exists in a Vite build).
 * Tests drive the callbacks through `registerSW.mock.calls[0][0]`.
 */
export const registerSW = vi.fn(() => vi.fn(async () => undefined));
