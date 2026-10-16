<script>
import { Head, Link } from '@inertiajs/vue3';
import ActivityChart from '@/components/flow/ActivityChart.vue';
import AppIcon from '@/components/flow/AppIcon.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import StatCard from '@/components/flow/StatCard.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import { errorMessage } from '@/lib/api';
import {
    formatDuration,
    timeAgo,
    triggerLabel,
    TRIGGER_LABELS,
} from '@/lib/format';

const today = () => new Date().toISOString().slice(0, 10);
const daysAgo = (n) =>
    new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);

export default {
    name: 'OverviewPage',
    components: {
        ActivityChart,
        AppIcon,
        EmptyState,
        Head,
        Link,
        PageHeader,
        StatCard,
        StatusChip,
    },
    data() {
        return {
            loading: true,
            data: null,
            filters: {
                date_from: daysAgo(13),
                date_to: today(),
                workflow_id: '',
                status: '',
                trigger_type: '',
            },
            triggerOptions: TRIGGER_LABELS,
            importing: null,
        };
    },
    computed: {
        firstName() {
            return (this.$page.props.auth.user?.name || '').split(' ')[0];
        },
        totals() {
            return this.data?.totals || {};
        },
        hasActivity() {
            return (this.data?.activity || []).some(
                (d) => d.success + d.failed + d.other > 0,
            );
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        formatDuration,
        timeAgo,
        triggerLabel,
        formatTokens(n) {
            return n >= 1_000_000
                ? (n / 1_000_000).toFixed(1) + 'M'
                : n >= 1000
                  ? (n / 1000).toFixed(1) + 'k'
                  : String(n);
        },
        formatCost(usd) {
            return usd > 0 && usd < 0.01
                ? '<$0.01'
                : '$' + Number(usd).toFixed(2);
        },
        async load() {
            this.loading = true;
            try {
                const params = Object.fromEntries(
                    Object.entries(this.filters).filter(([, v]) => v !== ''),
                );
                this.data = (
                    await this.$api.get('/dashboard', { params })
                ).data;
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load the dashboard.'),
                );
            } finally {
                this.loading = false;
            }
        },
        resetFilters() {
            this.filters = {
                date_from: daysAgo(13),
                date_to: today(),
                workflow_id: '',
                status: '',
                trigger_type: '',
            };
            this.load();
        },
        async importTemplate(template) {
            this.importing = template.id;
            try {
                const { data } = await this.$api.post(
                    `/templates/${template.id}/import`,
                );
                this.$toast.success(
                    `"${template.name}" imported`,
                    'Opening the workflow editor…',
                );
                this.$inertia.visit(`/workflows/${data.data.id}/edit`);
            } catch (error) {
                this.$toast.error(errorMessage(error, 'Import failed.'));
            } finally {
                this.importing = null;
            }
        },
    },
};
</script>

