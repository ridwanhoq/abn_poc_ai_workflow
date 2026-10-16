<script>
import { Head } from '@inertiajs/vue3';
import GraphPreview from '@/components/editor/GraphPreview.vue';
import AppIcon from '@/components/flow/AppIcon.vue';
import EmptyState from '@/components/flow/EmptyState.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import { errorMessage } from '@/lib/api';
import { triggerLabel } from '@/lib/format';

export default {
    name: 'TemplatesPage',
    components: {
        AppIcon,
        EmptyState,
        GraphPreview,
        Head,
        ModalDialog,
        PageHeader,
    },
    data() {
        return {
            loading: true,
            templates: [],
            typeMap: {},
            integrations: {},
            category: '',
            q: '',
            preview: null,
            busy: null,
        };
    },
    computed: {
        categories() {
            return [
                ...new Set(
                    this.templates.map((t) => t.category.split(' / ')[0]),
                ),
            ];
        },
        filtered() {
            const q = this.q.trim().toLowerCase();
            return this.templates.filter(
                (t) =>
                    (!this.category || t.category.startsWith(this.category)) &&
                    (!q ||
                        t.name.toLowerCase().includes(q) ||
                        t.description.toLowerCase().includes(q)),
            );
        },
    },
    async mounted() {
        try {
            const requests = [
                this.$api.get('/templates'),
                this.$api.get('/node-types'),
            ];
            if (this.$can('integrations.view')) {
                requests.push(this.$api.get('/integrations'));
            }
            const [templates, types, integrations] =
                await Promise.all(requests);
            this.templates = templates.data.data;
            this.typeMap = Object.fromEntries(
                types.data.data.map((t) => [t.type, t]),
            );
            this.integrations = Object.fromEntries(
                (integrations?.data.data || []).map((i) => [i.key, i]),
            );
            const previewId = Number(
                new URLSearchParams(window.location.search).get('preview'),
            );
            if (previewId) {
                this.preview =
                    this.templates.find((t) => t.id === previewId) || null;
            }
        } catch (error) {
            this.$toast.error(errorMessage(error, 'Could not load templates.'));
        } finally {
            this.loading = false;
        }
    },
    methods: {
        triggerLabel,
        triggerOf(template) {
            return triggerLabel(
                (template.trigger_type || '').replace('trigger.', ''),
            );
        },
        integrationName(key) {
            return this.integrations[key]?.name || key;
        },
        integrationStatus(key) {
            return this.integrations[key]?.status || 'simulated';
        },
        async importTemplate(template, { test = false } = {}) {
            this.busy = template.id + (test ? '-test' : '');
            try {
                const { data } = await this.$api.post(
                    `/templates/${template.id}/import`,
                );
                const workflowId = data.data.id;
                if (test) {
                    const run = await this.$api.post(
                        `/workflows/${workflowId}/execute`,
                        { test: true },
                    );
                    window.dispatchEvent(new Event('ff:execution-started'));
                    this.$toast.success(
                        `"${template.name}" imported and test run started`,
                    );
                    this.$inertia.visit(`/executions/${run.data.data.id}`);
                } else {
                    this.$toast.success(
                        `"${template.name}" imported`,
                        'A new draft workflow was created — the template is unchanged.',
                    );
                    this.$inertia.visit(`/workflows/${workflowId}/edit`);
                }
            } catch (error) {
                this.$toast.error(errorMessage(error, 'Import failed.'));
            } finally {
                this.busy = null;
            }
        },
    },
};
</script>

