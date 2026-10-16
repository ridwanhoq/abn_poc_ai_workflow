<script>
import { confirmState, settleConfirm } from '@/lib/confirm';
import AppIcon from './AppIcon.vue';
import ModalDialog from './ModalDialog.vue';

export default {
    name: 'ConfirmDialog',
    components: { ModalDialog, AppIcon },
    data() {
        return { state: confirmState, typed: '' };
    },
    computed: {
        canConfirm() {
            return (
                !this.state.requireText || this.typed === this.state.requireText
            );
        },
    },
    watch: {
        'state.open'() {
            this.typed = '';
        },
    },
    methods: {
        settle(value) {
            settleConfirm(value);
        },
    },
};
</script>

<template>
    <ModalDialog :open="state.open" width="max-w-md" @close="settle(false)">
        <div class="flex gap-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-full"
                :class="
                    state.danger
                        ? 'bg-bad/10 text-bad'
                        : 'bg-brand-500/10 text-brand-500'
                "
            >
                <AppIcon
                    :name="state.danger ? 'triangle-alert' : 'info'"
                    :size="20"
                />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-bold">{{ state.title }}</h2>
                <p
                    v-if="state.message"
                    class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ state.message }}
                </p>
                <div v-if="state.requireText" class="mt-3">
                    <label class="ff-label"
                        >Type "{{ state.requireText }}" to confirm</label
                    >
                    <input v-model="typed" class="ff-input" autofocus />
                </div>
            </div>
        </div>
        <template #footer>
            <button
                type="button"
                class="ff-btn-secondary"
                @click="settle(false)"
            >
                {{ state.cancelText }}
            </button>
            <button
                type="button"
                :class="state.danger ? 'ff-btn-danger' : 'ff-btn-primary'"
                :disabled="!canConfirm"
                @click="settle(true)"
            >
                {{ state.confirmText }}
            </button>
        </template>
    </ModalDialog>
</template>
