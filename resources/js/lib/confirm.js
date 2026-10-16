import { reactive } from 'vue';

/**
 * Promise-based confirmation dialog state (rendered by ConfirmDialog in the layout).
 * Usage: if (await this.$confirm({ title, message, danger: true })) { ... }
 */
export const confirmState = reactive({
    open: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    danger: false,
    requireText: null,
    resolve: null,
});

export function confirm(options = {}) {
    if (confirmState.resolve) {
        confirmState.resolve(false);
    }

    return new Promise((resolve) => {
        Object.assign(confirmState, {
            open: true,
            title: options.title || 'Are you sure?',
            message: options.message || '',
            confirmText: options.confirmText || 'Confirm',
            cancelText: options.cancelText || 'Cancel',
            danger: Boolean(options.danger),
            requireText: options.requireText || null,
            resolve,
        });
    });
}

export function settleConfirm(value) {
    const resolve = confirmState.resolve;
    confirmState.open = false;
    confirmState.resolve = null;

    if (resolve) {
        resolve(value);
    }
}
