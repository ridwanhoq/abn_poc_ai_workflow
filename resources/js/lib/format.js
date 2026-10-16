export const STATUS_META = {
    success: { label: 'Success', color: '#00c875', icon: 'circle-check' },
    failed: { label: 'Failed', color: '#e2445c', icon: 'circle-x' },
    partial: { label: 'Partial', color: '#ffcb00', icon: 'circle-alert' },
    running: { label: 'Running', color: '#579bfc', icon: 'loader-circle' },
    queued: { label: 'Queued', color: '#fdab3d', icon: 'clock' },
    waiting: { label: 'Waiting', color: '#a25ddc', icon: 'hourglass' },
    cancelled: { label: 'Cancelled', color: '#9ca3af', icon: 'ban' },
    skipped: { label: 'Skipped', color: '#c3c6d4', icon: 'circle-dashed' },
    active: { label: 'Active', color: '#00c875', icon: 'zap' },
    draft: { label: 'Draft', color: '#9ca3af', icon: 'pencil' },
    inactive: { label: 'Inactive', color: '#fdab3d', icon: 'pause' },
    connected: { label: 'Connected', color: '#00c875', icon: 'plug-zap' },
    simulated: { label: 'Simulated', color: '#a25ddc', icon: 'flask-conical' },
    not_configured: {
        label: 'Not configured',
        color: '#fdab3d',
        icon: 'settings',
    },
    unavailable: { label: 'Unavailable', color: '#9ca3af', icon: 'ban' },
    error: { label: 'Error', color: '#e2445c', icon: 'circle-x' },
    implemented: { label: 'Implemented', color: '#00c875', icon: 'check' },
    integration_required: {
        label: 'Needs integration',
        color: '#fdab3d',
        icon: 'plug',
    },
};

export const CATEGORY_META = {
    trigger: { label: 'Triggers', color: '#ff7575', icon: 'zap' },
    logic: { label: 'Logic', color: '#fdab3d', icon: 'git-fork' },
    data: { label: 'Data', color: '#579bfc', icon: 'database' },
    notification: {
        label: 'Notifications',
        color: '#00c875',
        icon: 'bell-ring',
    },
    ai_media: { label: 'AI & Media', color: '#a25ddc', icon: 'sparkles' },
    utility: { label: 'Utilities', color: '#7e8494', icon: 'wrench' },
};

export const TRIGGER_LABELS = {
    manual: 'Manual',
    schedule: 'Schedule',
    webhook: 'Webhook',
    database_event: 'Database event',
    file_uploaded: 'File uploaded',
    user_created: 'User created',
    user_verification_pending: 'Verification pending',
    subworkflow: 'Subworkflow',
};

export function statusMeta(status) {
    return (
        STATUS_META[status] || {
            label: status || 'Unknown',
            color: '#9ca3af',
            icon: 'circle',
        }
    );
}

export function triggerLabel(type) {
    return TRIGGER_LABELS[type] || type || '—';
}

export function formatDuration(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }

    if (ms < 1000) {
        return `${Math.round(ms)} ms`;
    }

    if (ms < 60000) {
        return `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} s`;
    }

    const minutes = Math.floor(ms / 60000);

    return `${minutes}m ${Math.round((ms % 60000) / 1000)}s`;
}

export function formatDate(value, withTime = true) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year:
            date.getFullYear() === new Date().getFullYear()
                ? undefined
                : 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    });
}

export function timeAgo(value) {
    if (!value) {
        return '—';
    }

    const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);

    if (seconds < 45) {
        return 'just now';
    }

    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return new Intl.RelativeTimeFormat(undefined, {
                numeric: 'auto',
            }).format(-Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export function pretty(value) {
    if (value === null || value === undefined) {
        return 'null';
    }

    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

export function debounce(fn, wait = 300) {
    let timer = null;

    return function debounced(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}
