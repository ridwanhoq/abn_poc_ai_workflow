<script>
import AppIcon from './AppIcon.vue';

export default {
    name: 'PaginationBar',
    components: { AppIcon },
    props: {
        meta: { type: Object, default: null },
    },
    emits: ['page'],
};
</script>

<template>
    <div
        v-if="meta && meta.last_page > 1"
        class="flex items-center justify-between gap-3 px-4 py-3 text-sm text-muted-foreground"
    >
        <span>
            Page {{ meta.current_page }} of {{ meta.last_page }} ·
            {{ meta.total }} total
        </span>
        <div class="flex gap-1.5">
            <button
                class="ff-btn-secondary ff-btn-sm"
                :disabled="meta.current_page <= 1"
                @click="$emit('page', meta.current_page - 1)"
            >
                <AppIcon name="chevron-left" :size="14" /> Prev
            </button>
            <button
                class="ff-btn-secondary ff-btn-sm"
                :disabled="meta.current_page >= meta.last_page"
                @click="$emit('page', meta.current_page + 1)"
            >
                Next <AppIcon name="chevron-right" :size="14" />
            </button>
        </div>
    </div>
</template>
