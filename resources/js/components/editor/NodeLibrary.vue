<script>
import AppIcon from '@/components/flow/AppIcon.vue';
import { CATEGORY_META } from '@/lib/format';

export default {
    name: 'NodeLibrary',
    components: { AppIcon },
    props: {
        nodeTypes: { type: Array, default: () => [] },
        disabled: { type: Boolean, default: false },
        hasTrigger: { type: Boolean, default: false },
    },
    emits: ['add'],
    data() {
        return { q: '', collapsed: {} };
    },
    computed: {
        groups() {
            const q = this.q.trim().toLowerCase();
            return Object.entries(CATEGORY_META)
                .map(([key, meta]) => ({
                    key,
                    ...meta,
                    items: this.nodeTypes.filter(
                        (n) =>
                            n.category === key &&
                            (!q ||
                                n.label.toLowerCase().includes(q) ||
                                n.description.toLowerCase().includes(q) ||
                                n.type.includes(q)),
                    ),
                }))
                .filter((g) => g.items.length);
        },
    },
    methods: {
        onDragStart(event, node) {
            event.dataTransfer.setData('application/flowforge-node', node.type);
            event.dataTransfer.effectAllowed = 'move';
        },
        blocked(node) {
            return (
                this.disabled ||
                (node.category === 'trigger' && this.hasTrigger)
            );
        },
    },
};
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="border-b border-border p-3">
            <div class="relative">
                <AppIcon
                    name="search"
                    :size="14"
                    class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="q"
                    type="search"
                    class="ff-input h-8 pl-8 text-xs"
                    placeholder="Search nodes…"
                    aria-label="Search nodes"
                />
            </div>
            <p class="mt-2 text-[11px] text-muted-foreground">
                Drag onto the canvas or click to add.
            </p>
        </div>
        <div class="flex-1 space-y-1 overflow-y-auto p-2">
            <section v-for="group in groups" :key="group.key">
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase hover:text-foreground"
                    @click="collapsed[group.key] = !collapsed[group.key]"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ backgroundColor: group.color }"
                    />
                    {{ group.label }}
                    <span class="ml-auto font-medium">{{
                        group.items.length
                    }}</span>
                    <AppIcon
                        :name="
                            collapsed[group.key]
                                ? 'chevron-right'
                                : 'chevron-down'
                        "
                        :size="12"
                    />
                </button>
                <div v-show="!collapsed[group.key]" class="space-y-1 pb-2">
                    <button
                        v-for="node in group.items"
                        :key="node.type"
                        type="button"
                        :draggable="!blocked(node)"
                        :disabled="blocked(node)"
                        class="group flex w-full cursor-grab items-center gap-2.5 rounded-xl border border-transparent px-2 py-1.5 text-left transition hover:border-border hover:bg-muted/60 active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-40"
                        :title="
                            blocked(node) && node.category === 'trigger'
                                ? 'A workflow can only have one trigger'
                                : node.description
                        "
                        @dragstart="onDragStart($event, node)"
                        @click="$emit('add', node.type)"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-lg"
                            :style="{
                                backgroundColor: group.color + '1f',
                                color: group.color,
                            }"
                        >
                            <AppIcon :name="node.icon" :size="14" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-xs font-semibold"
                                >{{ node.label }}</span
                            >
                        </span>
                        <AppIcon
                            v-if="node.implementation === 'simulated'"
                            name="flask-conical"
                            :size="12"
                            class="shrink-0 text-cat-ai"
                            title="Simulated"
                        />
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
