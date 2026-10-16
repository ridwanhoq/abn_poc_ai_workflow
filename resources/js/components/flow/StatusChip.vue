<script>
import { statusMeta } from '@/lib/format';
import AppIcon from './AppIcon.vue';

export default {
    name: 'StatusChip',
    components: { AppIcon },
    props: {
        status: { type: String, default: null },
        label: { type: String, default: null },
        size: { type: String, default: 'md' },
    },
    computed: {
        meta() {
            return statusMeta(this.status);
        },
        spinning() {
            return this.status === 'running';
        },
    },
};
</script>

<template>
    <span
        class="ff-chip"
        :class="size === 'sm' ? 'px-2 py-px text-[11px]' : ''"
        :style="{
            backgroundColor: meta.color + '22',
            color: meta.color,
        }"
    >
        <AppIcon
            :name="meta.icon"
            :size="size === 'sm' ? 11 : 13"
            stroke-width="2.5"
            :class="{ 'animate-spin': spinning }"
        />
        <span class="text-foreground/80 dark:text-foreground/90">{{
            label || meta.label
        }}</span>
    </span>
</template>
