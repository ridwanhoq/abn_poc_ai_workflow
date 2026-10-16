<script>
import { Head } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import DemoEventsPanel from '@/components/flow/DemoEventsPanel.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import ToggleSwitch from '@/components/flow/ToggleSwitch.vue';
import { errorMessage, fieldErrors } from '@/lib/api';
import { formatDate, timeAgo } from '@/lib/format';

export default {
    name: 'DataVariablesPage',
    components: {
        DemoEventsPanel,
        AppIcon,
        EmptyState,
        Head,
        ModalDialog,
        PageHeader,
        ToggleSwitch,
    },
    data() {
        return {
            loading: true,
            models: [],
            verification: null,
            variables: [],
            assets: [],
            browsing: null,
            rows: [],
            columns: [],
            variableOpen: false,
            variableForm: {
                key: '',
                value: '',
                is_secret: false,
                description: '',
                global: false,
            },
            variableErrors: {},
            editingVariable: null,
            uploadOpen: false,
            upload: { file: null, title: '', language: 'en' },
            uploading: false,
            uploadProgress: 0,
        };
    },
    async mounted() {
        await this.load();
        const params = new URLSearchParams(window.location.search);
        if (params.get('upload') && this.$can('workflows.execute')) {
            this.uploadOpen = true;
        }
        if (params.get('variable') && this.$can('variables.manage')) {
            this.openVariable();
        }
    },
    methods: {
        formatDate,
        timeAgo,
        async load() {
            try {
                const [data, variables, assets] = await Promise.all([
                    this.$api.get('/data'),
                    this.$api.get('/variables'),
                    this.$api.get('/media-assets'),
                ]);
                this.models = data.data.data;
                this.verification = data.data.users_verification;
                this.variables = variables.data.data;
                this.assets = assets.data.data;
            } catch (error) {
                this.$toast.error(errorMessage(error, 'Could not load data.'));
            } finally {
                this.loading = false;
            }
        },
        async browse(model) {
            this.browsing = model;
            this.rows = [];
            try {
                const { data } = await this.$api.get(`/data/${model.alias}`);
                this.rows = data.data;
                this.columns = data.columns.slice(0, 7);
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        cell(value) {
            if (value === null || value === undefined) {
                return '—';
            }
            return typeof value === 'object'
                ? JSON.stringify(value).slice(0, 60)
                : String(value).slice(0, 60);
        },
        openVariable(variable = null) {
            this.editingVariable = variable;
            this.variableErrors = {};
            this.variableForm = variable
                ? {
                      key: variable.key,
                      value: variable.is_secret ? '' : variable.value || '',
                      is_secret: variable.is_secret,
                      description: variable.description || '',
                      global: variable.scope === 'global',
                  }
                : {
                      key: '',
                      value: '',
                      is_secret: false,
                      description: '',
                      global: false,
                  };
            this.variableOpen = true;
        },
        async saveVariable() {
            try {
                if (this.editingVariable) {
                    const body = { description: this.variableForm.description };
                    if (
                        !this.editingVariable.is_secret ||
                        this.variableForm.value !== ''
                    ) {
                        body.value = this.variableForm.value;
                    }
                    await this.$api.put(
                        `/variables/${this.editingVariable.id}`,
                        body,
                    );
                } else {
                    await this.$api.post('/variables', this.variableForm);
                }
                this.$toast.success(
                    'Variable saved',
                    `Use it as {{ variables.${this.variableForm.key} }}`,
                );
                this.variableOpen = false;
                this.load();
            } catch (error) {
                this.variableErrors = fieldErrors(error);
                this.$toast.error(errorMessage(error));
            }
        },
        async deleteVariable(variable) {
            const ok = await this.$confirm({
                title: `Delete variable "${variable.key}"?`,
                message: 'Workflows that reference it will receive null.',
                confirmText: 'Delete',
                danger: true,
            });
            if (!ok) {
                return;
            }
            try {
                await this.$api.delete(`/variables/${variable.id}`);
                this.$toast.success('Variable deleted');
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        onFile(event) {
            this.upload.file = event.target.files[0] || null;
            if (this.upload.file && !this.upload.title) {
                this.upload.title = this.upload.file.name.replace(
                    /\.[^.]+$/,
                    '',
                );
            }
        },
        async submitUpload() {
            if (!this.upload.file) {
                this.$toast.warning('Choose a video file first.');
                return;
            }
            const form = new FormData();
            form.append('file', this.upload.file);
            form.append('title', this.upload.title);
            form.append('language', this.upload.language);
            this.uploading = true;
            this.uploadProgress = 0;
            try {
                const { data } = await this.$api.post('/media-assets', form, {
                    timeout: 0,
                    onUploadProgress: (e) =>
                        (this.uploadProgress = e.total
                            ? Math.round((e.loaded / e.total) * 100)
                            : 0),
                });
                this.$toast.success('Video uploaded', data.message);
                window.dispatchEvent(new Event('ff:execution-started'));
                this.uploadOpen = false;
                this.upload = { file: null, title: '', language: 'en' };
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error, 'Upload failed.'));
            } finally {
                this.uploading = false;
            }
        },
    },
};
</script>

<template>
    <Head title="Data & Variables" />

    <PageHeader
        title="Data & Variables"
        description="Data models that workflows can read or write, reusable variables, and media uploads for the File Uploaded trigger."
    >
        <button
            v-if="$can('workflows.execute')"
            class="ff-btn-secondary"
            @click="uploadOpen = true"
        >
            <AppIcon name="upload" :size="15" /> Upload video
        </button>
        <button
            v-if="$can('variables.manage')"
            class="ff-btn-primary"
            @click="openVariable()"
        >
            <AppIcon name="plus" :size="15" /> New variable
        </button>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-3">
        <!-- Variables -->
        <section class="overflow-hidden ff-card xl:col-span-1">
            <div class="border-b border-border p-4">
                <h2 class="font-bold">Variables</h2>
                <p class="text-xs text-muted-foreground">
                    Available in every workflow as
                    <code v-pre class="font-mono">{{ variables.name }}</code
                    >. Secrets are encrypted.
                </p>
            </div>
            <div v-if="loading" class="space-y-2 p-4">
                <div v-for="i in 3" :key="i" class="h-10 ff-skeleton" />
            </div>
            <EmptyState
                v-else-if="!variables.length"
                icon="variable"
                title="No variables yet"
                description="Store values like a brand name or API base URL once and reuse them."
            />
            <ul v-else class="divide-y divide-border">
                <li
                    v-for="v in variables"
                    :key="v.id"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <AppIcon
                        :name="v.is_secret ? 'lock' : 'variable'"
                        :size="15"
                        class="shrink-0 text-muted-foreground"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-mono text-sm font-semibold">
                            {{ v.key }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{
                                v.is_secret
                                    ? '•••••••• (secret)'
                                    : v.value || '—'
                            }}
                            · {{ v.scope }}
                        </div>
                    </div>
                    <template v-if="$can('variables.manage')">
                        <button
                            class="ff-icon-btn size-8"
                            title="Edit"
                            @click="openVariable(v)"
                        >
                            <AppIcon name="pencil" :size="14" />
                        </button>
                        <button
                            class="ff-icon-btn size-8 hover:text-bad"
                            title="Delete"
                            @click="deleteVariable(v)"
                        >
                            <AppIcon name="trash-2" :size="14" />
                        </button>
                    </template>
                </li>
            </ul>
        </section>

        <!-- Data models -->
        <section class="overflow-hidden ff-card xl:col-span-2">
            <div class="border-b border-border p-4">
                <h2 class="font-bold">Workflow data models</h2>
                <p class="text-xs text-muted-foreground">
                    Data nodes can only access these models and columns. Users
                    are read-only (verified_at can never be changed by a
                    workflow).
                </p>
            </div>
            <div
                v-if="verification"
                class="flex flex-wrap gap-3 border-b border-border p-4 text-sm"
            >
                <span class="ff-chip bg-ok/15 text-ok"
                    ><AppIcon name="user-check" :size="12" />
                    {{ verification.verified }} verified users</span
                >
                <span class="ff-chip bg-wait/15 text-wait"
                    ><AppIcon name="user" :size="12" />
                    {{ verification.unverified }} with verified_at = NULL</span
                >
            </div>
            <div v-if="loading" class="space-y-2 p-4">
                <div v-for="i in 5" :key="i" class="h-10 ff-skeleton" />
            </div>
            <div v-else class="overflow-x-auto">
                <table class="ff-table">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Rows</th>
                            <th class="hidden md:table-cell">Access</th>
                            <th class="w-px" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in models" :key="m.alias">
                            <td>
                                <div class="font-semibold">{{ m.label }}</div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ m.alias }}
                                </div>
                            </td>
                            <td class="font-semibold">{{ m.count }}</td>
                            <td class="hidden md:table-cell">
                                <span class="mr-1 ff-chip bg-run/15 text-run"
                                    >read</span
                                >
                                <span
                                    v-if="m.writable.length"
                                    class="mr-1 ff-chip bg-ok/15 text-ok"
                                    >create</span
                                >
                                <span
                                    v-if="m.updatable.length"
                                    class="ff-chip bg-wait/15 text-wait"
                                    >update</span
                                >
                            </td>
                            <td>
                                <button
                                    class="ff-btn-ghost ff-btn-sm"
                                    @click="browse(m)"
                                >
                                    Browse
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <DemoEventsPanel
        v-if="$page.props.platform?.demo_mode && $can('workflows.execute')"
        class="mt-6"
    />

    <!-- Media assets -->
    <section class="mt-6 overflow-hidden ff-card">
        <div
            class="flex items-center justify-between border-b border-border p-4"
        >
            <div>
                <h2 class="font-bold">Media assets</h2>
                <p class="text-xs text-muted-foreground">
                    Uploads start every active workflow with a File Uploaded
                    trigger.
                </p>
            </div>
        </div>
        <EmptyState
            v-if="!loading && !assets.length"
            icon="film"
            title="No media assets"
        />
        <div v-else class="overflow-x-auto">
            <table class="ff-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th class="hidden md:table-cell">Type</th>
                        <th class="hidden md:table-cell">Size</th>
                        <th class="hidden lg:table-cell">Added</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in assets" :key="a.id">
                        <td>
                            <div class="font-semibold">{{ a.title }}</div>
                            <div class="text-xs text-muted-foreground">
                                #{{ a.id }} · {{ a.language }}
                                <span v-if="a.is_demo">· demo</span>
                            </div>
                        </td>
                        <td>
                            <span
                                class="ff-chip bg-muted text-muted-foreground"
                                >{{ a.status.replace('_', ' ') }}</span
                            >
                        </td>
                        <td class="hidden text-muted-foreground md:table-cell">
                            {{ a.mime_type }}
                        </td>
                        <td class="hidden text-muted-foreground md:table-cell">
                            {{ (a.size_bytes / 1048576).toFixed(1) }} MB
                        </td>
                        <td class="hidden text-muted-foreground lg:table-cell">
                            {{ timeAgo(a.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Browse dialog -->
    <ModalDialog
        :open="Boolean(browsing)"
        :title="browsing?.label || ''"
        description="Latest 25 rows · personal data is masked"
        width="max-w-5xl"
        @close="browsing = null"
    >
        <div class="overflow-x-auto">
            <table class="ff-table text-xs">
                <thead>
                    <tr>
                        <th v-for="c in columns" :key="c">{{ c }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="i">
                        <td
                            v-for="c in columns"
                            :key="c"
                            class="max-w-48 truncate"
                        >
                            {{ cell(row[c]) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="!rows.length"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                No rows.
            </p>
        </div>
    </ModalDialog>

    <!-- Variable dialog -->
    <ModalDialog
        :open="variableOpen"
        :title="editingVariable ? 'Edit variable' : 'New variable'"
        @close="variableOpen = false"
    >
        <form
            id="variable-form"
            class="space-y-4"
            @submit.prevent="saveVariable"
        >
            <div>
                <label class="ff-label" for="v-key">Key</label>
                <input
                    id="v-key"
                    v-model="variableForm.key"
                    class="ff-input font-mono"
                    :disabled="Boolean(editingVariable)"
                    placeholder="brand_name"
                    required
                />
                <p v-if="variableErrors.key" class="mt-1 text-xs text-bad">
                    {{ variableErrors.key }}
                </p>
            </div>
            <div>
                <label class="ff-label" for="v-value">Value</label>
                <input
                    id="v-value"
                    v-model="variableForm.value"
                    class="ff-input"
                    :type="variableForm.is_secret ? 'password' : 'text'"
                    autocomplete="off"
                    :placeholder="
                        editingVariable?.is_secret
                            ? 'Leave empty to keep the current secret'
                            : ''
                    "
                />
            </div>
            <div>
                <label class="ff-label" for="v-desc">Description</label>
                <input
                    id="v-desc"
                    v-model="variableForm.description"
                    class="ff-input"
                />
            </div>
            <div v-if="!editingVariable" class="flex flex-wrap gap-6">
                <label class="flex items-center gap-2 text-sm"
                    ><ToggleSwitch
                        v-model="variableForm.is_secret"
                        label="Secret"
                    />
                    Secret (encrypted)</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><ToggleSwitch
                        v-model="variableForm.global"
                        label="Global"
                    />
                    All workspaces</label
                >
            </div>
        </form>
        <template #footer>
            <button class="ff-btn-secondary" @click="variableOpen = false">
                Cancel
            </button>
            <button class="ff-btn-primary" type="submit" form="variable-form">
                Save variable
            </button>
        </template>
    </ModalDialog>

    <!-- Upload dialog -->
    <ModalDialog
        :open="uploadOpen"
        title="Upload a video"
        description="Stored on the local disk. Active File Uploaded workflows start automatically."
        @close="!uploading && (uploadOpen = false)"
    >
        <div class="space-y-4">
            <label
                class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-border p-8 text-center transition hover:border-brand-400 hover:bg-brand-500/5"
            >
                <AppIcon name="file-up" :size="28" class="text-brand-500" />
                <span class="mt-2 text-sm font-semibold">{{
                    upload.file ? upload.file.name : 'Choose a video file'
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >MP4, MOV, WebM, MKV or AVI · validated server-side</span
                >
                <input
                    type="file"
                    accept="video/*"
                    class="sr-only"
                    @change="onFile"
                />
            </label>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="ff-label" for="u-title">Title</label>
                    <input
                        id="u-title"
                        v-model="upload.title"
                        class="ff-input"
                        maxlength="160"
                    />
                </div>
                <div>
                    <label class="ff-label" for="u-lang">Language</label>
                    <input
                        id="u-lang"
                        v-model="upload.language"
                        class="ff-input"
                        maxlength="10"
                    />
                </div>
            </div>
            <div
                v-if="uploading"
                class="h-2 overflow-hidden rounded-full bg-muted"
            >
                <div
                    class="h-full rounded-full bg-brand-500 transition-all"
                    :style="{ width: uploadProgress + '%' }"
                />
            </div>
        </div>
        <template #footer>
            <button
                class="ff-btn-secondary"
                :disabled="uploading"
                @click="uploadOpen = false"
            >
                Cancel
            </button>
            <button
                class="ff-btn-primary"
                :disabled="uploading || !upload.file"
                @click="submitUpload"
            >
                {{ uploading ? `Uploading ${uploadProgress}%` : 'Upload' }}
            </button>
        </template>
    </ModalDialog>
</template>
