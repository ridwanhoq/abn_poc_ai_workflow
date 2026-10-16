import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FlowLayout from '@/layouts/FlowLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { api } from '@/lib/api';
import { confirm } from '@/lib/confirm';
import { initializeFlashToast } from '@/lib/flashToast';
import { toast } from '@/lib/toast';

const appName = import.meta.env.VITE_APP_NAME || 'FlowForge AI';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case [
                'settings/Users',
                'settings/Audit',
                'settings/Workspaces',
            ].includes(name):
                return FlowLayout;
            case name.startsWith('settings/'):
                return [FlowLayout, SettingsLayout];
            default:
                return FlowLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });

        // Globals for Options API components: this.$api, this.$toast, this.$confirm, this.$can
        app.config.globalProperties.$api = api;
        app.config.globalProperties.$toast = toast;
        app.config.globalProperties.$confirm = confirm;
        app.config.globalProperties.$can = function (
            this: {
                $page: {
                    props: { auth?: { user?: { permissions?: string[] } } };
                };
            },
            permission: string,
        ) {
            return Boolean(
                this.$page?.props?.auth?.user?.permissions?.includes(
                    permission,
                ),
            );
        };
    },
    progress: {
        color: '#6161ff',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
