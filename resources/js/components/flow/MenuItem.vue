<script>
import AppIcon from './AppIcon.vue';

export default {
    name: 'MenuItem',
    components: { AppIcon },
    props: {
        icon: { type: String, default: null },
        danger: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },
    emits: ['select'],
};
</script>

<template>
    <button
        type="button"
        data-close
        :disabled="disabled"
        class="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40"
        :class="
            danger
                ? 'text-bad hover:bg-bad/10'
                : 'text-foreground hover:bg-muted'
        "
        @click="$emit('select')"
    >
        <AppIcon
            v-if="icon"
            :name="icon"
            :size="15"
            class="shrink-0 opacity-80"
        />
        <span class="flex-1 truncate"><slot /></span>
    </button>
</template>
