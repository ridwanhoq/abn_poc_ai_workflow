<script>
import AppIcon from './AppIcon.vue';

export default {
    name: 'ModalDialog',
    components: { AppIcon },
    props: {
        open: { type: Boolean, default: false },
        title: { type: String, default: '' },
        description: { type: String, default: '' },
        width: { type: String, default: 'max-w-lg' },
        closable: { type: Boolean, default: true },
    },
    emits: ['close'],
    watch: {
        open(value) {
            document.body.style.overflow = value ? 'hidden' : '';
        },
    },
    mounted() {
        window.addEventListener('keydown', this.onKey);
    },
    beforeUnmount() {
        window.removeEventListener('keydown', this.onKey);
        document.body.style.overflow = '';
    },
    methods: {
        onKey(event) {
            if (event.key === 'Escape' && this.open && this.closable) {
                this.$emit('close');
            }
        },
    },
};
</script>

<template>
    <Teleport to="body">
        <Transition name="ff-fade">
            <div
                v-if="open"
                class="fixed inset-0 z-[60] flex items-end justify-center bg-[#0f1229]/45 p-0 backdrop-blur-[2px] sm:items-center sm:p-4"
                role="dialog"
                aria-modal="true"
                @mousedown.self="closable && $emit('close')"
            >
                <div
                    class="flex max-h-[92vh] w-full flex-col overflow-hidden ff-card rounded-b-none sm:rounded-2xl"
                    :class="width"
                >
                    <div
                        v-if="title"
                        class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"
                    >
                        <div>
                            <h2 class="text-base font-bold">{{ title }}</h2>
                            <p
                                v-if="description"
                                class="mt-0.5 text-sm text-muted-foreground"
                            >
                                {{ description }}
                            </p>
                        </div>
                        <button
                            v-if="closable"
                            type="button"
                            class="-mt-1 -mr-2 ff-icon-btn"
                            aria-label="Close"
                            @click="$emit('close')"
                        >
                            <AppIcon name="x" />
                        </button>
                    </div>
                    <div class="overflow-y-auto px-5 py-4">
                        <slot />
                    </div>
                    <div
                        v-if="$slots.footer"
                        class="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/40 px-5 py-3"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
