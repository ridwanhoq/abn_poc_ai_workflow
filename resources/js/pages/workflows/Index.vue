<script>
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import DropdownMenu from '@/components/flow/DropdownMenu.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import MenuItem from '@/components/flow/MenuItem.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import PaginationBar from '@/components/flow/PaginationBar.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import ToggleSwitch from '@/components/flow/ToggleSwitch.vue';
import { errorMessage, fieldErrors } from '@/lib/api';
import {
    debounce,
    formatDate,
    timeAgo,
    triggerLabel,
    TRIGGER_LABELS,
} from '@/lib/format';

export default {
    name: 'WorkflowsIndex',
    components: {
        AppIcon,
        DropdownMenu,
        EmptyState,
        Head,
        Link,
        MenuItem,
        ModalDialog,
        PageHeader,
        PaginationBar,
        StatusChip,
        ToggleSwitch,
    },
    data() {
        return {
            loading: true,
            workflows: [],
            meta: null,
            filters: {
                q: '',
                status: '',
                trigger_type: '',
                sort: 'updated_at',
                direction: 'desc',
                page: 1,
                mine: false,
            },
            triggerOptions: TRIGGER_LABELS,
            busy: {},
            createOpen: false,
            form: { name: '', description: '', category: '' },
            formErrors: {},
            saving: false,
        };
    },
    created() {
        this.debouncedLoad = debounce(() => {
            this.filters.page = 1;
            this.load();
        }, 300);
    },
    mounted() {
        this.load();
    },
    methods: {
        formatDate,
        timeAgo,
        triggerLabel,
        async load() {
            this.loading = true;
            try {
                const params = Object.fromEntries(
                    Object.entries(this.filters).filter(
                        ([, v]) => v !== '' && v !== false,
                    ),
                );
                const { data } = await this.$api.get('/workflows', { params });
                this.workflows = data.data;
                this.meta = data.meta;
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load workflows.'),
                );
            } finally {
                this.loading = false;
            }
        },
        sortBy(column) {
            if (this.filters.sort === column) {
                this.filters.direction =
                    this.filters.direction === 'asc' ? 'desc' : 'asc';
            } else {
                this.filters.sort = column;
                this.filters.direction = column === 'name' ? 'asc' : 'desc';
            }
            this.load();
        },
        goPage(page) {
            this.filters.page = page;
            this.load();
        },
        openCreate() {
            this.form = { name: '', description: '', category: '' };
            this.formErrors = {};
            this.createOpen = true;
        },
        async create() {
            this.saving = true;
            this.formErrors = {};
            try {
                const { data } = await this.$api.post('/workflows', this.form);
                this.$toast.success('Workflow created');
                this.$inertia.visit(`/workflows/${data.data.id}/edit`);
            } catch (error) {
                this.formErrors = fieldErrors(error);
                this.$toast.error(errorMessage(error));
            } finally {
                this.saving = false;
            }
        },
        async toggleActive(workflow) {
            const activate = workflow.status !== 'active';
            if (
                activate &&
                !(await this.$confirm({
                    title: `Activate "${workflow.name}"?`,
                    message:
                        'Active workflows respond to their trigger automatically (schedules, webhooks, events).',
                    confirmText: 'Activate',
                }))
            ) {
                return;
            }
            this.busy[workflow.id] = true;
            try {
                const { data } = await this.$api.post(
                    `/workflows/${workflow.id}/${activate ? 'activate' : 'deactivate'}`,
                );
                Object.assign(workflow, { status: data.data.status });
                this.$toast.success(
                    activate ? 'Workflow activated' : 'Workflow deactivated',
                );
            } catch (error) {
                const issues = error?.response?.data?.validation?.errors || [];
                this.$toast.error(
                    errorMessage(error, 'Could not change status.'),
                    issues.length
                        ? `${issues.length} validation issue(s) — open the editor to fix them.`
                        : undefined,
                );
            } finally {
                this.busy[workflow.id] = false;
            }
        },
        async run(workflow) {
            this.busy[workflow.id] = true;
            try {
                const { data } = await this.$api.post(
                    `/workflows/${workflow.id}/execute`,
                    { test: true },
                );
                window.dispatchEvent(new Event('ff:execution-started'));
                this.$toast.success(
                    'Test run queued',
                    `Execution #${data.data.id}`,
                );
                this.$inertia.visit(`/executions/${data.data.id}`);
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not start the run.'),
                );
            } finally {
                this.busy[workflow.id] = false;
            }
        },
        async duplicate(workflow) {
            try {
                const { data } = await this.$api.post(
                    `/workflows/${workflow.id}/duplicate`,
                );
                this.$toast.success('Workflow duplicated', data.data.name);
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        async remove(workflow) {
            const ok = await this.$confirm({
                title: 'Delete workflow?',
                message: `"${workflow.name}" will be deactivated and removed. Execution history is kept.`,
                confirmText: 'Delete',
                danger: true,
            });
            if (!ok) {
                return;
            }
            try {
                await this.$api.delete(`/workflows/${workflow.id}`);
                this.$toast.success('Workflow deleted');
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        sortIcon(column) {
            if (this.filters.sort !== column) {
                return null;
            }
            return this.filters.direction === 'asc'
                ? 'chevron-up'
                : 'chevron-down';
        },
    },
};
</script>

<template>
    <Head title="Workflows" />

    <PageHeader
        title="Workflows"
        description="Build, activate and monitor your automations."
    >
        <Link
            v-if="$can('templates.import')"
            href="/templates"
            class="ff-btn-secondary"
        >
            <AppIcon name="layout-template" :size="16" /> From template
        </Link>
        <button
            v-if="$can('workflows.create')"
            class="ff-btn-primary"
            @click="openCreate"
        >
            <AppIcon name="plus" :size="16" /> New workflow
        </button>
    </PageHeader>

    <div class="overflow-hidden ff-card">
        <div
            class="flex flex-wrap items-center gap-2 border-b border-border p-3"
        >
            <div class="relative min-w-56 flex-1">
                <AppIcon
                    name="search"
                    :size="15"
                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="filters.q"
                    type="search"
                    class="ff-input pl-9"
                    placeholder="Search by name or description"
                    @input="debouncedLoad"
                />
            </div>
            <select
                v-model="filters.status"
                class="ff-input w-36"
                aria-label="Status filter"
                @change="debouncedLoad"
            >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="draft">Draft</option>
                <option value="inactive">Inactive</option>
            </select>
            <select
                v-model="filters.trigger_type"
                class="ff-input w-44"
                aria-label="Trigger filter"
                @change="debouncedLoad"
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
            <label
                class="flex cursor-pointer items-center gap-2 px-2 text-sm text-muted-foreground"
            >
                <input
                    v-model="filters.mine"
                    type="checkbox"
                    class="size-4 accent-brand-500"
                    @change="debouncedLoad"
                />
                Created by me
            </label>
        </div>

        <div v-if="loading && !workflows.length" class="space-y-2 p-4">
            <div v-for="i in 5" :key="i" class="h-12 ff-skeleton" />
        </div>

        <EmptyState
            v-else-if="!workflows.length"
            icon="workflow"
            title="No workflows found"
            :description="
                filters.q || filters.status || filters.trigger_type
                    ? 'Try clearing the filters.'
                    : 'Create a workflow from scratch or start from one of the six ready-made templates.'
            "
        >
            <Link
                v-if="$can('templates.import')"
                href="/templates"
                class="ff-btn-secondary"
                >Browse templates</Link
            >
            <button
                v-if="$can('workflows.create')"
                class="ff-btn-primary"
                @click="openCreate"
            >
                New workflow
            </button>
        </EmptyState>

        <div
            v-else
            class="overflow-x-auto"
            :class="{ 'opacity-60 transition-opacity': loading }"
        >
            <table class="ff-table">
                <thead>
                    <tr>
                        <th>
                            <button
                                class="inline-flex items-center gap-1 uppercase"
                                @click="sortBy('name')"
                            >
                                Name
                                <AppIcon
                                    v-if="sortIcon('name')"
                                    :name="sortIcon('name')"
                                    :size="12"
                                />
                            </button>
                        </th>
                        <th>Status</th>
                        <th class="hidden lg:table-cell">Trigger</th>
                        <th class="hidden md:table-cell">Last run</th>
                        <th class="hidden xl:table-cell">Success</th>
                        <th class="hidden xl:table-cell">Creator</th>
                        <th class="hidden lg:table-cell">
                            <button
                                class="inline-flex items-center gap-1 uppercase"
                                @click="sortBy('updated_at')"
                            >
                                Updated
                                <AppIcon
                                    v-if="sortIcon('updated_at')"
                                    :name="sortIcon('updated_at')"
                                    :size="12"
                                />
                            </button>
                        </th>
                        <th class="w-px text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="w in workflows" :key="w.id">
                        <td class="max-w-80">
                            <Link
                                :href="`/workflows/${w.id}/edit`"
                                class="block truncate font-semibold hover:text-brand-500"
                                >{{ w.name }}</Link
                            >
                            <div class="truncate text-xs text-muted-foreground">
                                {{ w.category || 'Uncategorized' }} ·
                                {{ w.node_count }} nodes · v{{ w.version }}
                            </div>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <ToggleSwitch
                                    v-if="w.can.activate"
                                    :model-value="w.status === 'active'"
                                    :disabled="busy[w.id]"
                                    :label="
                                        w.status === 'active'
                                            ? 'Deactivate'
                                            : 'Activate'
                                    "
                                    @update:model-value="toggleActive(w)"
                                />
                                <StatusChip :status="w.status" size="sm" />
                            </div>
                        </td>
                        <td class="hidden text-muted-foreground lg:table-cell">
                            {{ triggerLabel(w.trigger_type) }}
                        </td>
                        <td class="hidden md:table-cell">
                            <Link
                                v-if="w.last_execution"
                                :href="`/executions/${w.last_execution.id}`"
                                class="inline-flex items-center gap-2"
                            >
                                <StatusChip
                                    :status="w.last_execution.status"
                                    size="sm"
                                />
                                <span class="text-xs text-muted-foreground">{{
                                    timeAgo(w.last_execution.created_at)
                                }}</span>
                            </Link>
                            <span v-else class="text-xs text-muted-foreground"
                                >Never run</span
                            >
                        </td>
                        <td class="hidden xl:table-cell">
                            <span
                                v-if="w.success_rate !== null"
                                class="text-sm font-semibold"
                                :class="
                                    w.success_rate >= 80
                                        ? 'text-ok'
                                        : w.success_rate >= 50
                                          ? 'text-wait'
                                          : 'text-bad'
                                "
                                >{{ w.success_rate }}%</span
                            >
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                            <span
                                v-if="w.executions_count"
                                class="ml-1 text-xs text-muted-foreground"
                                >({{ w.executions_count }})</span
                            >
                        </td>
                        <td
                            class="hidden text-sm text-muted-foreground xl:table-cell"
                        >
                            {{ w.creator?.name || '—' }}
                        </td>
                        <td
                            class="hidden text-sm text-muted-foreground lg:table-cell"
                            :title="formatDate(w.updated_at)"
                        >
                            {{ timeAgo(w.updated_at) }}
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button
                                    v-if="w.can.execute"
                                    class="ff-icon-btn"
                                    title="Test run"
                                    :disabled="busy[w.id]"
                                    @click="run(w)"
                                >
                                    <AppIcon name="play" :size="16" />
                                </button>
                                <Link
                                    :href="`/workflows/${w.id}/edit`"
                                    class="ff-icon-btn"
                                    :title="w.can.update ? 'Edit' : 'View'"
                                >
                                    <AppIcon
                                        :name="w.can.update ? 'pencil' : 'eye'"
                                        :size="16"
                                    />
                                </Link>
                                <DropdownMenu width="w-48">
                                    <template #trigger="{ toggle }">
                                        <button
                                            class="ff-icon-btn"
                                            aria-label="More actions"
                                            @click="toggle"
                                        >
                                            <AppIcon
                                                name="ellipsis"
                                                :size="16"
                                            />
                                        </button>
                                    </template>
                                    <MenuItem
                                        icon="history"
                                        @select="
                                            $inertia.visit(
                                                `/executions?workflow_id=${w.id}`,
                                            )
                                        "
                                        >Executions</MenuItem
                                    >
                                    <MenuItem
                                        v-if="$can('workflows.create')"
                                        icon="copy"
                                        @select="duplicate(w)"
                                        >Duplicate</MenuItem
                                    >
                                    <MenuItem
                                        v-if="w.can.delete"
                                        icon="trash-2"
                                        danger
                                        @select="remove(w)"
                                        >Delete</MenuItem
                                    >
                                </DropdownMenu>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationBar :meta="meta" @page="goPage" />
    </div>

    <ModalDialog
        :open="createOpen"
        title="New workflow"
        description="You can change everything later in the editor."
        @close="createOpen = false"
    >
        <form id="create-workflow" class="space-y-4" @submit.prevent="create">
            <div>
                <label class="ff-label" for="wf-name">Name</label>
                <input
                    id="wf-name"
                    v-model="form.name"
                    v-focus
                    class="ff-input"
                    maxlength="120"
                    required
                    placeholder="e.g. Weekly content report"
                />
                <p v-if="formErrors.name" class="mt-1 text-xs text-bad">
                    {{ formErrors.name }}
                </p>
            </div>
            <div>
                <label class="ff-label" for="wf-cat">Category</label>
                <input
                    id="wf-cat"
                    v-model="form.category"
                    class="ff-input"
                    maxlength="80"
                    placeholder="e.g. OTT / Content"
                />
            </div>
            <div>
                <label class="ff-label" for="wf-desc">Description</label>
                <textarea
                    id="wf-desc"
                    v-model="form.description"
                    class="ff-textarea"
                    maxlength="2000"
                />
            </div>
        </form>
        <template #footer>
            <button class="ff-btn-secondary" @click="createOpen = false">
                Cancel
            </button>
            <button
                class="ff-btn-primary"
                form="create-workflow"
                type="submit"
                :disabled="saving || !form.name"
            >
                {{ saving ? 'Creating…' : 'Create & open editor' }}
            </button>
        </template>
    </ModalDialog>
</template>
