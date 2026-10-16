<script>
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import ConfirmDialog from '@/components/flow/ConfirmDialog.vue';
import ExecutionIndicator from '@/components/flow/ExecutionIndicator.vue';
import GlobalSearch from '@/components/flow/GlobalSearch.vue';
import NotificationsBell from '@/components/flow/NotificationsBell.vue';
import QuickCreate from '@/components/flow/QuickCreate.vue';
import UserMenu from '@/components/flow/UserMenu.vue';
import WorkspaceSwitcher from '@/components/flow/WorkspaceSwitcher.vue';
import { Toaster } from '@/components/ui/sonner';

const NAV = [
    {
        label: 'Overview',
        href: '/dashboard',
        icon: 'layout-dashboard',
        permission: null,
    },
    {
        label: 'Workflows',
        href: '/workflows',
        icon: 'workflow',
        permission: 'workflows.view',
    },
    {
        label: 'Templates',
        href: '/templates',
        icon: 'layout-template',
        permission: 'templates.view',
    },
    {
        label: 'Executions',
        href: '/executions',
        icon: 'history',
        permission: 'executions.view',
    },
    {
        label: 'Integrations',
        href: '/integrations',
        icon: 'plug',
        permission: 'integrations.view',
    },
    {
        label: 'AI Playground',
        href: '/playground',
        icon: 'flask-conical',
        permission: 'playground.use',
    },
    {
        label: 'Data & Variables',
        href: '/data',
        icon: 'database',
        permission: 'variables.view',
    },
    {
        label: 'Settings',
        href: '/settings/profile',
        icon: 'settings',
        permission: null,
        match: '/settings',
    },
];

export default {
    name: 'FlowLayout',
    components: {
        AppIcon,
        ConfirmDialog,
        ExecutionIndicator,
        GlobalSearch,
        Link,
        NotificationsBell,
        QuickCreate,
        Toaster,
        UserMenu,
        WorkspaceSwitcher,
    },
    props: {
        // Accepted for compatibility with starter-kit pages that pass breadcrumbs.
        breadcrumbs: { type: Array, default: () => [] },
    },
    data() {
        let collapsed = false;
        try {
            collapsed = localStorage.getItem('ff_sidebar_collapsed') === '1';
        } catch {
            // ignore
        }
        return { collapsed, mobileOpen: false };
    },
    computed: {
        nav() {
            return NAV.filter(
                (item) => !item.permission || this.$can(item.permission),
            );
        },
        demoMode() {
            return this.$page.props.platform?.demo_mode;
        },
        fullBleed() {
            return this.$page.url.includes('/edit');
        },
    },
    watch: {
        '$page.url'() {
            this.mobileOpen = false;
        },
    },
    methods: {
        isActive(item) {
            const url = this.$page.url.split('?')[0];
            const base = item.match || item.href;
            return url === base || url.startsWith(base + '/');
        },
        toggleCollapsed() {
            this.collapsed = !this.collapsed;
            try {
                localStorage.setItem(
                    'ff_sidebar_collapsed',
                    this.collapsed ? '1' : '0',
                );
            } catch {
                // ignore
            }
        },
    },
};
</script>

<template>
    <div class="flex min-h-screen bg-background text-foreground">
        <!-- Mobile backdrop -->
        <Transition name="ff-fade">
            <div
                v-if="mobileOpen"
                class="fixed inset-0 z-40 bg-black/40 lg:hidden"
                @click="mobileOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex flex-col border-r border-border bg-card transition-all duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="[
                collapsed ? 'lg:w-[72px]' : 'lg:w-64',
                mobileOpen ? 'w-64 translate-x-0' : 'w-64 -translate-x-full',
            ]"
        >
            <div class="flex h-16 items-center gap-2.5 px-4">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 via-cat-ai to-cat-trigger text-white shadow-md shadow-brand-500/30"
                >
                    <AppIcon name="workflow" :size="19" stroke-width="2.4" />
                </div>
                <div v-if="!collapsed" class="min-w-0">
                    <div
                        class="truncate text-[15px] leading-tight font-extrabold"
                    >
                        FlowForge
                    </div>
                    <div class="text-[11px] font-medium text-muted-foreground">
                        AI automation · OTT & ERP
                    </div>
                </div>
            </div>

            <nav
                class="flex-1 space-y-0.5 overflow-y-auto px-3 py-2"
                aria-label="Main"
            >
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors"
                    :class="
                        isActive(item)
                            ? 'bg-brand-500/10 text-brand-600 dark:text-brand-300'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    :title="collapsed ? item.label : null"
                >
                    <AppIcon :name="item.icon" :size="18" class="shrink-0" />
                    <span v-if="!collapsed" class="truncate">{{
                        item.label
                    }}</span>
                    <span
                        v-if="isActive(item) && !collapsed"
                        class="ml-auto size-1.5 rounded-full bg-brand-500"
                    />
                </Link>
            </nav>

            <div class="space-y-2 p-3">
                <div
                    v-if="demoMode && !collapsed"
                    class="rounded-xl border border-cat-ai/30 bg-cat-ai/8 p-3 text-xs"
                >
                    <div
                        class="flex items-center gap-1.5 font-bold text-cat-ai"
                    >
                        <AppIcon name="flask-conical" :size="13" /> Demo mode
                    </div>
                    <p class="mt-1 text-muted-foreground">
                        Emails are logged, outbound HTTP is simulated and AI
                        nodes use mock adapters.
                    </p>
                </div>
                <button
                    type="button"
                    class="ff-btn-ghost hidden w-full justify-start lg:flex"
                    :aria-label="
                        collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                    "
                    @click="toggleCollapsed"
                >
                    <AppIcon
                        :name="collapsed ? 'panel-right' : 'panel-left'"
                        :size="17"
                    />
                    <span v-if="!collapsed">Collapse</span>
                </button>
            </div>
        </aside>

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-border bg-card/85 px-3 backdrop-blur-md sm:gap-3 sm:px-5"
            >
                <button
                    type="button"
                    class="ff-icon-btn lg:hidden"
                    aria-label="Open menu"
                    @click="mobileOpen = true"
                >
                    <AppIcon name="menu" :size="19" />
                </button>
                <WorkspaceSwitcher />
                <div class="hidden flex-1 justify-center md:flex">
                    <GlobalSearch />
                </div>
                <div class="ml-auto flex items-center gap-1 sm:gap-2">
                    <ExecutionIndicator />
                    <QuickCreate />
                    <NotificationsBell />
                    <UserMenu />
                </div>
            </header>

            <main
                class="min-w-0 flex-1"
                :class="
                    fullBleed
                        ? ''
                        : 'mx-auto w-full max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8'
                "
            >
                <slot />
            </main>
        </div>

        <ConfirmDialog />
        <Toaster position="bottom-right" rich-colors close-button />
    </div>
</template>
