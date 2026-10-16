<script>
import { iconFor } from '@/lib/icons';

export default {
    name: 'AppIcon',
    props: {
        name: { type: String, default: 'box' },
        size: { type: [Number, String], default: 16 },
        strokeWidth: { type: [Number, String], default: 2 },
    },
    computed: {
        component() {
            return iconFor(this.name);
        },
    },
};
</script>

<template>
    <component
        :is="component"
        :size="Number(size)"
        :stroke-width="Number(strokeWidth)"
        aria-hidden="true"
    />
</template>
