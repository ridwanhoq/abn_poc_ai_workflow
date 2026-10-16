<script>
export default {
    name: 'PageHeader',
    props: {
        title: { type: String, required: true },
        description: { type: String, default: '' },
    },
};
</script>

<template>
    <div
        class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="min-w-0">
            <h1 class="text-2xl font-extrabold tracking-tight sm:text-[28px]">
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </div>
</template>
