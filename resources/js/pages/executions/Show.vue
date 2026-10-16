<script>
import { Head, Link } from '@inertiajs/vue3';
import GraphPreview from '@/components/editor/GraphPreview.vue';
import AppIcon from '@/components/flow/AppIcon.vue';
import JsonView from '@/components/flow/JsonView.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import { errorMessage } from '@/lib/api';
import { onExecutionUpdated, realtime } from '@/lib/realtime';
import {
    formatDate,
    formatDuration,
    statusMeta,
    timeAgo,
    triggerLabel,
} from '@/lib/format';

const ACTIVE = ['queued', 'running', 'waiting'];

export default {
    name: 'ExecutionShow',
    components: { AppIcon, GraphPreview, Head, JsonView, Link, StatusChip },
    props: {
        executionId: { type: Number, required: true },
    },
    data() {
        return {
            loading: true,
            execution: null,
            steps: [],
            stepsTotal: 0,
            nodeSummary: {},
            logs: [],
            aiUsage: [],
            definition: null,
            typeMap: {},
            expanded: {},
            filter: '',
            tab: 'timeline',
            timer: null,
            acting: false,
        };
    },
    computed: {
        isActive() {
            return this.execution && ACTIVE.includes(this.execution.status);
        },
        runByNode() {
            const map = {};
            for (const step of this.steps) {
                map[step.node_key] = {
                    status: step.status,
                    duration_ms: step.duration_ms,
                    count: (map[step.node_key]?.count || 0) + 1,
                };
            }
            return map;
        },
        visibleSteps() {
            return this.filter
                ? this.steps.filter((s) => s.status === this.filter)
                : this.steps;
        },
        counts() {
            const out = {};
            this.steps.forEach(
                (s) => (out[s.status] = (out[s.status] || 0) + 1),
            );
            return out;
        },
        maxDuration() {
            return Math.max(1, ...this.steps.map((s) => s.duration_ms || 0));
        },
    },
    async mounted() {
        try {
            const types = await this.$api.get('/node-types');
            this.typeMap = Object.fromEntries(
                types.data.data.map((t) => [t.type, t]),
            );
        } catch {
            // diagram falls back to generic nodes
        }
        this.unsubscribe = onExecutionUpdated(
            `executions.${this.executionId}`,
            () => this.load(),
        );
        await this.load();
    },
    beforeUnmount() {
        clearTimeout(this.timer);
        this.unsubscribe?.();
    },
    methods: {
        formatDate,
        formatDuration,
        statusMeta,
        timeAgo,
        triggerLabel,
        async load() {
            clearTimeout(this.timer);
            try {
                const { data } = await this.$api.get(
                    `/executions/${this.executionId}`,
                    { params: { steps_limit: 1000 } },
                );
                this.execution = data.data;
                this.steps = data.steps;
                this.stepsTotal = data.steps_total;
                this.nodeSummary = data.node_summary;
                this.logs = data.logs;
                this.aiUsage = data.ai_usage || [];
                this.definition = data.definition;
                if (
                    this.loading &&
                    this.steps.some((s) => s.status === 'failed')
                ) {
                    const failed = this.steps.find(
                        (s) => s.status === 'failed',
                    );
                    this.expanded[failed.id] = true;
                }
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load the execution.'),
                );
            } finally {
                this.loading = false;
                if (this.isActive) {
                    this.timer = setTimeout(
                        this.load,
                        realtime.connected ? 5000 : 1500,
                    );
                }
            }
        },
        icon(type) {
            return this.typeMap[type]?.icon || 'box';
        },
        async cancel() {
            const ok = await this.$confirm({
                title: 'Cancel this execution?',
                message:
                    'Queued or waiting runs stop immediately; running ones stop before the next node.',
                confirmText: 'Cancel execution',
                danger: true,
            });
            if (!ok) {
                return;
            }
            this.acting = true;
            try {
                await this.$api.post(`/executions/${this.execution.id}/cancel`);
                this.$toast.success('Cancellation requested');
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.acting = false;
            }
        },
        async retry() {
            const ok = await this.$confirm({
                title: 'Retry with the same trigger data?',
                message:
                    'A new execution is created using the current saved version of the workflow.',
                confirmText: 'Retry',
            });
            if (!ok) {
                return;
            }
            this.acting = true;
            try {
                const { data } = await this.$api.post(
                    `/executions/${this.execution.id}/retry`,
                );
                window.dispatchEvent(new Event('ff:execution-started'));
                this.$toast.success(`Retry started as #${data.data.id}`);
                this.$inertia.visit(`/executions/${data.data.id}`);
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.acting = false;
            }
        },
        jumpToNode(key) {
            this.tab = 'timeline';
            const step = [...this.steps]
                .reverse()
                .find((s) => s.node_key === key);
            if (step) {
                this.expanded[step.id] = true;
                this.$nextTick(() =>
                    document.getElementById(`step-${step.id}`)?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    }),
                );
            }
        },
    },
};
</script>

