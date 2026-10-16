<script>
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import PaginationBar from '@/components/flow/PaginationBar.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import { errorMessage } from '@/lib/api';
import { onExecutionUpdated, realtime } from '@/lib/realtime';
import {
    formatDate,
    formatDuration,
    timeAgo,
    triggerLabel,
    TRIGGER_LABELS,
} from '@/lib/format';

export default {
    name: 'ExecutionsIndex',
    components: {
        AppIcon,
        EmptyState,
        Head,
        Link,
        PageHeader,
        PaginationBar,
        StatusChip,
    },
    data() {
        const params = new URLSearchParams(window.location.search);
        return {
            loading: true,
            executions: [],
            meta: null,
            workflows: [],
            filters: {
                status: params.get('status') || '',
                workflow_id: params.get('workflow_id') || '',
                trigger_type: '',
                mode: '',
                date_from: '',
                date_to: '',
                page: 1,
            },
            triggerOptions: TRIGGER_LABELS,
            timer: null,
        };
    },
    computed: {
        hasActive() {
            return this.executions.some((e) =>
                ['queued', 'running', 'waiting'].includes(e.status),
            );
        },
    },
    async mounted() {
        this.load();
        try {
            this.workflows = (
                await this.$api.get('/workflows', {
                    params: { per_page: 100, sort: 'name', direction: 'asc' },
                })
            ).data.data;
        } catch {
            // filter list is optional
        }
    },
    created() {
        const workspaceId = this.$page.props.auth.user?.current_workspace?.id;
        if (workspaceId) {
            this.unsubscribe = onExecutionUpdated(
                `workspaces.${workspaceId}.executions`,
                () => this.load(true),
            );
        }
    },
    beforeUnmount() {
        clearTimeout(this.timer);
        this.unsubscribe?.();
    },
    methods: {
        formatDate,
        formatDuration,
        timeAgo,
        triggerLabel,
        async load(silent = false) {
            clearTimeout(this.timer);
            if (!silent) {
                this.loading = true;
            }
            try {
                const params = Object.fromEntries(
                    Object.entries(this.filters).filter(([, v]) => v !== ''),
                );
                const { data } = await this.$api.get('/executions', { params });
                this.executions = data.data;
                this.meta = data.meta;
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load executions.'),
                );
            } finally {
                this.loading = false;
                if (this.hasActive) {
                    this.timer = setTimeout(
                        () => this.load(true),
                        realtime.connected ? 15000 : 3000,
                    );
                }
            }
        },
        apply() {
            this.filters.page = 1;
            this.load();
        },
        goPage(page) {
            this.filters.page = page;
            this.load();
        },
    },
};
</script>

<template>
    <Head title="Executions" />

    <PageHeader
        title="Executions"
        description="Every run with its trigger, status, timing and node-by-node results."
    >
        <button class="ff-btn-secondary" :disabled="loading" @click="load()">
            <AppIcon
                name="refresh-cw"
                :size="15"
                :class="{ 'animate-spin': loading }"
            />
            Refresh
        </button>
    </PageHeader>

    <div class="overflow-hidden ff-card">
        <div class="flex flex-wrap items-end gap-2 border-b border-border p-3">
            <select
                v-model="filters.status"
                class="ff-input w-36"
                aria-label="Status"
                @change="apply"
            >
                <option value="">All statuses</option>
                <option
                    v-for="s in [
                        'success',
                        'partial',
                        'failed',
                        'running',
                        'queued',
                        'waiting',
                        'cancelled',
                    ]"
                    :key="s"
                    :value="s"
                >
                    {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                </option>
            </select>
            <select
                v-model="filters.workflow_id"
                class="ff-input w-56"
                aria-label="Workflow"
                @change="apply"
            >
                <option value="">All workflows</option>
                <option
                    v-for="w in workflows"
                    :key="w.id"
                    :value="String(w.id)"
                >
                    {{ w.name }}
                </option>
            </select>
            <select
                v-model="filters.trigger_type"
                class="ff-input w-44"
                aria-label="Trigger"
                @change="apply"
            >
                <option value="">All triggers</option>
                <option
                    v-for="(label, key) in triggerOptions"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select>
            <select
                v-model="filters.mode"
                class="ff-input w-36"
                aria-label="Mode"
                @change="apply"
            >
                <option value="">Test & production</option>
                <option value="test">Test runs</option>
                <option value="production">Production</option>
            </select>
            <input
                v-model="filters.date_from"
                type="date"
                class="ff-input w-40"
                aria-label="From date"
                @change="apply"
            />
            <input
                v-model="filters.date_to"
                type="date"
                class="ff-input w-40"
                aria-label="To date"
                @change="apply"
            />
        </div>

        <div v-if="loading && !executions.length" class="space-y-2 p-4">
            <div v-for="i in 6" :key="i" class="h-11 ff-skeleton" />
        </div>
        <EmptyState
            v-else-if="!executions.length"
            icon="history"
            title="No executions found"
            description="Run a workflow or adjust the filters."
        />
        <div v-else class="overflow-x-auto">
            <table class="ff-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Workflow</th>
                        <th>Status</th>
                        <th class="hidden md:table-cell">Trigger</th>
                        <th class="hidden lg:table-cell">Started</th>
                        <th class="hidden sm:table-cell">Duration</th>
                        <th class="hidden xl:table-cell">Initiated by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in executions"
                        :key="e.id"
                        class="cursor-pointer"
                        @click="$inertia.visit(`/executions/${e.id}`)"
                    >
                        <td class="font-mono text-xs text-muted-foreground">
                            #{{ e.id }}
                        </td>
                        <td class="max-w-72">
                            <div class="truncate font-semibold">
                                {{ e.workflow?.name || '—' }}
                            </div>
                            <div
                                class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                            >
                                <span
                                    v-if="e.mode === 'test'"
                                    class="font-bold uppercase"
                                    >Test run</span
                                >
                                <span v-if="e.retry_of_id"
                                    >· retry of #{{ e.retry_of_id }}</span
                                >
                                <span v-if="e.start_node_key"
                                    >· from {{ e.start_node_key }}</span
                                >
                            </div>
                        </td>
                        <td>
                            <StatusChip :status="e.status" size="sm" />
                            <div
                                v-if="e.error_message"
                                class="mt-1 max-w-64 truncate text-[11px] text-bad"
                                :title="e.error_message"
                            >
                                {{ e.error_message }}
                            </div>
                        </td>
                        <td class="hidden text-muted-foreground md:table-cell">
                            {{ triggerLabel(e.trigger_type) }}
                        </td>
                        <td
                            class="hidden text-muted-foreground lg:table-cell"
                            :title="formatDate(e.started_at || e.created_at)"
                        >
                            {{ timeAgo(e.started_at || e.created_at) }}
                        </td>
                        <td class="hidden text-muted-foreground sm:table-cell">
                            {{ formatDuration(e.duration_ms) }}
                        </td>
                        <td class="hidden text-muted-foreground xl:table-cell">
                            {{ e.initiator?.name || 'System' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationBar :meta="meta" @page="goPage" />
    </div>
</template>
