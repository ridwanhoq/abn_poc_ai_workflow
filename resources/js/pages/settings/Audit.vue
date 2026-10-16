<script>
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import JsonView from '@/components/flow/JsonView.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import PaginationBar from '@/components/flow/PaginationBar.vue';
import { errorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';

export default {
    name: 'AuditPage',
    components: {
        AppIcon,
        EmptyState,
        Head,
        JsonView,
        Link,
        PageHeader,
        PaginationBar,
    },
    data() {
        return { loading: true, logs: [], meta: null, action: '', open: {} };
    },
    mounted() {
        this.load();
    },
    methods: {
        formatDate,
        async load(page = 1) {
            this.loading = true;
            try {
                const { data } = await this.$api.get('/audit-logs', {
                    params: { page, action: this.action || undefined },
                });
                this.logs = data.data;
                this.meta = data.meta;
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<template>
    <Head title="Audit log" />
    <PageHeader
        title="Audit log"
        description="Administrative changes: workflow lifecycle, template imports, integrations, variables and access."
    >
        <Link href="/settings/profile" class="ff-btn-ghost"
            ><AppIcon name="arrow-left" :size="15" /> Settings</Link
        >
    </PageHeader>

    <div class="overflow-hidden ff-card">
        <div class="border-b border-border p-3">
            <select
                v-model="action"
                class="ff-input w-56"
                aria-label="Filter by action"
                @change="load()"
            >
                <option value="">All actions</option>
                <option value="workflow.">Workflows</option>
                <option value="template.">Templates</option>
                <option value="integration.">Integrations</option>
                <option value="variable.">Variables</option>
                <option value="user.">Users</option>
            </select>
        </div>
        <div v-if="loading" class="space-y-2 p-4">
            <div v-for="i in 6" :key="i" class="h-10 ff-skeleton" />
        </div>
        <EmptyState
            v-else-if="!logs.length"
            icon="scroll-text"
            title="No audit entries yet"
        />
        <ul v-else class="divide-y divide-border">
            <li v-for="log in logs" :key="log.id" class="px-4 py-3">
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-3 text-left"
                    @click="open[log.id] = !open[log.id]"
                >
                    <span
                        class="rounded-md bg-brand-500/10 px-2 py-0.5 font-mono text-xs font-semibold text-brand-600 dark:text-brand-300"
                        >{{ log.action }}</span
                    >
                    <span class="min-w-0 flex-1 truncate text-sm"
                        >{{ log.subject || '—' }}
                        <span class="text-muted-foreground"
                            >by {{ log.user || 'System' }}</span
                        ></span
                    >
                    <span
                        class="hidden text-xs text-muted-foreground sm:inline"
                        >{{ formatDate(log.created_at) }}</span
                    >
                    <AppIcon
                        :name="open[log.id] ? 'chevron-up' : 'chevron-down'"
                        :size="14"
                        class="text-muted-foreground"
                    />
                </button>
                <div v-if="open[log.id]" class="mt-3">
                    <JsonView
                        :value="{ changes: log.changes, ip: log.ip_address }"
                    />
                </div>
            </li>
        </ul>
        <PaginationBar :meta="meta" @page="load" />
    </div>
</template>
