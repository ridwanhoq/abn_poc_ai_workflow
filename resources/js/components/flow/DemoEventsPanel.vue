<script>
import { Link } from '@inertiajs/vue3';
import { errorMessage } from '@/lib/api';
import AppIcon from './AppIcon.vue';
import StatusChip from './StatusChip.vue';

const inDays = (n) =>
    new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

/**
 * Demo-mode event simulator: changes demo records so that ACTIVE workflows
 * with Database Event triggers run end to end.
 */
export default {
    name: 'DemoEventsPanel',
    components: { AppIcon, Link, StatusChip },
    emits: ['fired'],
    data() {
        return {
            options: { contents: [], employees: [], departments: [] },
            contentId: '',
            employee: { name: '', department: 'Engineering', job_title: '' },
            leave: {
                employee_id: '',
                leave_type: 'annual',
                start_date: inDays(14),
                end_date: inDays(16),
                reason: '',
            },
            busy: null,
            result: null,
        };
    },
    mounted() {
        this.loadOptions();
    },
    methods: {
        async loadOptions() {
            try {
                this.options = (await this.$api.get('/demo-events')).data;
                this.contentId ||=
                    this.options.contents.find((c) => c.title)?.id || '';
                this.leave.employee_id ||= this.options.employees[0]?.id || '';
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load demo records.'),
                );
            }
        },
        async fire(kind, url, body) {
            this.busy = kind;
            try {
                const { data } = await this.$api.post(url, body);
                this.result = { kind, ...data };
                (data.executions.length
                    ? this.$toast.success
                    : this.$toast.warning)(data.message);
                window.dispatchEvent(new Event('ff:execution-started'));
                this.$emit('fired');
                this.loadOptions();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.busy = null;
            }
        },
        publish() {
            this.fire('content', '/demo-events/publish-content', {
                content_id: this.contentId,
            });
        },
        hire() {
            this.fire('employee', '/demo-events/employees', this.employee);
        },
        requestLeave() {
            this.fire('leave', '/demo-events/leave-requests', this.leave);
        },
    },
};
</script>

