<script>
import AppIcon from './AppIcon.vue';

export default {
    name: 'EmptyState',
    components: { AppIcon },
    props: {
        icon: { type: String, default: 'inbox' },
        title: { type: String, required: true },
        description: { type: String, default: '' },
    },
};
</script>

<template>
    <div
        class="flex flex-col items-center justify-center px-6 py-14 text-center"
    >
        <div
            class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500/15 to-cat-ai/15 text-brand-500"
        >
            <AppIcon :name="icon" :size="26" />
        </div>
        <h3 class="text-base font-bold">{{ title }}</h3>
        <p
            v-if="description"
            class="mt-1 max-w-md text-sm text-muted-foreground"
        >
            {{ description }}
        </p>
        <div
            v-if="$slots.default"
            class="mt-5 flex flex-wrap justify-center gap-2"
        >
            <slot />
        </div>
    </div>
</template>
