import { createRouter, createWebHistory, type RouterHistory, type Router, type RouteRecordRaw } from 'vue-router';
import { setActiveRouter } from '@/lib/navigation';
import { useAuthStore } from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        requiresAuth?: boolean;
        guestOnly?: boolean;
        titleKey?: string;
    }
}

export const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'hud',
        component: () => import('@/pages/HudPage.vue'),
        meta: { requiresAuth: true, titleKey: 'pages.hud' },
    },
    {
        path: '/conversations/:id',
        name: 'conversation',
        component: () => import('@/pages/ConversationPage.vue'),
        props: true,
        meta: { requiresAuth: true, titleKey: 'pages.conversation' },
    },
    {
        path: '/settings',
        name: 'settings',
        component: () => import('@/pages/SettingsPage.vue'),
        meta: { requiresAuth: true, titleKey: 'pages.settings' },
    },
    {
        path: '/memories',
        name: 'memories',
        component: () => import('@/pages/MemoriesPage.vue'),
        meta: { requiresAuth: true, titleKey: 'pages.memories' },
    },
    {
        path: '/activity',
        name: 'activity',
        component: () => import('@/pages/ActivityPage.vue'),
        meta: { requiresAuth: true, titleKey: 'pages.activity' },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'hud' },
    },
];

/**
 * Builds the SPA router; the guard loads the session user (`GET /api/v1/auth/me`) on first navigation.
 */
export function createAppRouter(history: RouterHistory = createWebHistory()): Router {
    const router = createRouter({ history, routes });

    router.beforeEach(async (to) => {
        const auth = useAuthStore();

        await auth.ensureLoaded();

        if (to.meta.requiresAuth && !auth.isAuthenticated) {
            return {
                name: 'login',
                query: to.fullPath !== '/' ? { redirect: to.fullPath } : {},
            };
        }

        if (to.meta.guestOnly && auth.isAuthenticated) {
            return { name: 'hud' };
        }

        return true;
    });

    setActiveRouter(router);

    return router;
}
