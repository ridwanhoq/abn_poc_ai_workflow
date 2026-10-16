import { toast as sonner } from 'vue-sonner';

/**
 * Reusable toast API used across the app (never alert()).
 */
export const toast = {
    success: (message, description) => sonner.success(message, { description }),
    error: (message, description) =>
        sonner.error(message, { description, duration: 6000 }),
    info: (message, description) => sonner.info(message, { description }),
    warning: (message, description) =>
        sonner.warning(message, { description, duration: 6000 }),
    promise: (promise, messages) => sonner.promise(promise, messages),
};