<template>
    <section class="overflow-hidden ff-card">
        <div
            class="flex flex-wrap items-start gap-3 border-b border-border p-4"
        >
            <span
                class="flex size-9 items-center justify-center rounded-xl bg-cat-trigger/15 text-cat-trigger"
            >
                <AppIcon name="database-zap" :size="18" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="font-bold">
                    Event simulator
                    <span class="ml-1 ff-chip bg-cat-ai/15 text-cat-ai"
                        >demo mode</span
                    >
                </h2>
                <p class="text-xs text-muted-foreground">
                    Makes real changes to demo records so that
                    <strong>active</strong> workflows with a Database Event
                    trigger run — e.g. the imported Thumbnail &amp; Trailer,
                    Onboarding and Leave Request templates.
                </p>
            </div>
        </div>

        <div class="grid gap-px bg-border md:grid-cols-3">
            <form class="space-y-3 bg-card p-4" @submit.prevent="publish">
                <div class="flex items-center gap-2 text-sm font-semibold">
                    <AppIcon
                        name="clapperboard"
                        :size="15"
                        class="text-cat-ai"
                    />
                    Publish content
                </div>
                <p class="text-xs text-muted-foreground">
                    Fires <code class="font-mono">contents.updated</code> with
                    status = published.
                </p>
                <select
                    v-model="contentId"
                    class="ff-input"
                    aria-label="Content"
                    required
                >
                    <option
                        v-for="c in options.contents"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.title || `Untitled #${c.id}` }} ({{ c.status }})
                    </option>
                </select>
                <button
                    class="ff-btn-primary w-full"
                    :disabled="busy !== null || !contentId"
                >
                    <AppIcon
                        :name="busy === 'content' ? 'loader-circle' : 'zap'"
                        :size="15"
                        :class="{ 'animate-spin': busy === 'content' }"
                    />
                    Publish
                </button>
            </form>

            <form class="space-y-3 bg-card p-4" @submit.prevent="hire">
                <div class="flex items-center gap-2 text-sm font-semibold">
                    <AppIcon
                        name="user-round-plus"
                        :size="15"
                        class="text-cat-ai"
                    />
                    New employee
                </div>
                <p class="text-xs text-muted-foreground">
                    Fires <code class="font-mono">employees.created</code>.
                </p>
                <input
                    v-model="employee.name"
                    class="ff-input"
                    placeholder="Full name"
                    maxlength="120"
                    required
                    aria-label="Employee name"
                />
                <div class="flex gap-2">
                    <select
                        v-model="employee.department"
                        class="ff-input w-1/2"
                        aria-label="Department"
                    >
                        <option v-for="d in options.departments" :key="d">
                            {{ d }}
                        </option>
                    </select>
                    <input
                        v-model="employee.job_title"
                        class="ff-input w-1/2"
                        placeholder="Job title"
                        maxlength="80"
                        required
                        aria-label="Job title"
                    />
                </div>
                <button class="ff-btn-primary w-full" :disabled="busy !== null">
                    <AppIcon
                        :name="busy === 'employee' ? 'loader-circle' : 'zap'"
                        :size="15"
                        :class="{ 'animate-spin': busy === 'employee' }"
                    />
                    Create employee
                </button>
            </form>

            <form class="space-y-3 bg-card p-4" @submit.prevent="requestLeave">
                <div class="flex items-center gap-2 text-sm font-semibold">
                    <AppIcon
                        name="calendar-check"
                        :size="15"
                        class="text-cat-ai"
                    />
                    Leave request
                </div>
                <p class="text-xs text-muted-foreground">
                    Fires <code class="font-mono">leave_requests.created</code>.
                </p>
                <div class="flex gap-2">
                    <select
                        v-model="leave.employee_id"
                        class="ff-input w-3/5"
                        aria-label="Employee"
                        required
                    >
                        <option
                            v-for="e in options.employees"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.name }}
                        </option>
                    </select>
                    <select
                        v-model="leave.leave_type"
                        class="ff-input w-2/5"
                        aria-label="Leave type"
                    >
                        <option
                            v-for="t in [
                                'annual',
                                'sick',
                                'parental',
                                'unpaid',
                            ]"
                            :key="t"
                        >
                            {{ t }}
                        </option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="leave.start_date"
                        type="date"
                        class="ff-input"
                        aria-label="Start date"
                        required
                    />
                    <input
                        v-model="leave.end_date"
                        type="date"
                        class="ff-input"
                        aria-label="End date"
                        required
                    />
                </div>
                <button
                    class="ff-btn-primary w-full"
                    :disabled="busy !== null || !leave.employee_id"
                >
                    <AppIcon
                        :name="busy === 'leave' ? 'loader-circle' : 'zap'"
                        :size="15"
                        :class="{ 'animate-spin': busy === 'leave' }"
                    />
                    Submit request
                </button>
            </form>
        </div>

        <div
            v-if="result"
            class="border-t border-border bg-muted/30 p-4 text-sm"
        >
            <p class="text-muted-foreground">{{ result.message }}</p>
            <ul v-if="result.executions.length" class="mt-2 space-y-1.5">
                <li
                    v-for="e in result.executions"
                    :key="e.id"
                    class="flex items-center gap-2"
                >
                    <StatusChip :status="e.status" size="sm" />
                    <Link
                        :href="`/executions/${e.id}`"
                        class="font-semibold hover:text-brand-500"
                        >#{{ e.id }} · {{ e.workflow }}</Link
                    >
                </li>
            </ul>
            <Link
                v-else
                href="/workflows"
                class="mt-1 inline-block text-xs font-semibold text-brand-500 hover:underline"
                >Activate a workflow →</Link
            >
        </div>
    </section>
</template>
