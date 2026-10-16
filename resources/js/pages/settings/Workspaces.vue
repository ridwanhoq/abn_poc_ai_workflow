<script>
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import { errorMessage, fieldErrors } from '@/lib/api';

export default {
    name: 'WorkspacesPage',
    components: { AppIcon, EmptyState, Head, Link, ModalDialog, PageHeader },
    data() {
        return {
            loading: true,
            workspaces: [],
            users: [],
            colors: [],
            editing: null,
            form: { name: '', color: '#6161ff' },
            formErrors: {},
            membersOf: null,
            memberIds: [],
            memberSearch: '',
            saving: false,
        };
    },
    computed: {
        filteredUsers() {
            const q = this.memberSearch.trim().toLowerCase();
            return q
                ? this.users.filter(
                      (u) =>
                          u.name.toLowerCase().includes(q) ||
                          u.email.toLowerCase().includes(q),
                  )
                : this.users;
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        async load() {
            try {
                const { data } = await this.$api.get('/workspaces/manage');
                this.workspaces = data.data;
                this.users = data.users;
                this.colors = data.colors;
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load workspaces.'),
                );
            } finally {
                this.loading = false;
            }
        },
        openForm(workspace = null) {
            this.editing = workspace || { id: null };
            this.form = workspace
                ? { name: workspace.name, color: workspace.color }
                : { name: '', color: this.colors[0] || '#6161ff' };
            this.formErrors = {};
        },
        async save() {
            this.saving = true;
            this.formErrors = {};
            try {
                if (this.editing.id) {
                    await this.$api.put(
                        `/workspaces/${this.editing.id}`,
                        this.form,
                    );
                    this.$toast.success('Workspace updated');
                } else {
                    await this.$api.post('/workspaces', this.form);
                    this.$toast.success(
                        'Workspace created',
                        'You were added as its first member.',
                    );
                }
                this.editing = null;
                this.load();
            } catch (error) {
                this.formErrors = fieldErrors(error);
                this.$toast.error(errorMessage(error));
            } finally {
                this.saving = false;
            }
        },
        openMembers(workspace) {
            this.membersOf = workspace;
            this.memberIds = [...workspace.member_ids];
            this.memberSearch = '';
        },
        async saveMembers() {
            this.saving = true;
            try {
                const { data } = await this.$api.put(
                    `/workspaces/${this.membersOf.id}/members`,
                    { user_ids: this.memberIds },
                );
                const added = data.changes.attached.length;
                const removed = data.changes.detached.length;
                this.$toast.success(
                    'Members updated',
                    `${added} added · ${removed} removed`,
                );
                this.membersOf = null;
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.saving = false;
            }
        },
        async remove(workspace) {
            const ok = await this.$confirm({
                title: `Delete "${workspace.name}"?`,
                message:
                    'Members lose access and its workspace variables are deleted. Workspaces that still contain workflows cannot be deleted.',
                confirmText: 'Delete workspace',
                danger: true,
                requireText: workspace.name,
            });
            if (!ok) {
                return;
            }
            try {
                await this.$api.delete(`/workspaces/${workspace.id}`);
                this.$toast.success('Workspace deleted');
                if (workspace.is_current) {
                    window.location.reload();
                    return;
                }
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
    },
};
</script>

<template>
    <Head title="Workspaces" />

    <PageHeader
        title="Workspaces"
        description="Workflows, executions and variables are scoped to a workspace. Members only see the workspaces they belong to."
    >
        <Link href="/settings/profile" class="ff-btn-ghost"
            ><AppIcon name="arrow-left" :size="15" /> Settings</Link
        >
        <button class="ff-btn-primary" @click="openForm()">
            <AppIcon name="plus" :size="15" /> New workspace
        </button>
    </PageHeader>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="i in 3" :key="i" class="h-40 ff-skeleton rounded-2xl" />
    </div>
    <EmptyState
        v-else-if="!workspaces.length"
        icon="building-2"
        title="No workspaces you can manage"
        description="Create one to start organising workflows by team."
    >
        <button class="ff-btn-primary" @click="openForm()">
            New workspace
        </button>
    </EmptyState>
    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="w in workspaces"
            :key="w.id"
            class="flex flex-col ff-card p-5"
        >
            <div class="flex items-start gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl text-lg font-extrabold text-white"
                    :style="{ backgroundColor: w.color }"
                    >{{ w.name.charAt(0) }}</span
                >
                <div class="min-w-0 flex-1">
                    <h2 class="truncate font-bold">{{ w.name }}</h2>
                    <div class="font-mono text-xs text-muted-foreground">
                        {{ w.slug }}
                    </div>
                </div>
                <span v-if="w.is_current" class="ff-chip bg-ok/15 text-ok"
                    >Current</span
                >
            </div>
            <div class="mt-4 mb-4 flex gap-4 text-sm">
                <span class="flex items-center gap-1.5 text-muted-foreground"
                    ><AppIcon name="users" :size="14" />
                    {{ w.members_count }}
                    {{ w.members_count === 1 ? 'member' : 'members' }}</span
                >
                <span class="flex items-center gap-1.5 text-muted-foreground"
                    ><AppIcon name="workflow" :size="14" />
                    {{ w.workflows_count }}
                    {{
                        w.workflows_count === 1 ? 'workflow' : 'workflows'
                    }}</span
                >
            </div>
            <div
                class="mt-auto flex flex-wrap gap-2 border-t border-border pt-4"
            >
                <button
                    class="ff-btn-secondary ff-btn-sm"
                    @click="openMembers(w)"
                >
                    <AppIcon name="users" :size="13" /> Members
                </button>
                <button class="ff-btn-secondary ff-btn-sm" @click="openForm(w)">
                    <AppIcon name="pencil" :size="13" /> Rename
                </button>
                <button
                    class="ff-btn-ghost ml-auto ff-btn-sm text-bad"
                    :disabled="w.workflows_count > 0"
                    :title="
                        w.workflows_count > 0
                            ? 'Delete or move its workflows first'
                            : 'Delete workspace'
                    "
                    @click="remove(w)"
                >
                    <AppIcon name="trash-2" :size="13" /> Delete
                </button>
            </div>
        </article>
    </div>

    <ModalDialog
        :open="Boolean(editing)"
        :title="editing?.id ? 'Rename workspace' : 'New workspace'"
        @close="editing = null"
    >
        <form id="workspace-form" class="space-y-4" @submit.prevent="save">
            <div>
                <label class="ff-label" for="ws-name">Name</label>
                <input
                    id="ws-name"
                    v-model="form.name"
                    v-focus
                    class="ff-input"
                    maxlength="80"
                    required
                    placeholder="e.g. Kids & Family"
                />
                <p v-if="formErrors.name" class="mt-1 text-xs text-bad">
                    {{ formErrors.name }}
                </p>
            </div>
            <div>
                <div class="ff-label">Colour</div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="c in colors"
                        :key="c"
                        type="button"
                        class="size-8 cursor-pointer rounded-lg transition"
                        :class="
                            form.color === c
                                ? 'ring-2 ring-offset-2 ring-offset-card'
                                : 'hover:scale-110'
                        "
                        :style="{ backgroundColor: c, '--tw-ring-color': c }"
                        :aria-label="`Colour ${c}`"
                        :aria-pressed="form.color === c"
                        @click="form.color = c"
                    />
                </div>
            </div>
        </form>
        <template #footer>
            <button class="ff-btn-secondary" @click="editing = null">
                Cancel
            </button>
            <button
                class="ff-btn-primary"
                type="submit"
                form="workspace-form"
                :disabled="saving || !form.name.trim()"
            >
                {{ editing?.id ? 'Save' : 'Create workspace' }}
            </button>
        </template>
    </ModalDialog>

    <ModalDialog
        :open="Boolean(membersOf)"
        :title="`Members · ${membersOf?.name || ''}`"
        description="Members can see this workspace's workflows and executions according to their role."
        @close="membersOf = null"
    >
        <div class="relative mb-3">
            <AppIcon
                name="search"
                :size="15"
                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="memberSearch"
                type="search"
                class="ff-input pl-9"
                placeholder="Search users"
                aria-label="Search users"
            />
        </div>
        <div
            class="max-h-80 divide-y divide-border overflow-y-auto rounded-xl border border-border"
        >
            <label
                v-for="u in filteredUsers"
                :key="u.id"
                class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-muted/50"
            >
                <input
                    v-model="memberIds"
                    type="checkbox"
                    :value="u.id"
                    class="size-4 accent-brand-500"
                    :disabled="
                        u.id === $page.props.auth.user.id &&
                        !$page.props.auth.user.is_super_admin
                    "
                />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold">{{
                        u.name
                    }}</span>
                    <span
                        class="block truncate text-xs text-muted-foreground"
                        >{{ u.email }}</span
                    >
                </span>
                <span class="text-xs text-muted-foreground">{{
                    u.role || 'No role'
                }}</span>
            </label>
        </div>
        <p class="mt-2 text-xs text-muted-foreground">
            {{ memberIds.length }} selected. Removed members lose access
            immediately.
        </p>
        <template #footer>
            <button class="ff-btn-secondary" @click="membersOf = null">
                Cancel
            </button>
            <button
                class="ff-btn-primary"
                :disabled="saving"
                @click="saveMembers"
            >
                Save members
            </button>
        </template>
    </ModalDialog>
</template>
