<script>
import { Link } from '@inertiajs/vue3';
import { api } from '@/lib/api';
import { onExecutionUpdated } from '@/lib/realtime';
import AppIcon from './AppIcon.vue';

export default {
    name: 'ExecutionIndicator',
    components: { AppIcon, Link },
    data() {
        return { status: null, timer: null };
    },
    computed: {
        active() {
            return this.status
                ? this.status.running + this.status.queued + this.status.waiting
                : 0;
        },
        tooltip() {
            if (!this.status) {
                return 'Execution status';
            }
            const parts = [
                `${this.status.running} running`,
                `${this.status.queued} queued`,
                `${this.status.waiting} waiting`,
                `${this.status.failed_24h} failed in 24h`,
            ];
            if (this.status.worker_suspect) {
                parts.push(
                    'Queued jobs are older than a minute — is `php artisan queue:work` running?',
                );
            }
            return parts.join(' · ');
        },
    },
    mounted() {
        if (!this.$can('executions.view')) {
            return;
        }
        this.load();
        this.timer = setInterval(this.load, 10000);
        window.addEventListener('ff:execution-started', this.load);
        const workspaceId = this.$page.props.auth.user?.current_workspace?.id;
        if (workspaceId) {
            this.unsubscribe = onExecutionUpdated(
                `workspaces.${workspaceId}.executions`,
                this.load,
            );
        }
    },
    beforeUnmount() {
        clearInterval(this.timer);
        this.unsubscribe?.();
        window.removeEventListener('ff:execution-started', this.load);
    },
    methods: {
        async load() {
            if (document.hidden) {
                return;
            }
            try {
                this.status = (await api.get('/executions/status')).data;
            } catch {
                this.status = null;
            }
        },
    },
};
</script>

<template>
    <Link
        v-if="$can('executions.view')"
        href="/executions"
        class="ff-btn-ghost hidden h-9 gap-2 px-2.5 md:inline-flex"
        :title="tooltip"
    >
        <span class="relative flex size-2.5">
            <span
                v-if="active"
                class="absolute inline-flex size-full animate-ping rounded-full bg-run opacity-60"
            />
            <span
                class="relative inline-flex size-2.5 rounded-full"
                :class="
                    status?.worker_suspect
                        ? 'bg-wait'
                        : active
                          ? 'bg-run'
                          : 'bg-ok'
                "
            />
        </span>
        <span class="text-xs font-semibold text-foreground">
            <template v-if="!status">Status</template>
            <template v-else-if="status.worker_suspect">Worker idle?</template>
            <template v-else-if="active">{{ active }} in progress</template>
            <template v-else>All idle</template>
        </span>
        <span
            v-if="status && status.failed_24h"
            class="ff-chip bg-bad/15 px-1.5 py-0 text-[10px] text-bad"
        >
            <AppIcon name="circle-x" :size="10" /> {{ status.failed_24h }}
        </span>
    </Link>
</template>
