<script>
import { Head } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import { errorMessage, fieldErrors } from '@/lib/api';

const CATEGORY_ICON = {
    core: 'database',
    notifications: 'bell-ring',
    ai: 'sparkles',
    media: 'film',
    storage: 'package',
};

export default {
    name: 'IntegrationsPage',
    components: { AppIcon, Head, ModalDialog, PageHeader, StatusChip },
    data() {
        return {
            loading: true,
            integrations: [],
            demoMode: true,
            mailer: null,
            editing: null,
            rows: [{ key: '', value: '' }],
            saving: false,
            testing: null,
            // Integrations configured through environment variables, not stored credentials.
            envConfigured: {
                s3: 'WORKFLOW_UPLOAD_DISK=s3 + AWS_*',
                smtp_mail: 'MAIL_MAILER + MAIL_* (and demo mode off)',
            },
            claude: null,
            claudeForm: {
                api_key: '',
                enabled: false,
                model: 'claude-opus-5-5',
                effort: 'low',
            },
            claudeErrors: {},
        };
    },
    computed: {
        grouped() {
            const groups = {};
            for (const i of this.integrations) {
                (groups[i.category] ||= []).push(i);
            }
            return groups;
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        categoryIcon(category) {
            return CATEGORY_ICON[category] || 'plug';
        },
        async load() {
            try {
                const { data } = await this.$api.get('/integrations');
                this.integrations = data.data;
                this.demoMode = data.demo_mode;
                this.mailer = data.mailer;
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load integrations.'),
                );
            } finally {
                this.loading = false;
            }
        },
        async test(integration) {
            this.testing = integration.id;
            try {
                const { data } = await this.$api.post(
                    `/integrations/${integration.id}/test`,
                );
                (data.ok ? this.$toast.success : this.$toast.warning)(
                    integration.name,
                    data.message,
                );
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.testing = null;
            }
        },
        openClaude(integration) {
            const config = integration.configuration || {};
            this.claude = integration;
            this.claudeErrors = {};
            this.claudeForm = {
                api_key: '',
                enabled: Boolean(config.enabled),
                model: config.model || 'claude-opus-5-5',
                effort: config.effort || 'low',
            };
        },
        async saveClaude() {
            this.saving = true;
            this.claudeErrors = {};
            try {
                if (this.claudeForm.api_key) {
                    await this.$api.put(`/integrations/${this.claude.id}`, {
                        credentials: { api_key: this.claudeForm.api_key },
                    });
                }
                const { enabled, model, effort } = this.claudeForm;
                await this.$api.put(`/integrations/${this.claude.id}`, {
                    configuration: { enabled, model, effort },
                });
                this.$toast.success(
                    enabled ? 'Claude enabled' : 'Claude settings saved',
                    enabled
                        ? 'AI Text Generation nodes now call the Claude API.'
                        : 'AI Text nodes keep using the mock adapter.',
                );
                this.claude = null;
                this.load();
            } catch (error) {
                this.claudeErrors = fieldErrors(error);
                this.$toast.error(errorMessage(error));
            } finally {
                this.saving = false;
            }
        },
        configure(integration) {
            this.editing = integration;
            this.rows = [{ key: 'api_key', value: '' }];
        },
        async saveCredentials() {
            const credentials = Object.fromEntries(
                this.rows
                    .filter((r) => r.key && r.value)
                    .map((r) => [r.key, r.value]),
            );
            if (!Object.keys(credentials).length) {
                this.$toast.warning('Enter at least one credential value.');
                return;
            }
            this.saving = true;
            try {
                const { data } = await this.$api.put(
                    `/integrations/${this.editing.id}`,
                    { credentials },
                );
                this.$toast.success('Credentials saved', data.message);
                this.editing = null;
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.saving = false;
            }
        },
        async clearCredentials(integration) {
            const ok = await this.$confirm({
                title: `Remove credentials for ${integration.name}?`,
                confirmText: 'Remove',
                danger: true,
            });
            if (!ok) {
                return;
            }
            try {
                await this.$api.put(`/integrations/${integration.id}`, {
                    clear_credentials: true,
                });
                this.$toast.success('Credentials removed');
                this.load();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
    },
};
</script>

<template>
    <Head title="Integrations" />

    <PageHeader
        title="Integrations"
        description="Adapters used by workflow nodes. Simulated adapters work out of the box and are clearly labelled in every result."
    />

    <div class="mb-6 grid gap-3 md:grid-cols-2">
        <div class="flex gap-3 ff-card p-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                :class="
                    demoMode ? 'bg-cat-ai/15 text-cat-ai' : 'bg-ok/15 text-ok'
                "
            >
                <AppIcon
                    :name="demoMode ? 'flask-conical' : 'shield'"
                    :size="19"
                />
            </div>
            <div>
                <div class="font-bold">
                    Demo mode is {{ demoMode ? 'ON' : 'OFF' }}
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        demoMode
                            ? 'Emails are written to the Laravel log and outbound HTTP is simulated for every workflow. Set WORKFLOW_DEMO_MODE=false to allow real side effects.'
                            : 'Real side effects are possible for production runs of workflows that explicitly allow them.'
                    }}
                </p>
            </div>
        </div>
        <div class="flex gap-3 ff-card p-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-run/15 text-run"
            >
                <AppIcon name="mail" :size="19" />
            </div>
            <div>
                <div class="font-bold">
                    Mailer: <code class="font-mono text-sm">{{ mailer }}</code>
                </div>
                <p class="text-sm text-muted-foreground">
                    Production email uses the Laravel mailer configured in
                    <code>.env</code> (MAIL_MAILER). "log" and "array" count as
                    not configured.
                </p>
            </div>
        </div>
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-40 ff-skeleton rounded-2xl" />
    </div>

    <div
        v-for="(items, category) in grouped"
        v-else
        :key="category"
        class="mb-8"
    >
        <h2
            class="mb-3 flex items-center gap-2 text-sm font-bold tracking-wide text-muted-foreground uppercase"
        >
            <AppIcon :name="categoryIcon(category)" :size="15" /> {{ category }}
        </h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="i in items"
                :key="i.id"
                class="flex flex-col ff-card p-5"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="font-bold">{{ i.name }}</h3>
                        <div class="text-xs text-muted-foreground">
                            {{ i.provider }} ·
                            <code class="font-mono">{{ i.key }}</code>
                        </div>
                    </div>
                    <StatusChip :status="i.status" size="sm" />
                </div>
                <p class="mt-2 flex-1 text-sm text-muted-foreground">
                    {{ i.description }}
                </p>
                <div
                    v-if="i.used_by_nodes.length"
                    class="mt-3 flex flex-wrap gap-1"
                >
                    <span
                        v-for="n in i.used_by_nodes"
                        :key="n"
                        class="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground"
                        >{{ n }}</span
                    >
                </div>
                <div
                    class="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-3"
                >
                    <button
                        class="ff-btn-secondary ff-btn-sm"
                        :disabled="testing === i.id"
                        @click="test(i)"
                    >
                        <AppIcon name="plug-zap" :size="13" /> Check
                    </button>
                    <button
                        v-if="
                            $can('integrations.manage') && i.key === 'anthropic'
                        "
                        class="ff-btn-primary ff-btn-sm"
                        @click="openClaude(i)"
                    >
                        <AppIcon name="settings" :size="13" /> Configure
                    </button>
                    <span
                        v-else-if="
                            envConfigured[i.key] && i.status !== 'connected'
                        "
                        class="text-xs text-muted-foreground"
                        >Configured in <code>.env</code>:
                        {{ envConfigured[i.key] }}</span
                    >
                    <template
                        v-else-if="
                            $can('integrations.manage') &&
                            ['unavailable', 'not_configured'].includes(i.status)
                        "
                    >
                        <button
                            class="ff-btn-secondary ff-btn-sm"
                            @click="configure(i)"
                        >
                            <AppIcon name="key-round" :size="13" /> Credentials
                        </button>
                        <button
                            v-if="i.has_credentials"
                            class="ff-btn-ghost ff-btn-sm text-bad"
                            @click="clearCredentials(i)"
                        >
                            Remove
                        </button>
                    </template>
                    <span
                        v-if="i.has_credentials"
                        class="ml-auto inline-flex items-center gap-1 text-xs text-ok"
                        ><AppIcon name="lock" :size="12" /> Encrypted
                        credentials stored</span
                    >
                </div>
            </article>
        </div>
    </div>

    <ModalDialog
        :open="Boolean(editing)"
        :title="`Credentials · ${editing?.name || ''}`"
        description="Values are encrypted at rest and never shown again."
        @close="editing = null"
    >
        <div class="space-y-2">
            <div v-for="(row, index) in rows" :key="index" class="flex gap-2">
                <input
                    v-model="row.key"
                    class="ff-input w-2/5 font-mono text-xs"
                    placeholder="api_key"
                />
                <input
                    v-model="row.value"
                    type="password"
                    autocomplete="off"
                    class="ff-input flex-1"
                    placeholder="secret value"
                />
            </div>
            <button
                class="ff-btn-ghost ff-btn-sm"
                @click="rows.push({ key: '', value: '' })"
            >
                <AppIcon name="plus" :size="13" /> Add value
            </button>
            <p
                v-if="editing?.status === 'unavailable'"
                class="rounded-lg bg-wait/10 p-2 text-xs text-wait"
            >
                No adapter is implemented for this provider yet — credentials
                are stored for the future integration only.
            </p>
        </div>
        <template #footer>
            <button class="ff-btn-secondary" @click="editing = null">
                Cancel
            </button>
            <button
                class="ff-btn-primary"
                :disabled="saving"
                @click="saveCredentials"
            >
                Save encrypted
            </button>
        </template>
    </ModalDialog>

    <ModalDialog
        :open="Boolean(claude)"
        title="Anthropic Claude"
        description="Real text generation for AI Text Generation nodes. The key is stored encrypted and never shown again."
        @close="claude = null"
    >
        <form id="claude-form" class="space-y-4" @submit.prevent="saveClaude">
            <div>
                <label class="ff-label" for="claude-key">API key</label>
                <input
                    id="claude-key"
                    v-model="claudeForm.api_key"
                    type="password"
                    autocomplete="off"
                    class="ff-input"
                    :placeholder="
                        claude?.has_credentials
                            ? 'Stored — leave empty to keep it'
                            : 'sk-ant-…'
                    "
                />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="ff-label" for="claude-model">Model</label>
                    <select
                        id="claude-model"
                        v-model="claudeForm.model"
                        class="ff-input"
                    >
                        <option value="claude-opus-5-5">
                            Claude Opus 5.5 (default)
                        </option>
                        <option value="claude-sonnet-5-5">
                            Claude Sonnet 5.5
                        </option>
                        <option value="claude-haiku-5-5">
                            Claude Haiku 5.5
                        </option>
                    </select>
                </div>
                <div>
                    <label class="ff-label" for="claude-effort">Effort</label>
                    <select
                        id="claude-effort"
                        v-model="claudeForm.effort"
                        class="ff-input"
                    >
                        <option
                            v-for="e in [
                                'low',
                                'medium',
                                'high',
                                'xhigh',
                                'max',
                            ]"
                            :key="e"
                            :value="e"
                        >
                            {{ e }}
                        </option>
                    </select>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Copywriting and metadata tasks are short and well specified, so
                <strong>low</strong> effort keeps cost and latency down. Raise
                it only if output quality needs it.
            </p>
            <label
                class="flex items-start gap-3 rounded-xl border border-border p-3"
            >
                <input
                    v-model="claudeForm.enabled"
                    type="checkbox"
                    class="mt-0.5 size-4 accent-brand-500"
                />
                <span class="text-sm">
                    <span class="block font-semibold"
                        >Use Claude in workflows</span
                    >
                    <span class="block text-xs text-muted-foreground">
                        Applies to every AI Text Generation node, including test
                        runs. Workflow data in prompts is sent to the Claude
                        API. Turn off to return to the deterministic mock.
                    </span>
                </span>
            </label>
            <p
                v-if="claudeErrors['configuration.enabled']"
                class="text-xs text-bad"
            >
                {{ claudeErrors['configuration.enabled'] }}
            </p>
        </form>
        <template #footer>
            <button class="ff-btn-secondary" @click="claude = null">
                Cancel
            </button>
            <button
                class="ff-btn-primary"
                type="submit"
                form="claude-form"
                :disabled="saving"
            >
                Save
            </button>
        </template>
    </ModalDialog>
</template>
