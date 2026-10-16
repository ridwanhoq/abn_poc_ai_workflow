import axios from 'axios';

/**
 * Axios instance for the session-authenticated REST API (/api/v1).
 * Laravel sets the XSRF-TOKEN cookie; axios echoes it as X-XSRF-TOKEN.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    timeout: 30000,
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            window.location.href = '/login';
        }

        return Promise.reject(error);
    },
);

/**
 * Human-readable message for any API error (never a stack trace).
 */
export function errorMessage(
    error,
    fallback = 'Something went wrong. Please try again.',
) {
    const response = error?.response;

    if (!response) {
        return error?.code === 'ECONNABORTED'
            ? 'The request timed out.'
            : 'Network error — check your connection.';
    }

    const data = response.data || {};

    if (response.status === 403) {
        return data.message && data.message !== 'This action is unauthorized.'
            ? data.message
            : 'You do not have permission to do that.';
    }

    if (response.status === 419) {
        return 'Your session expired. Refresh the page and try again.';
    }

    if (response.status === 429) {
        return 'Too many requests. Please wait a moment.';
    }

    if (data.errors && typeof data.errors === 'object') {
        const first = Object.values(data.errors)[0];

        if (Array.isArray(first) && first.length) {
            return first[0];
        }
    }

    if (response.status >= 500) {
        return fallback;
    }

    return data.message || fallback;
}

/**
 * Field => first message map for form validation errors.
 */
export function fieldErrors(error) {
    const errors = error?.response?.data?.errors || {};
    const out = {};

    for (const [key, messages] of Object.entries(errors)) {
        out[key] = Array.isArray(messages) ? messages[0] : String(messages);
    }

    return out;
}
