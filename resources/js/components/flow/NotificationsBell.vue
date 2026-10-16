<script>
import { router } from '@inertiajs/vue3';
import { api } from '@/lib/api';
import { timeAgo } from '@/lib/format';
import AppIcon from './AppIcon.vue';
import DropdownMenu from './DropdownMenu.vue';

const LEVEL_COLOR = {
    error: '#e2445c',
    warning: '#fdab3d',
    success: '#00c875',
    info: '#579bfc',
};

export default {
    name: 'NotificationsBell',
    components: { AppIcon, DropdownMenu },
    data() {
        return { items: [], unread: 0, timer: null };
    },
    mounted() {
        this.load();
        this.timer = setInterval(this.load, 30000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        timeAgo,
        color(level) {
            return LEVEL_COLOR[level] || LEVEL_COLOR.info;
        },
        async load() {
            if (document.hidden) {
                return;
            }
            try {
                const { data } = await api.get('/notifications');
                this.items = data.data;
                this.unread = data.unread;
            } catch {
                // Bell stays quiet if notifications are unavailable.
            }
        },
        async open(item) {
            if (!item.read_at) {
                await api
                    .post(`/notifications/${item.id}/read`)
                    .catch(() => {});
                item.read_at = new Date().toISOString();
                this.unread = Math.max(0, this.unread - 1);
            }
            if (item.data.execution_id) {
                router.visit(`/executions/${item.data.execution_id}`);
            }
        },
        async readAll() {
            await api.post('/notifications/read-all').catch(() => {});
            this.items.forEach(
                (i) => (i.read_at = i.read_at || new Date().toISOString()),
            );
            this.unread = 0;
        },
    },
};
</script>

<template>
    <DropdownMenu width="w-80">
        <template #trigger="{ toggle }">
            <button
                type="button"
                class="relative ff-icon-btn"
                :aria-label="`Notifications (${unread} unread)`"
                @click="toggle"
            >
                <AppIcon name="bell" :size="18" />
                <span
                    v-if="unread"
                    class="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-bad px-1 text-[10px] leading-4 font-bold text-white"
                    >{{ unread > 9 ? '9+' : unread }}</span
                >
            </button>
        </template>
        <div class="flex items-center justify-between px-2.5 pt-1 pb-2">
            <span class="text-sm font-bold">Notifications</span>
            <button
                v-if="unread"
                type="button"
                class="text-xs font-semibold text-brand-500 hover:underline"
                @click="readAll"
            >
                Mark all read
            </button>
        </div>
        <div class="max-h-96 overflow-y-auto">
            <button
                v-for="item in items"
                :key="item.id"
                type="button"
                data-close
                class="flex w-full cursor-pointer gap-3 rounded-lg px-2.5 py-2.5 text-left hover:bg-muted"
                @click="open(item)"
            >
                <span
                    class="mt-1.5 size-2 shrink-0 rounded-full"
                    :style="{
                        backgroundColor: item.read_at
                            ? 'transparent'
                            : color(item.data.level),
                    }"
                />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold">{{
                        item.data.title
                    }}</span>
                    <span
                        class="line-clamp-2 block text-xs text-muted-foreground"
                        >{{ item.data.message }}</span
                    >
                    <span
                        class="mt-0.5 block text-[11px] text-muted-foreground/80"
                        >{{ timeAgo(item.created_at) }}</span
                    >
                </span>
            </button>
            <p
                v-if="!items.length"
                class="px-3 py-8 text-center text-sm text-muted-foreground"
            >
                You're all caught up.
            </p>
        </div>
    </DropdownMenu>
</template>
