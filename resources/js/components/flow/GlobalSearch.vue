<script>
import { router } from '@inertiajs/vue3';
import { api } from '@/lib/api';
import { debounce } from '@/lib/format';
import AppIcon from './AppIcon.vue';

const TYPE_ICON = {
    workflow: 'workflow',
    template: 'layout-template',
    execution: 'history',
};

export default {
    name: 'GlobalSearch',
    components: { AppIcon },
    data() {
        return { q: '', results: [], open: false, loading: false, active: 0 };
    },
    created() {
        this.search = debounce(this.search, 250);
    },
    mounted() {
        window.addEventListener('keydown', this.onShortcut);
        document.addEventListener('mousedown', this.onOutside);
    },
    beforeUnmount() {
        window.removeEventListener('keydown', this.onShortcut);
        document.removeEventListener('mousedown', this.onOutside);
    },
    methods: {
        icon(type) {
            return TYPE_ICON[type] || 'search';
        },
        onInput() {
            this.open = true;
            this.loading = this.q.trim() !== '';
            this.search();
        },
        async search() {
            const q = this.q.trim();
            if (!q) {
                this.results = [];
                this.loading = false;
                return;
            }
            try {
                const { data } = await api.get('/search', { params: { q } });
                this.results = data.data;
                this.active = 0;
            } catch {
                this.results = [];
            } finally {
                this.loading = false;
            }
        },
        go(result) {
            this.open = false;
            this.q = '';
            router.visit(result.url);
        },
        onKeydown(event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.active = Math.min(
                    this.active + 1,
                    this.results.length - 1,
                );
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.active = Math.max(this.active - 1, 0);
            } else if (event.key === 'Enter' && this.results[this.active]) {
                this.go(this.results[this.active]);
            } else if (event.key === 'Escape') {
                this.open = false;
                this.$refs.input.blur();
            }
        },
        onShortcut(event) {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                this.$refs.input.focus();
            }
        },
        onOutside(event) {
            if (!this.$el.contains(event.target)) {
                this.open = false;
            }
        },
    },
};
</script>

<template>
    <div class="relative w-full max-w-md">
        <AppIcon
            name="search"
            :size="15"
            class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
        />
        <input
            ref="input"
            v-model="q"
            type="search"
            class="ff-input h-9 rounded-full bg-muted/60 pr-14 pl-9"
            placeholder="Search workflows, templates, #execution…"
            aria-label="Global search"
            @input="onInput"
            @focus="open = q !== ''"
            @keydown="onKeydown"
        />
        <kbd
            class="pointer-events-none absolute top-1/2 right-3 hidden -translate-y-1/2 rounded border border-border bg-card px-1.5 text-[10px] font-semibold text-muted-foreground sm:block"
            >⌘K</kbd
        >
        <Transition name="ff-fade">
            <div
                v-if="open && q"
                class="absolute top-11 right-0 left-0 z-50 overflow-hidden ff-card p-1.5 shadow-xl"
            >
                <div v-if="loading" class="space-y-2 p-2">
                    <div class="h-8 ff-skeleton" />
                    <div class="h-8 ff-skeleton" />
                </div>
                <div
                    v-else-if="!results.length"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No matches for “{{ q }}”.
                </div>
                <button
                    v-for="(result, i) in results"
                    v-else
                    :key="result.type + result.id"
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-left"
                    :class="i === active ? 'bg-muted' : 'hover:bg-muted/60'"
                    @mouseenter="active = i"
                    @click="go(result)"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-md bg-brand-500/10 text-brand-500"
                    >
                        <AppIcon :name="icon(result.type)" :size="14" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{
                            result.title
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ result.type }} · {{ result.subtitle }}</span
                        >
                    </span>
                </button>
            </div>
        </Transition>
    </div>
</template>