<template>
    <Head :title="execution ? `Execution #${execution.id}` : 'Execution'" />

    <div v-if="loading" class="space-y-4">
        <div class="h-10 w-72 ff-skeleton" />
        <div class="h-32 ff-skeleton" />
        <div class="h-96 ff-skeleton" />
    </div>

    <template v-else-if="execution">
        <div class="mb-5 flex flex-wrap items-start gap-3">
            <Link
                href="/executions"
                class="mt-1 ff-icon-btn"
                aria-label="Back to executions"
                ><AppIcon name="arrow-left" :size="17"
            /></Link>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-extrabold tracking-tight">
                        Execution #{{ execution.id }}
                    </h1>
                    <StatusChip :status="execution.status" />
                    <span
                        v-if="execution.mode === 'test'"
                        class="ff-chip bg-run/15 text-run"
                        >Test run</span
                    >
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    <Link
                        v-if="execution.workflow && !execution.workflow.deleted"
                        :href="`/workflows/${execution.workflow.id}/edit`"
                        class="font-semibold text-foreground hover:text-brand-500"
                        >{{ execution.workflow.name }}</Link
                    >
                    <span v-else>{{
                        execution.workflow?.name || 'Deleted workflow'
                    }}</span>
                    · v{{ execution.workflow_version }} ·
                    <span class="font-mono text-xs">{{ execution.uuid }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <button class="ff-btn-secondary" @click="load">
                    <AppIcon name="refresh-cw" :size="15" /> Refresh
                </button>
                <button
                    v-if="execution.can.cancel"
                    class="ff-btn-danger"
                    :disabled="acting"
                    @click="cancel"
                >
                    <AppIcon name="ban" :size="15" /> Cancel
                </button>
                <button
                    v-if="execution.can.retry"
                    class="ff-btn-primary"
                    :disabled="acting"
                    @click="retry"
                >
                    <AppIcon name="rotate-ccw" :size="15" /> Retry
                </button>
            </div>
        </div>

        <div
            v-if="execution.error_message"
            class="mb-5 flex gap-3 rounded-2xl border p-4"
            :class="
                execution.status === 'partial'
                    ? 'border-part/40 bg-part/10'
                    : 'border-bad/30 bg-bad/8'
            "
        >
            <AppIcon
                name="triangle-alert"
                :size="18"
                class="mt-0.5 shrink-0"
                :class="
                    execution.status === 'partial' ? 'text-wait' : 'text-bad'
                "
            />
            <div>
                <div class="font-bold">
                    {{
                        execution.status === 'partial'
                            ? 'Finished with failures'
                            : execution.status === 'cancelled'
                              ? 'Cancelled'
                              : 'Execution failed'
                    }}
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ execution.error_message }}
                </p>
            </div>
        </div>
        <div
            v-if="execution.status === 'queued'"
            class="mb-5 flex gap-3 rounded-2xl border border-wait/40 bg-wait/10 p-4 text-sm"
        >
            <AppIcon name="clock" :size="18" class="mt-0.5 text-wait" />
            <p>
                Waiting for a queue worker. If this persists, start one with
                <code class="font-mono">php artisan queue:work</code>.
            </p>
        </div>
        <div
            v-if="execution.status === 'waiting'"
            class="mb-5 flex gap-3 rounded-2xl border border-cat-ai/40 bg-cat-ai/10 p-4 text-sm"
        >
            <AppIcon name="hourglass" :size="18" class="mt-0.5 text-cat-ai" />
            <p>
                Paused by a Delay node. Resumes
                {{
                    timeAgo(execution.resume_at) === 'just now'
                        ? 'shortly'
                        : 'at ' + formatDate(execution.resume_at)
                }}.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="ff-card p-4">
                <div class="ff-label">Trigger</div>
                <div class="font-semibold">
                    {{ triggerLabel(execution.trigger_type) }}
                </div>
                <div class="text-xs text-muted-foreground">
                    by {{ execution.initiator?.name || 'System' }}
                </div>
            </div>
            <div class="ff-card p-4">
                <div class="ff-label">Started</div>
                <div class="font-semibold">
                    {{ formatDate(execution.started_at) }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ timeAgo(execution.started_at || execution.created_at) }}
                </div>
            </div>
            <div class="ff-card p-4">
                <div class="ff-label">Finished</div>
                <div class="font-semibold">
                    {{ formatDate(execution.finished_at) }}
                </div>
            </div>
            <div class="ff-card p-4">
                <div class="ff-label">Duration</div>
                <div class="font-semibold">
                    {{ formatDuration(execution.duration_ms) }}
                </div>
            </div>
            <div class="ff-card p-4">
                <div class="ff-label">Node executions</div>
                <div class="font-semibold">{{ execution.node_executions }}</div>
                <div class="text-xs text-muted-foreground">
                    {{ stepsTotal }} step records
                </div>
            </div>
        </div>

        <div
            class="mt-6 flex gap-1 overflow-x-auto rounded-xl bg-muted p-1 text-sm font-semibold sm:inline-flex"
        >
            <button
                v-for="t in [
                    { key: 'timeline', label: 'Timeline', icon: 'list-checks' },
                    { key: 'diagram', label: 'Diagram', icon: 'workflow' },
                    { key: 'data', label: 'Trigger & result', icon: 'braces' },
                    {
                        key: 'logs',
                        label: `Logs (${logs.length})`,
                        icon: 'scroll-text',
                    },
                ]"
                :key="t.key"
                type="button"
                class="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 whitespace-nowrap transition"
                :class="
                    tab === t.key
                        ? 'bg-card shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="tab = t.key"
            >
                <AppIcon :name="t.icon" :size="14" /> {{ t.label }}
            </button>
        </div>

        <!-- Timeline -->
        <section
            v-show="tab === 'timeline'"
            class="mt-4 overflow-hidden ff-card"
        >
            <div
                class="flex flex-wrap items-center gap-2 border-b border-border p-3"
            >
                <button
                    v-for="s in [
                        '',
                        'success',
                        'failed',
                        'skipped',
                        'running',
                        'cancelled',
                    ]"
                    :key="s || 'all'"
                    type="button"
                    class="ff-chip cursor-pointer px-3 py-1"
                    :class="
                        filter === s
                            ? 'bg-brand-500 text-white'
                            : 'bg-muted text-muted-foreground hover:text-foreground'
                    "
                    @click="filter = s"
                >
                    {{ s ? statusMeta(s).label : 'All' }}
                    {{ s ? counts[s] || 0 : steps.length }}
                </button>
                <span
                    v-if="stepsTotal > steps.length"
                    class="ml-auto text-xs text-muted-foreground"
                    >Showing first {{ steps.length }} of
                    {{ stepsTotal }} steps</span
                >
            </div>
            <ol class="divide-y divide-border">
                <li
                    v-for="step in visibleSteps"
                    :id="`step-${step.id}`"
                    :key="step.id"
                >
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left hover:bg-muted/40"
                        :aria-expanded="Boolean(expanded[step.id])"
                        @click="expanded[step.id] = !expanded[step.id]"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                            :style="{
                                backgroundColor:
                                    statusMeta(step.status).color + '22',
                                color: statusMeta(step.status).color,
                            }"
                        >
                            <AppIcon :name="icon(step.node_type)" :size="15" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">
                                {{ step.node_name }}
                                <span
                                    class="font-mono text-xs font-normal text-muted-foreground"
                                    >{{ step.node_key }}</span
                                >
                            </span>
                            <span
                                class="flex flex-wrap gap-x-2 text-[11px] text-muted-foreground"
                            >
                                <span>{{
                                    typeMap[step.node_type]?.label ||
                                    step.node_type
                                }}</span>
                                <span v-if="step.iteration !== null"
                                    >· item {{ step.iteration }}</span
                                >
                                <span v-if="step.attempt > 1"
                                    >· attempt {{ step.attempt }}</span
                                >
                                <span
                                    v-if="
                                        typeMap[step.node_type]
                                            ?.implementation === 'simulated'
                                    "
                                    class="text-cat-ai"
                                    >· simulated</span
                                >
                            </span>
                            <span
                                v-if="step.error"
                                class="mt-0.5 block truncate text-xs text-bad"
                                >{{ step.error }}</span
                            >
                        </span>
                        <span class="hidden w-28 sm:block">
                            <span
                                class="block h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full"
                                    :style="{
                                        width:
                                            Math.max(
                                                3,
                                                ((step.duration_ms || 0) /
                                                    maxDuration) *
                                                    100,
                                            ) + '%',
                                        backgroundColor: statusMeta(step.status)
                                            .color,
                                    }"
                                />
                            </span>
                        </span>
                        <span
                            class="w-16 text-right text-xs text-muted-foreground"
                            >{{ formatDuration(step.duration_ms) }}</span
                        >
                        <StatusChip :status="step.status" size="sm" />
                        <AppIcon
                            :name="
                                expanded[step.id]
                                    ? 'chevron-up'
                                    : 'chevron-down'
                            "
                            :size="15"
                            class="text-muted-foreground"
                        />
                    </button>
                    <div
                        v-if="expanded[step.id]"
                        class="grid gap-4 bg-muted/30 px-4 py-4 lg:grid-cols-2"
                    >
                        <JsonView :value="step.input" label="Input" />
                        <div class="space-y-3">
                            <div
                                v-if="step.error"
                                class="rounded-xl border border-bad/30 bg-bad/8 p-3 text-sm text-bad"
                            >
                                <div class="mb-1 text-xs font-bold uppercase">
                                    Error
                                </div>
                                {{ step.error }}
                            </div>
                            <JsonView
                                v-if="step.status !== 'skipped'"
                                :value="step.output"
                                label="Output"
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                Skipped — no incoming branch was active for this
                                node.
                            </p>
                        </div>
                        <p
                            class="text-[11px] text-muted-foreground lg:col-span-2"
                        >
                            {{
                                step.started_at
                                    ? formatDate(step.started_at)
                                    : ''
                            }}
                            · Sensitive keys and personal data are redacted in
                            stored execution data.
                        </p>
                    </div>
                </li>
            </ol>
            <p
                v-if="!visibleSteps.length"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                No steps{{ filter ? ' with this status' : ' recorded yet' }}.
            </p>
        </section>

        <!-- Diagram -->
        <section v-if="tab === 'diagram'" class="mt-4 ff-card p-4">
            <p class="mb-3 text-xs text-muted-foreground">
                Current workflow definition with this run's node results. Click
                a node to jump to its step.
            </p>
            <GraphPreview
                v-if="definition"
                :definition="definition"
                :type-map="typeMap"
                :run-by-node="runByNode"
                height="h-[520px]"
                @node-click="jumpToNode"
            />
        </section>

        <!-- Data -->
        <section v-show="tab === 'data'" class="mt-4 grid gap-4 lg:grid-cols-2">
            <div
                v-if="aiUsage.length"
                class="overflow-hidden ff-card lg:col-span-2"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <h2 class="font-bold">AI usage</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ aiUsage.length }} call{{
                            aiUsage.length === 1 ? '' : 's'
                        }}
                        · est. ${{
                            aiUsage
                                .reduce(
                                    (sum, u) => sum + u.estimated_cost_usd,
                                    0,
                                )
                                .toFixed(4)
                        }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="ff-table text-xs">
                        <thead>
                            <tr>
                                <th>Node</th>
                                <th>Task</th>
                                <th>Model</th>
                                <th>Input</th>
                                <th>Output</th>
                                <th>Est. cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(u, i) in aiUsage" :key="i">
                                <td class="font-mono">{{ u.node_key }}</td>
                                <td>{{ u.task || '—' }}</td>
                                <td>
                                    <span class="font-mono">{{ u.model }}</span>
                                    <span
                                        v-if="u.simulated"
                                        class="ml-1 text-cat-ai"
                                        >(simulated)</span
                                    >
                                </td>
                                <td>{{ u.input_tokens }}</td>
                                <td>{{ u.output_tokens }}</td>
                                <td>${{ u.estimated_cost_usd.toFixed(4) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="ff-card p-4">
                <JsonView
                    :value="execution.trigger_data"
                    label="Trigger data"
                    max-height="max-h-[480px]"
                />
            </div>
            <div class="ff-card p-4">
                <JsonView
                    :value="execution.result"
                    label="Result"
                    max-height="max-h-[480px]"
                />
            </div>
        </section>

        <!-- Logs -->
        <section v-show="tab === 'logs'" class="mt-4 overflow-hidden ff-card">
            <p
                v-if="!logs.length"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                No log entries. Insert Execution Log nodes and failure handling
                write here.
            </p>
            <ul v-else class="divide-y divide-border">
                <li
                    v-for="log in logs"
                    :key="log.id"
                    class="flex gap-3 px-4 py-2.5 text-sm"
                >
                    <span
                        class="ff-chip h-fit px-2 py-px text-[10px] uppercase"
                        :class="
                            log.level === 'error'
                                ? 'bg-bad/15 text-bad'
                                : log.level === 'warning'
                                  ? 'bg-wait/15 text-wait'
                                  : 'bg-run/15 text-run'
                        "
                        >{{ log.level }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <div>{{ log.message }}</div>
                        <div class="text-[11px] text-muted-foreground">
                            <span class="font-mono">{{ log.node_key }}</span> ·
                            {{ formatDate(log.created_at) }}
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </template>
</template>
