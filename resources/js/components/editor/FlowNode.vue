<script>
import { Handle, Position } from '@vue-flow/core';
import AppIcon from '@/components/flow/AppIcon.vue';
import { CATEGORY_META, formatDuration, statusMeta } from '@/lib/format';

const HANDLE_LABELS = {
    true: 'true',
    false: 'false',
    each: 'each',
    done: 'done',
    default: 'default',
};

export default {
    name: 'FlowNode',
    components: { AppIcon, Handle },
    props: {
        id: { type: String, required: true },
        data: { type: Object, required: true },
        selected: { type: Boolean, default: false },
    },
    data() {
        return { Position };
    },
    computed: {
        meta() {
            return this.data.meta || {};
        },
        category() {
            return CATEGORY_META[this.meta.category] || CATEGORY_META.utility;
        },
        handles() {
            return this.data.handles || ['main'];
        },
        run() {
            return this.data.run || null;
        },
        runMeta() {
            return this.run ? statusMeta(this.run.status) : null;
        },
        isTrigger() {
            return this.meta.category === 'trigger';
        },
        ring() {
            if (this.data.issues) {
                return 'ring-2 ring-bad/70';
            }
            if (this.run?.status === 'running') {
                return 'ring-2 ring-run';
            }
            if (this.selected) {
                return 'ring-2 ring-brand-500';
            }
            return 'ring-1 ring-border';
        },
    },
    methods: {
        formatDuration,
        handleLabel(handle) {
            if (handle.startsWith('case_')) {
                const index = Number(handle.slice(5));
                const value = this.data.config?.cases?.[index]?.value;
                return value !== undefined && value !== ''
                    ? String(value)
                    : `case ${index + 1}`;
            }
            return HANDLE_LABELS[handle] || handle;
        },
        handleTop(index) {
            const count = this.handles.length;
            return count === 1
                ? '50%'
                : `${((index + 1) / (count + 1)) * 100}%`;
        },
    },
};
</script>

<template>
    <div
        class="group relative w-[232px] rounded-2xl bg-card text-left shadow-[0_2px_10px_-2px_rgba(16,24,64,0.12)] transition-shadow duration-150 hover:shadow-[0_6px_20px_-6px_rgba(16,24,64,0.25)]"
        :class="[ring, isTrigger ? 'rounded-l-[28px]' : '']"
    >
        <div
            class="absolute inset-y-0 left-0 w-1.5"
            :class="isTrigger ? 'rounded-l-[28px]' : 'rounded-l-2xl'"
            :style="{ backgroundColor: category.color }"
        />
        <div class="flex items-start gap-2.5 py-3 pr-3 pl-4">
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                :style="{
                    backgroundColor: category.color + '1f',
                    color: category.color,
                }"
            >
                <AppIcon :name="meta.icon || 'box'" :size="18" />
            </div>
            <div class="min-w-0 flex-1">
                <div
                    class="truncate text-[13px] leading-tight font-bold text-foreground"
                >
                    {{ data.name || meta.label }}
                </div>
                <div class="mt-0.5 truncate text-[11px] text-muted-foreground">
                    {{ meta.label }}
                </div>
                <div class="mt-1.5 flex flex-wrap items-center gap-1">
                    <span
                        class="rounded bg-muted px-1.5 py-px font-mono text-[10px] text-muted-foreground"
                        >{{ id }}</span
                    >
                    <span
                        v-if="meta.implementation === 'simulated'"
                        class="inline-flex items-center gap-0.5 rounded bg-cat-ai/12 px-1.5 py-px text-[10px] font-semibold text-cat-ai"
                        title="Simulated adapter — deterministic sample output"
                    >
                        <AppIcon name="flask-conical" :size="10" /> sim
                    </span>
                    <span
                        v-if="data.issues"
                        class="inline-flex items-center gap-0.5 rounded bg-bad/12 px-1.5 py-px text-[10px] font-semibold text-bad"
                        :title="`${data.issues} validation issue(s)`"
                    >
                        <AppIcon name="triangle-alert" :size="10" />
                        {{ data.issues }}
                    </span>
                </div>
            </div>
        </div>

        <div
            v-if="run"
            class="flex items-center justify-between border-t border-border/70 px-3 py-1.5 text-[11px]"
            :style="{ backgroundColor: runMeta.color + '14' }"
        >
            <span
                class="inline-flex items-center gap-1 font-semibold"
                :style="{ color: runMeta.color }"
            >
                <AppIcon
                    :name="runMeta.icon"
                    :size="11"
                    :class="{ 'animate-spin': run.status === 'running' }"
                />
                {{ runMeta.label
                }}<template v-if="run.count > 1"> ×{{ run.count }}</template>
            </span>
            <span class="text-muted-foreground">{{
                formatDuration(run.duration_ms)
            }}</span>
        </div>

        <Handle
            v-if="meta.accepts_input"
            id="in"
            type="target"
            :position="Position.Left"
            :connectable="data.editable"
        />
        <template v-for="(handle, index) in handles" :key="handle">
            <Handle
                :id="handle"
                type="source"
                :position="Position.Right"
                :style="{
                    top: handleTop(index),
                    background:
                        handle === 'false'
                            ? '#e2445c'
                            : handle === 'true'
                              ? '#00c875'
                              : undefined,
                }"
                :connectable="data.editable"
            />
            <span
                v-if="handle !== 'main'"
                class="pointer-events-none absolute left-full ml-2 -translate-y-1/2 rounded bg-card/90 px-1 text-[10px] font-semibold whitespace-nowrap text-muted-foreground"
                :style="{ top: handleTop(index) }"
                >{{ handleLabel(handle) }}</span
            >
        </template>
    </div>
</template>
