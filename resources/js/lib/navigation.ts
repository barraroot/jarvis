import type { Router } from 'vue-router';

let activeRouter: Router | null = null;

/**
 * Holds the router instance so non-component code (stores, HTTP layer) can redirect.
 */
export function setActiveRouter(router: Router): void {
    activeRouter = router;
}

export function getActiveRouter(): Router | null {
    return activeRouter;
}

export async function redirectToLogin(): Promise<void> {
    const router = activeRouter;

    if (router === null || router.currentRoute.value.name === 'login') {
        return;
    }

    const current = router.currentRoute.value.fullPath;

    await router.push({
        name: 'login',
        query: current && current !== '/' && current !== '/login' ? { redirect: current } : {},
    });
}
