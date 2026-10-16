<script>
export default {
    name: 'DropdownMenu',
    props: {
        align: { type: String, default: 'right' },
        width: { type: String, default: 'w-56' },
    },
    data() {
        return { open: false };
    },
    mounted() {
        document.addEventListener('mousedown', this.onOutside);
        document.addEventListener('keydown', this.onKey);
    },
    beforeUnmount() {
        document.removeEventListener('mousedown', this.onOutside);
        document.removeEventListener('keydown', this.onKey);
    },
    methods: {
        toggle() {
            this.open = !this.open;
        },
        close() {
            this.open = false;
        },
        onOutside(event) {
            if (this.open && !this.$el.contains(event.target)) {
                this.open = false;
            }
        },
        onKey(event) {
            if (event.key === 'Escape') {
                this.open = false;
            }
        },
    },
};
</script>

<template>
    <div class="relative inline-block">
        <slot name="trigger" :toggle="toggle" :open="open" />
        <Transition name="ff-fade">
            <div
                v-if="open"
                class="absolute z-50 mt-1.5 overflow-hidden ff-card p-1.5 shadow-xl"
                :class="[width, align === 'right' ? 'right-0' : 'left-0']"
                @click="$event.target.closest('[data-close]') && close()"
            >
                <slot :close="close" />
            </div>
        </Transition>
    </div>
</template>
