<script>
import { pretty } from '@/lib/format';
import AppIcon from './AppIcon.vue';

export default {
    name: 'JsonView',
    components: { AppIcon },
    props: {
        value: { default: null },
        maxHeight: { type: String, default: 'max-h-80' },
        label: { type: String, default: '' },
    },
    data() {
        return { copied: false };
    },
    computed: {
        text() {
            return pretty(this.value);
        },
    },
    methods: {
        async copy() {
            try {
                await navigator.clipboard.writeText(this.text);
                this.copied = true;
                setTimeout(() => (this.copied = false), 1200);
            } catch {
                this.$toast.error(
                    'Copy failed — your browser blocked clipboard access.',
                );
            }
        },
    },
};
</script>

<template>
    <div class="group relative">
        <div v-if="label" class="ff-label">{{ label }}</div>
        <pre
            class="overflow-auto rounded-xl border border-border bg-muted/60 p-3 ff-mono whitespace-pre-wrap text-foreground/90"
            :class="maxHeight"
            >{{ text }}</pre>
        <button
            type="button"
            class="absolute top-1.5 right-1.5 rounded-md bg-card/90 p-1.5 text-muted-foreground opacity-0 shadow-sm transition group-hover:opacity-100 hover:text-foreground"
            :class="label ? 'top-7' : ''"
            :title="copied ? 'Copied' : 'Copy JSON'"
            @click="copy"
        >
            <AppIcon :name="copied ? 'check' : 'copy'" :size="13" />
        </button>
    </div>
</template>