<template>
    <Head title="Templates" />

    <PageHeader
        title="Templates"
        description="Ready-made OTT and ERP automations. Importing creates a new draft workflow you can configure and test — the template itself never changes."
    />

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <button
            v-for="c in ['', ...categories]"
            :key="c || 'all'"
            type="button"
            class="ff-chip cursor-pointer px-3.5 py-1.5 text-sm transition"
            :class="
                category === c
                    ? 'bg-brand-500 text-white shadow-sm shadow-brand-500/30'
                    : 'bg-card text-muted-foreground ring-1 ring-border hover:text-foreground'
            "
            @click="category = c"
        >
            {{ c || 'All templates' }}
        </button>
        <div class="relative ml-auto w-full sm:w-64">
            <AppIcon
                name="search"
                :size="15"
                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="q"
                type="search"
                class="ff-input pl-9"
                placeholder="Search templates"
                aria-label="Search templates"
            />
        </div>
    </div>

    <div v-if="loading" class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="i in 6" :key="i" class="h-72 ff-skeleton rounded-2xl" />
    </div>

    <EmptyState
        v-else-if="!filtered.length"
        icon="layout-template"
        title="No templates match"
        description="Clear the search or pick another category."
    />

    <div v-else class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        <article
            v-for="t in filtered"
            :key="t.id"
            class="group flex flex-col overflow-hidden ff-card transition hover:-translate-y-0.5 hover:shadow-lg"
        >
            <div
                class="relative h-28 overflow-hidden"
                :style="{
                    background: `linear-gradient(135deg, ${t.color}26, ${t.color}08)`,
                }"
            >
                <div
                    class="absolute -top-6 -right-6 size-28 rounded-full opacity-20"
                    :style="{ backgroundColor: t.color }"
                />
                <div class="flex h-full items-center gap-4 px-5">
                    <div
                        class="flex size-12 items-center justify-center rounded-2xl text-white shadow-lg"
                        :style="{
                            backgroundColor: t.color,
                            boxShadow: `0 8px 20px -8px ${t.color}`,
                        }"
                    >
                        <AppIcon :name="t.icon" :size="24" />
                    </div>
                    <div class="min-w-0">
                        <div
                            class="text-[11px] font-bold tracking-wide uppercase"
                            :style="{ color: t.color }"
                        >
                            {{ t.category }}
                        </div>
                        <div class="mt-0.5 text-xs text-muted-foreground">
                            {{ t.node_count }} nodes ·
                            {{ triggerOf(t) }} trigger
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex flex-1 flex-col p-5">
                <h2 class="text-base leading-snug font-bold">{{ t.name }}</h2>
                <p class="mt-1.5 line-clamp-3 text-sm text-muted-foreground">
                    {{ t.description }}
                </p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <span
                        v-for="tag in t.tags"
                        :key="tag"
                        class="rounded-md bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground"
                        >{{ tag }}</span
                    >
                </div>
                <div
                    v-if="t.simulated_nodes.length"
                    class="mt-3 flex items-center gap-1.5 text-xs text-cat-ai"
                >
                    <AppIcon name="flask-conical" :size="13" />
                    {{ t.simulated_nodes.length }} simulated step{{
                        t.simulated_nodes.length === 1 ? '' : 's'
                    }}
                    · runs without credentials
                </div>
                <div class="mt-auto flex flex-wrap gap-2 pt-5">
                    <button
                        class="ff-btn-secondary flex-1"
                        @click="preview = t"
                    >
                        <AppIcon name="eye" :size="15" /> Preview
                    </button>
                    <button
                        v-if="$can('templates.import')"
                        class="ff-btn-primary flex-1"
                        :disabled="busy !== null"
                        @click="importTemplate(t)"
                    >
                        <AppIcon
                            :name="busy === t.id ? 'loader-circle' : 'download'"
                            :size="15"
                            :class="{ 'animate-spin': busy === t.id }"
                        />
                        Import
                    </button>
                </div>
            </div>
        </article>
    </div>

    <ModalDialog
        :open="Boolean(preview)"
        :title="preview?.name || ''"
        :description="preview?.category || ''"
        width="max-w-5xl"
        @close="preview = null"
    >
        <template v-if="preview">
            <p class="mb-4 text-sm text-muted-foreground">
                {{ preview.description }}
            </p>
            <GraphPreview
                :definition="preview.definition"
                :type-map="typeMap"
                height="h-[420px]"
            />
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <div class="ff-label">Required integrations</div>
                    <ul class="space-y-1.5">
                        <li
                            v-for="key in preview.required_integrations"
                            :key="key"
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <span class="flex items-center gap-2"
                                ><AppIcon
                                    name="plug"
                                    :size="14"
                                    class="text-muted-foreground"
                                />
                                {{ integrationName(key) }}</span
                            >
                            <span
                                class="ff-chip px-2 py-px text-[11px]"
                                :class="
                                    integrationStatus(key) === 'connected'
                                        ? 'bg-ok/15 text-ok'
                                        : integrationStatus(key) === 'simulated'
                                          ? 'bg-cat-ai/15 text-cat-ai'
                                          : 'bg-wait/15 text-wait'
                                "
                                >{{
                                    integrationStatus(key).replace('_', ' ')
                                }}</span
                            >
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="ff-label">Simulated steps</div>
                    <p
                        v-if="!preview.simulated_nodes.length"
                        class="text-sm text-muted-foreground"
                    >
                        None — every step is implemented.
                    </p>
                    <ul v-else class="space-y-1 text-sm">
                        <li
                            v-for="name in preview.simulated_nodes"
                            :key="name"
                            class="flex items-center gap-2"
                        >
                            <AppIcon
                                name="flask-conical"
                                :size="13"
                                class="text-cat-ai"
                            />
                            {{ name }}
                        </li>
                    </ul>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Simulated steps return deterministic sample data and are
                        labelled in every result.
                    </p>
                </div>
            </div>
        </template>
        <template #footer>
            <button class="ff-btn-secondary" @click="preview = null">
                Close
            </button>
            <template v-if="preview && $can('templates.import')">
                <button
                    v-if="$can('workflows.execute')"
                    class="ff-btn-secondary"
                    :disabled="busy !== null"
                    @click="importTemplate(preview, { test: true })"
                >
                    <AppIcon name="play" :size="15" /> Import & test run
                </button>
                <button
                    class="ff-btn-primary"
                    :disabled="busy !== null"
                    @click="importTemplate(preview)"
                >
                    <AppIcon name="settings" :size="15" /> Import & configure
                </button>
            </template>
        </template>
    </ModalDialog>
</template>
