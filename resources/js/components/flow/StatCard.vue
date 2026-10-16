<script>
import AppIcon from './AppIcon.vue';

export default {
    name: 'StatCard',
    components: { AppIcon },
    props: {
        label: { type: String, required: true },
        value: { type: [String, Number], default: null },
        icon: { type: String, default: 'activity' },
        color: { type: String, default: '#6161ff' },
        hint: { type: String, default: '' },
        loading: { type: Boolean, default: false },
    },
};
</script>

<template>
    <div class="relative overflow-hidden ff-card p-4 sm:p-5">
        <div
            class="pointer-events-none absolute -top-8 -right-8 size-24 rounded-full opacity-[0.08]"
            :style="{ backgroundColor: color }"
        />
        <div class="flex items-center gap-3">
            <div
                class="flex size-9 items-center justify-center rounded-xl"
                :style="{ backgroundColor: color + '1f', color }"
            >
                <AppIcon :name="icon" :size="18" />
            </div>
            <span class="text-sm font-medium text-muted-foreground">{{
                label
            }}</span>
        </div>
        <div class="mt-3">
            <div v-if="loading" class="h-8 w-20 ff-skeleton" />
            <div
                v-else
                class="text-[28px] leading-none font-extrabold tracking-tight"
            >
                {{ value ?? '—' }}
            </div>
            <p
                v-if="hint && !loading"
                class="mt-1.5 text-xs text-muted-foreground"
            >
                {{ hint }}
            </p>
        </div>
    </div>
</template>