<template>
    <Head title="Overview" />

    <PageHeader
        :title="`Welcome back${firstName ? ', ' + firstName : ''} 👋`"
        description="Your automation workspace at a glance — real numbers from executed workflows."
    >
        <Link
            v-if="$can('templates.import')"
            href="/templates"
            class="ff-btn-secondary"
        >
            <AppIcon name="layout-template" :size="16" /> Browse templates
        </Link>
        <Link
            v-if="$can('workflows.view')"
            href="/workflows"
            class="ff-btn-primary"
        >
            <AppIcon name="workflow" :size="16" /> Open workflows
        </Link>
    </PageHeader>

    <!-- Filters -->
    <div class="mb-6 flex flex-wrap items-end gap-3 ff-card p-4">
        <div>
            <label class="ff-label" for="f-from">From</label>
            <input
                id="f-from"
                v-model="filters.date_from"
                type="date"
                class="ff-input w-40"
            />
        </div>
        <div>
            <label class="ff-label" for="f-to">To</label>
            <input
                id="f-to"
                v-model="filters.date_to"
                type="date"
                class="ff-input w-40"
            />
        </div>
        <div class="min-w-44 flex-1 sm:flex-none">
            <label class="ff-label" for="f-wf">Workflow</label>
            <select id="f-wf" v-model="filters.workflow_id" class="ff-input">
                <option value="">All workflows</option>
                <option
                    v-for="w in data?.workflow_options || []"
                    :key="w.id"
                    :value="w.id"
                >
                    {{ w.name }}
                </option>
            </select>
        </div>
        <div>
            <label class="ff-label" for="f-status">Status</label>
            <select
                id="f-status"
                v-model="filters.status"
                class="ff-input w-36"
            >
                <option value="">Any</option>
                <option
                    v-for="s in [
                        'success',
                        'failed',
                        'partial',
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
        </div>
        <div>
            <label class="ff-label" for="f-trigger">Trigger</label>
            <select
                id="f-trigger"
                v-model="filters.trigger_type"
                class="ff-input w-44"
            >
                <option value="">Any trigger</option>
                <option
                    v-for="(label, key) in triggerOptions"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select>
        </div>
        <div class="flex gap-2">
            <button class="ff-btn-primary" :disabled="loading" @click="load">
                <AppIcon name="filter" :size="15" /> Apply
            </button>
            <button class="ff-btn-ghost" @click="resetFilters">Reset</button>
        </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-6">
        <StatCard
            label="Total workflows"
            :value="totals.workflows"
            icon="workflow"
            color="#6161ff"
            :loading="loading"
        />
        <StatCard
            label="Active"
            :value="totals.active_workflows"
            icon="zap"
            color="#00c875"
            :loading="loading"
            hint="Responding to triggers"
        />
        <StatCard
            label="Successful runs"
            :value="totals.successful"
            icon="circle-check"
            color="#00c875"
            :loading="loading"
        />
        <StatCard
            label="Failed runs"
            :value="totals.failed"
            icon="circle-x"
            color="#e2445c"
            :loading="loading"
        />
        <StatCard
            label="Success rate"
            :value="
                totals.success_rate === null ||
                totals.success_rate === undefined
                    ? '—'
                    : totals.success_rate + '%'
            "
            icon="gauge"
            color="#a25ddc"
            :loading="loading"
            :hint="totals.partial ? `${totals.partial} partial` : ''"
        />
        <StatCard
            label="Avg. duration"
            :value="
                totals.avg_duration_ms
                    ? formatDuration(totals.avg_duration_ms)
                    : '—'
            "
            icon="timer"
            color="#579bfc"
            :loading="loading"
            :hint="
                totals.in_progress ? `${totals.in_progress} in progress` : ''
            "
        />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <!-- Activity chart -->
        <section class="ff-card p-5 xl:col-span-2">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="font-bold">Workflow activity</h2>
                    <p class="text-xs text-muted-foreground">
                        Executions per day · {{ data?.range?.from }} →
                        {{ data?.range?.to }}
                    </p>
                </div>
            </div>
            <div v-if="loading" class="h-64 ff-skeleton" />
            <ActivityChart v-else-if="hasActivity" :activity="data.activity" />
            <EmptyState
                v-else
                icon="activity"
                title="No executions in this range"
                description="Import a template and run it to see activity here."
            />
        </section>

        <!-- Quick start -->
        <section class="ff-card p-5">
            <h2 class="font-bold">Quick start</h2>
            <p class="mb-4 text-xs text-muted-foreground">Common next steps</p>
            <div class="space-y-2">
                <Link
                    v-for="action in [
                        {
                            href: '/templates',
                            icon: 'layout-template',
                            color: '#6161ff',
                            title: 'Import a template',
                            text: 'Six ready-to-run OTT & ERP workflows',
                            perm: 'templates.import',
                        },
                        {
                            href: '/workflows',
                            icon: 'workflow',
                            color: '#00c875',
                            title: 'Build a workflow',
                            text: 'Drag, connect and configure nodes',
                            perm: 'workflows.create',
                        },
                        {
                            href: '/data?upload=1',
                            icon: 'upload',
                            color: '#a25ddc',
                            title: 'Upload a video',
                            text: 'Fires File Uploaded workflows',
                            perm: 'workflows.execute',
                        },
                        {
                            href: '/playground',
                            icon: 'flask-conical',
                            color: '#fdab3d',
                            title: 'Try the AI playground',
                            text: 'Test mock AI adapters',
                            perm: 'playground.use',
                        },
                    ].filter((a) => $can(a.perm))"
                    :key="action.href"
                    :href="action.href"
                    class="group flex items-center gap-3 rounded-xl border border-border p-3 transition hover:border-brand-300 hover:bg-brand-500/5"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-lg"
                        :style="{
                            backgroundColor: action.color + '1f',
                            color: action.color,
                        }"
                    >
                        <AppIcon :name="action.icon" :size="17" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">{{
                            action.title
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ action.text }}</span
                        >
                    </span>
                    <AppIcon
                        name="chevron-right"
                        :size="16"
                        class="text-muted-foreground transition group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <!-- Recent executions -->
        <section class="overflow-hidden ff-card xl:col-span-2">
            <div class="flex items-center justify-between px-5 pt-5 pb-3">
                <h2 class="font-bold">Recent executions</h2>
                <Link
                    v-if="$can('executions.view')"
                    href="/executions"
                    class="text-sm font-semibold text-brand-500 hover:underline"
                    >View all</Link
                >
            </div>
            <div v-if="loading" class="space-y-2 px-5 pb-5">
                <div v-for="i in 4" :key="i" class="h-10 ff-skeleton" />
            </div>
            <EmptyState
                v-else-if="!data?.recent_executions?.length"
                icon="history"
                title="No executions yet"
            />
            <div v-else class="overflow-x-auto">
                <table class="ff-table">
                    <thead>
                        <tr>
                            <th>Workflow</th>
                            <th>Status</th>
                            <th class="hidden sm:table-cell">Trigger</th>
                            <th class="hidden md:table-cell">Duration</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in data.recent_executions"
                            :key="e.id"
                            class="cursor-pointer"
                            @click="$inertia.visit(`/executions/${e.id}`)"
                        >
                            <td class="max-w-56 truncate font-semibold">
                                {{ e.workflow || 'Deleted workflow' }}
                                <span
                                    v-if="e.mode === 'test'"
                                    class="ml-1 text-[10px] font-bold text-muted-foreground uppercase"
                                    >test</span
                                >
                            </td>
                            <td><StatusChip :status="e.status" size="sm" /></td>
                            <td
                                class="hidden text-muted-foreground sm:table-cell"
                            >
                                {{ triggerLabel(e.trigger_type) }}
                            </td>
                            <td
                                class="hidden text-muted-foreground md:table-cell"
                            >
                                {{ formatDuration(e.duration_ms) }}
                            </td>
                            <td class="text-muted-foreground">
                                {{ timeAgo(e.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="space-y-6">
            <!-- Frequently used -->
            <section class="ff-card p-5">
                <h2 class="mb-3 font-bold">Frequently used workflows</h2>
                <div v-if="loading" class="space-y-2">
                    <div v-for="i in 3" :key="i" class="h-8 ff-skeleton" />
                </div>
                <p
                    v-else-if="!data?.top_workflows?.length"
                    class="text-sm text-muted-foreground"
                >
                    No runs in this range yet.
                </p>
                <ul v-else class="space-y-3">
                    <li v-for="w in data.top_workflows" :key="w.id">
                        <div class="flex items-center justify-between text-sm">
                            <Link
                                :href="`/workflows/${w.id}/edit`"
                                class="truncate font-semibold hover:text-brand-500"
                                >{{ w.name }}</Link
                            >
                            <span class="text-xs text-muted-foreground"
                                >{{ w.runs }} runs</span
                            >
                        </div>
                        <div
                            class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-ok transition-all"
                                :style="{ width: (w.success_rate || 0) + '%' }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <!-- AI usage -->
            <section class="ff-card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-bold">AI usage</h2>
                    <span class="text-xs text-muted-foreground"
                        >in selected range</span
                    >
                </div>
                <div v-if="loading" class="h-20 ff-skeleton" />
                <p
                    v-else-if="!data?.ai_usage?.calls"
                    class="text-sm text-muted-foreground"
                >
                    No AI calls in this range.
                </p>
                <template v-else>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <div class="text-lg font-extrabold">
                                {{ data.ai_usage.calls }}
                            </div>
                            <div class="text-[11px] text-muted-foreground">
                                calls
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <div class="text-lg font-extrabold">
                                {{
                                    formatTokens(
                                        data.ai_usage.input_tokens +
                                            data.ai_usage.output_tokens,
                                    )
                                }}
                            </div>
                            <div class="text-[11px] text-muted-foreground">
                                tokens
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <div class="text-lg font-extrabold">
                                {{
                                    formatCost(data.ai_usage.estimated_cost_usd)
                                }}
                            </div>
                            <div class="text-[11px] text-muted-foreground">
                                est. cost
                            </div>
                        </div>
                    </div>
                    <ul class="mt-3 space-y-1.5 text-xs">
                        <li
                            v-for="m in data.ai_usage.by_model"
                            :key="m.provider + m.model"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="ff-chip px-1.5 py-0 text-[10px]"
                                :class="
                                    m.simulated
                                        ? 'bg-cat-ai/15 text-cat-ai'
                                        : 'bg-ok/15 text-ok'
                                "
                                >{{ m.simulated ? 'mock' : m.provider }}</span
                            >
                            <span class="min-w-0 flex-1 truncate font-mono">{{
                                m.model
                            }}</span>
                            <span class="text-muted-foreground"
                                >{{ m.calls }} × ·
                                {{ formatCost(m.estimated_cost_usd) }}</span
                            >
                        </li>
                    </ul>
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        Estimates use list prices from
                        <code>config/workflows.php</code>; simulated calls cost
                        $0.
                    </p>
                </template>
            </section>

            <!-- Suggested templates -->
            <section v-if="$can('templates.view')" class="ff-card p-5">
                <h2 class="mb-3 font-bold">Suggested templates</h2>
                <div v-if="loading" class="space-y-2">
                    <div v-for="i in 3" :key="i" class="h-12 ff-skeleton" />
                </div>
                <div v-else class="space-y-2">
                    <div
                        v-for="t in data?.suggested_templates || []"
                        :key="t.id"
                        class="flex items-center gap-3 rounded-xl border border-border p-3"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg text-white"
                            :style="{ backgroundColor: t.color }"
                        >
                            <AppIcon :name="t.icon" :size="17" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm font-semibold"
                                >{{ t.name }}</span
                            >
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ t.category }}</span
                            >
                        </span>
                        <button
                            v-if="$can('templates.import')"
                            class="ff-btn-secondary ff-btn-sm"
                            :disabled="importing === t.id"
                            @click="importTemplate(t)"
                        >
                            {{ importing === t.id ? 'Importing…' : 'Use' }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
