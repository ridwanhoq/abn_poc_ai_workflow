<script>
import { router } from '@inertiajs/vue3';
import AppIcon from './AppIcon.vue';
import DropdownMenu from './DropdownMenu.vue';
import MenuItem from './MenuItem.vue';

function applyTheme(value) {
    const dark =
        value === 'dark' ||
        (value === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    try {
        localStorage.setItem('appearance', value);
    } catch {
        // storage may be unavailable (private mode)
    }
    document.cookie = `appearance=${value};path=/;max-age=31536000;SameSite=Lax`;
}

export default {
    name: 'UserMenu',
    components: { AppIcon, DropdownMenu, MenuItem },
    data() {
        let appearance = 'system';
        try {
            appearance = localStorage.getItem('appearance') || 'system';
        } catch {
            // ignore
        }
        return { appearance };
    },
    computed: {
        user() {
            return this.$page.props.auth.user;
        },
        initials() {
            return (this.user?.name || '?')
                .split(' ')
                .map((p) => p.charAt(0))
                .slice(0, 2)
                .join('')
                .toUpperCase();
        },
    },
    methods: {
        setTheme(value) {
            this.appearance = value;
            applyTheme(value);
        },
        logout() {
            router.post('/logout');
        },
    },
};
</script>

<template>
    <DropdownMenu width="w-64">
        <template #trigger="{ toggle }">
            <button
                type="button"
                class="flex cursor-pointer items-center gap-2 rounded-full p-0.5 transition hover:ring-2 hover:ring-brand-500/30"
                aria-label="User menu"
                @click="toggle"
            >
                <span
                    class="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cat-ai text-xs font-bold text-white"
                    >{{ initials }}</span
                >
            </button>
        </template>
        <div class="px-2.5 pt-1 pb-2.5">
            <div class="truncate text-sm font-bold">{{ user?.name }}</div>
            <div class="truncate text-xs text-muted-foreground">
                {{ user?.email }}
            </div>
            <span
                class="mt-2 ff-chip bg-brand-500/10 text-brand-600 dark:text-brand-300"
            >
                <AppIcon name="shield" :size="11" />
                {{ user?.role?.name || 'No role' }}
            </span>
        </div>
        <div class="my-1 border-t border-border" />
        <MenuItem icon="user" @select="$inertia.visit('/settings/profile')"
            >Profile & settings</MenuItem
        >
        <MenuItem
            v-if="$can('users.manage')"
            icon="users"
            @select="$inertia.visit('/settings/users')"
            >Users & roles</MenuItem
        >
        <div class="my-1 border-t border-border" />
        <div class="flex items-center gap-1 px-1.5 py-1">
            <button
                v-for="option in [
                    { value: 'light', icon: 'sun', label: 'Light' },
                    { value: 'dark', icon: 'moon', label: 'Dark' },
                    { value: 'system', icon: 'monitor-play', label: 'Auto' },
                ]"
                :key="option.value"
                type="button"
                class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-md py-1.5 text-xs font-semibold"
                :class="
                    appearance === option.value
                        ? 'bg-brand-500 text-white'
                        : 'text-muted-foreground hover:bg-muted'
                "
                @click="setTheme(option.value)"
            >
                <AppIcon :name="option.icon" :size="13" /> {{ option.label }}
            </button>
        </div>
        <div class="my-1 border-t border-border" />
        <MenuItem icon="log-out" danger @select="logout">Log out</MenuItem>
    </DropdownMenu>
</template>
