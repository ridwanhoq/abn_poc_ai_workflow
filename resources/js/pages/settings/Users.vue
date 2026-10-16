<script>
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import PaginationBar from '@/components/flow/PaginationBar.vue';
import { errorMessage } from '@/lib/api';
import { debounce } from '@/lib/format';

export default {
    name: 'UsersPage',
    components: { AppIcon, Head, Link, ModalDialog, PageHeader, PaginationBar },
    data() {
        return {
            loading: true,
            users: [],
            roles: [],
            workspaces: [],
            meta: null,
            q: '',
            editing: null,
            form: { role_id: null, workspace_ids: [] },
            saving: false,
            rolesOpen: false,
        };
    },
    created() {
        this.debouncedLoad = debounce(this.load, 300);
    },
    mounted() {
        this.load();
    },
    methods: {
        async load(page = 1) {
            try {
                const { data } = await this.$api.get('/users', {
                    params: { q: this.q || undefined, page },
                });
                this.users = data.data;
                this.meta = data.meta;
                this.roles = data.roles;
                this.workspaces = data.workspaces;
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.loading = false;
            }
        },
        edit(user) {
            this.editing = user;
            this.form = {
                role_id: user.role?.id || null,
                workspace_ids: user.workspaces.map((w) => w.id),
            };
        },
        async save() {
            const role = this.roles.find((r) => r.id === this.form.role_id);
            if (
                role?.slug === 'super_admin' &&
                this.editing.role?.slug !== 'super_admin'
            ) {
                const ok = await this.$confirm({
                    title: 'Grant Super Admin?',
                    message: `${this.editing.name} will get unrestricted access to everything.`,
                    confirmText: 'Grant',
                    danger: true,
                });
                if (!ok) {
                    return;
                }
            }
            this.saving = true;
            try {
                await this.$api.put(`/users/${this.editing.id}`, this.form);
                this.$toast.success('Access updated');
                this.editing = null;
                this.load(this.meta?.current_page || 1);
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.saving = false;
            }
        },
        roleColor(slug) {
            return (
                {
                    super_admin: '#e2445c',
                    admin: '#6161ff',
                    workflow_manager: '#00c875',
                    viewer: '#9ca3af',
                }[slug] || '#9ca3af'
            );
        },
    },
};
</script>

<template>
    <Head title="Users & roles" />

    <PageHeader
        title="Users & roles"
        description="Role-based access control. Every API endpoint enforces these permissions server-side."
    >
        <Link href="/settings/profile" class="ff-btn-ghost"
            ><AppIcon name="arrow-left" :size="15" /> Settings</Link
        >
        <button class="ff-btn-secondary" @click="rolesOpen = true">
            <AppIcon name="shield" :size="15" /> Role permissions
        </button>
    </PageHeader>

    <div class="overflow-hidden ff-card">
        <div class="border-b border-border p-3">
            <div class="relative max-w-sm">
                <AppIcon
                    name="search"
                    :size="15"
                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="q"
                    type="search"
                    class="ff-input pl-9"
                    placeholder="Search by name or email"
                    @input="debouncedLoad()"
                />
            </div>
        </div>
        <div v-if="loading" class="space-y-2 p-4">
            <div v-for="i in 5" :key="i" class="h-11 ff-skeleton" />
        </div>
        <div v-else class="overflow-x-auto">
            <table class="ff-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th class="hidden md:table-cell">Workspaces</th>
                        <th class="hidden lg:table-cell">Profile verified</th>
                        <th class="w-px" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id">
                        <td>
                            <div class="font-semibold">
                                {{ u.name }}
                                <span
                                    v-if="u.is_demo"
                                    class="ml-1 text-[10px] font-bold text-muted-foreground uppercase"
                                    >demo</span
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ u.email }}
                            </div>
                        </td>
                        <td>
                            <span
                                class="ff-chip"
                                :style="{
                                    backgroundColor:
                                        roleColor(u.role?.slug) + '22',
                                    color: roleColor(u.role?.slug),
                                }"
                                >{{ u.role?.name || 'No role' }}</span
                            >
                        </td>
                        <td
                            class="hidden text-sm text-muted-foreground md:table-cell"
                        >
                            {{
                                u.workspaces.map((w) => w.name).join(', ') ||
                                '—'
                            }}
                        </td>
                        <td class="hidden lg:table-cell">
                            <span
                                v-if="u.verified_at"
                                class="ff-chip bg-ok/15 text-ok"
                                ><AppIcon name="check" :size="11" /> Yes</span
                            >
                            <span v-else class="ff-chip bg-wait/15 text-wait"
                                >No</span
                            >
                        </td>
                        <td>
                            <button
                                class="ff-btn-ghost ff-btn-sm"
                                :disabled="u.id === $page.props.auth.user.id"
                                @click="edit(u)"
                            >
                                Edit access
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationBar :meta="meta" @page="load" />
    </div>

    <ModalDialog
        :open="Boolean(editing)"
        :title="`Access · ${editing?.name || ''}`"
        @close="editing = null"
    >
        <div class="space-y-5">
            <div>
                <div class="ff-label">Role</div>
                <div class="space-y-2">
                    <label
                        v-for="r in roles"
                        :key="r.id"
                        class="flex cursor-pointer gap-3 rounded-xl border p-3 transition"
                        :class="
                            form.role_id === r.id
                                ? 'border-brand-400 bg-brand-500/5'
                                : 'border-border hover:bg-muted/50'
                        "
                    >
                        <input
                            v-model="form.role_id"
                            type="radio"
                            :value="r.id"
                            class="mt-1 accent-brand-500"
                        />
                        <span>
                            <span class="block text-sm font-semibold">{{
                                r.name
                            }}</span>
                            <span class="block text-xs text-muted-foreground">{{
                                r.description
                            }}</span>
                        </span>
                    </label>
                </div>
            </div>
            <div>
                <div class="ff-label">Workspaces</div>
                <label
                    v-for="w in workspaces"
                    :key="w.id"
                    class="flex items-center gap-2 py-1 text-sm"
                >
                    <input
                        v-model="form.workspace_ids"
                        type="checkbox"
                        :value="w.id"
                        class="size-4 accent-brand-500"
                    />
                    {{ w.name }}
                </label>
            </div>
        </div>
        <template #footer>
            <button class="ff-btn-secondary" @click="editing = null">
                Cancel
            </button>
            <button class="ff-btn-primary" :disabled="saving" @click="save">
                Save access
            </button>
        </template>
    </ModalDialog>

    <ModalDialog
        :open="rolesOpen"
        title="Role permissions"
        description="Database-backed permissions per role. Super Admin bypasses all checks."
        width="max-w-3xl"
        @close="rolesOpen = false"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="r in roles"
                :key="r.id"
                class="rounded-xl border border-border p-3"
            >
                <div class="font-bold" :style="{ color: roleColor(r.slug) }">
                    {{ r.name }}
                </div>
                <p class="mb-2 text-xs text-muted-foreground">
                    {{ r.description }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <span
                        v-for="p in r.permissions"
                        :key="p"
                        class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px]"
                        >{{ p }}</span
                    >
                </div>
            </div>
        </div>
    </ModalDialog>
</template>
