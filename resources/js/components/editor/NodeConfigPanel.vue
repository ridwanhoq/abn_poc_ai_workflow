<script>
import AppIcon from '@/components/flow/AppIcon.vue';
import JsonView from '@/components/flow/JsonView.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import { CATEGORY_META, formatDuration, statusMeta } from '@/lib/format';
import ConfigField from './ConfigField.vue';

export default {
    name: 'NodeConfigPanel',
    components: { AppIcon, ConfigField, JsonView, StatusChip },
    props: {
        node: { type: Object, required: true },
        readonly: { type: Boolean, default: false },
        issues: { type: Array, default: () => [] },
        references: { type: Array, default: () => [] },
        dataModels: { type: Array, default: () => [] },
        filterOperators: { type: Object, default: () => ({}) },
        run: { type: Object, default: null },
        webhook: { type: Object, default: null },
        workflowStatus: { type: String, default: 'draft' },
        canRunFromHere: { type: Boolean, default: false },
    },
    emits: [
        'close',
        'change',
        'rename-key',
        'delete',
        'duplicate',
        'run-from',
        'rotate-webhook',
    ],
    data() {
        return { tab: 'parameters', keyDraft: this.node.id, showSecret: false };
    },
    computed: {
        meta() {
            return this.node.data.meta || {};
        },
        category() {
            return CATEGORY_META[this.meta.category] || CATEGORY_META.utility;
        },
        fieldErrors() {
            const out = {};
            for (const issue of this.issues) {
                if (issue.field && !out[issue.field]) {
                    out[issue.field] = issue.message;
                }
            }
            return out;
        },
        generalIssues() {
            return this.issues.filter(
                (i) =>
                    !i.field ||
                    !this.meta.config_schema?.some((f) => f.key === i.field),
            );
        },
        columns() {
            const model = this.dataModels.find(
                (m) => m.alias === this.node.data.config.model,
            );
            return model ? model.readable : [];
        },
        settings() {
            return this.node.data.settings || {};
        },
        implementationMeta() {
            return statusMeta(this.meta.implementation);
        },
    },
    watch: {
        'node.id'(value) {
            this.keyDraft = value;
            if (this.tab === 'output' && !this.run) {
                this.tab = 'parameters';
            }
        },
        run(value) {
            if (value && ['failed'].includes(value.status)) {
                this.tab = 'output';
            }
        },
    },
    methods: {
        formatDuration,
        setConfig(key, value) {
            this.node.data.config = { ...this.node.data.config, [key]: value };
            this.$emit('change');
        },
        setSetting(key, value) {
            const next = { ...this.settings };
            if (value === '' || value === null || value === undefined) {
                delete next[key];
            } else {
                next[key] = value;
            }
            this.node.data.settings = next;
            this.$emit('change');
        },
        setName(value) {
            this.node.data.name = value;
            this.$emit('change');
        },
        commitKey() {
            const key = this.keyDraft.trim();
            if (key !== this.node.id) {
                this.$emit('rename-key', {
                    from: this.node.id,
                    to: key,
                    revert: () => (this.keyDraft = this.node.id),
                });
            }
        },
        async copy(text) {
            try {
                await navigator.clipboard.writeText(text);
                this.$toast.success('Copied to clipboard');
            } catch {
                this.$toast.error(
                    'Copy failed — your browser blocked clipboard access.',
                );
            }
        },
    },
};
</script>

<template>
    <div class="flex h-full flex-col bg-card">
        <!-- Header -->
        <div class="border-b border-border p-4">
            <div class="flex items-start gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                    :style="{
                        backgroundColor: category.color + '1f',
                        color: category.color,
                    }"
                >
                    <AppIcon :name="meta.icon" :size="20" />
                </div>
                <div class="min-w-0 flex-1">
                    <input
                        class="-mx-1 w-full rounded-md bg-transparent px-1 text-[15px] font-bold outline-none hover:bg-muted focus:bg-muted"
                        :value="node.data.name"
                        :disabled="readonly"
                        aria-label="Node name"
                        @input="setName($event.target.value)"
                    />
                    <div
                        class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span>{{ meta.label }}</span>
                        <span
                            class="ff-chip px-1.5 py-0 text-[10px]"
                            :style="{
                                backgroundColor:
                                    implementationMeta.color + '1f',
                                color: implementationMeta.color,
                            }"
                        >
                            {{ implementationMeta.label }}
                        </span>
                    </div>
                </div>
                <button
                    type="button"
                    class="-mt-1 -mr-1 ff-icon-btn size-8"
                    aria-label="Close panel"
                    @click="$emit('close')"
                >
                    <AppIcon name="x" :size="16" />
                </button>
            </div>
            <div
                class="mt-3 flex gap-1 rounded-lg bg-muted p-1 text-xs font-semibold"
            >
                <button
                    v-for="t in [
                        { key: 'parameters', label: 'Parameters' },
                        { key: 'settings', label: 'Settings' },
                        { key: 'output', label: 'Output' },
                        { key: 'info', label: 'Info' },
                    ]"
                    :key="t.key"
                    type="button"
                    class="flex-1 cursor-pointer rounded-md py-1.5 transition"
                    :class="
                        tab === t.key
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="tab = t.key"
                >
                    {{ t.label }}
                    <span
                        v-if="t.key === 'parameters' && issues.length"
                        class="ml-0.5 text-bad"
                        >•</span
                    >
                    <span
                        v-if="t.key === 'output' && run"
                        class="ml-0.5"
                        :style="{
                            color:
                                run.status === 'failed' ? '#e2445c' : '#00c875',
                        }"
                        >•</span
                    >
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4">
            <!-- Parameters -->
            <div v-show="tab === 'parameters'" class="space-y-4">
                <div
                    v-if="meta.implementation === 'simulated'"
                    class="flex gap-2 rounded-xl border border-cat-ai/30 bg-cat-ai/8 p-3 text-xs"
                >
                    <AppIcon
                        name="flask-conical"
                        :size="15"
                        class="mt-px shrink-0 text-cat-ai"
                    />
                    <p class="text-muted-foreground">
                        <strong class="text-foreground">Simulated.</strong> This
                        node uses a mock adapter with deterministic sample
                        output — no external service is called.
                    </p>
                </div>

                <div
                    v-if="generalIssues.length"
                    class="space-y-1 rounded-xl border border-bad/30 bg-bad/8 p-3"
                >
                    <p
                        v-for="(issue, i) in generalIssues"
                        :key="i"
                        class="flex gap-1.5 text-xs text-bad"
                    >
                        <AppIcon
                            name="triangle-alert"
                            :size="13"
                            class="mt-px shrink-0"
                        />
                        {{ issue.message }}
                    </p>
                </div>

                <!-- Webhook details -->
                <div
                    v-if="node.data.type === 'trigger.webhook'"
                    class="space-y-2 rounded-xl border border-border bg-muted/40 p-3 text-xs"
                >
                    <div class="font-bold">Webhook endpoint</div>
                    <template v-if="webhook">
                        <div class="flex items-center gap-1">
                            <code
                                class="flex-1 truncate rounded bg-card px-2 py-1"
                                >{{ webhook.url }}</code
                            >
                            <button
                                type="button"
                                class="ff-icon-btn size-7"
                                title="Copy URL"
                                @click="copy(webhook.url)"
                            >
                                <AppIcon name="copy" :size="13" />
                            </button>
                        </div>
                        <div class="flex items-center gap-1">
                            <code
                                class="flex-1 truncate rounded bg-card px-2 py-1"
                                >{{
                                    showSecret
                                        ? webhook.secret
                                        : '••••••••••••••••'
                                }}</code
                            >
                            <button
                                type="button"
                                class="ff-icon-btn size-7"
                                :title="
                                    showSecret ? 'Hide secret' : 'Show secret'
                                "
                                @click="showSecret = !showSecret"
                            >
                                <AppIcon
                                    :name="showSecret ? 'eye-off' : 'eye'"
                                    :size="13"
                                />
                            </button>
                            <button
                                type="button"
                                class="ff-icon-btn size-7"
                                title="Copy secret"
                                @click="copy(webhook.secret)"
                            >
                                <AppIcon name="copy" :size="13" />
                            </button>
                        </div>
                        <p class="text-muted-foreground">
                            POST JSON with header
                            <code
                                >X-Workflow-Signature: sha256=HMAC_SHA256(body,
                                secret)</code
                            >. Optional
                            <code>Idempotency-Key</code> de-duplicates
                            deliveries.
                        </p>
                        <button
                            v-if="!readonly"
                            type="button"
                            class="ff-btn-secondary ff-btn-sm"
                            @click="$emit('rotate-webhook')"
                        >
                            <AppIcon name="refresh-cw" :size="12" /> Rotate
                            credentials
                        </button>
                    </template>
                    <p v-else class="text-muted-foreground">
                        Save and activate the workflow to generate the signed
                        endpoint URL and secret.
                    </p>
                </div>

                <ConfigField
                    v-for="field in meta.config_schema || []"
                    :key="node.id + field.key"
                    :field="field"
                    :model-value="node.data.config[field.key]"
                    :error="fieldErrors[field.key]"
                    :readonly="readonly"
                    :references="references"
                    :columns="columns"
                    :filter-operators="filterOperators"
                    @update:model-value="setConfig(field.key, $event)"
                />
                <p
                    v-if="!(meta.config_schema || []).length"
                    class="text-sm text-muted-foreground"
                >
                    This node has no parameters.
                </p>
            </div>

            <!-- Settings -->
            <div v-show="tab === 'settings'" class="space-y-4">
                <div>
                    <label class="ff-label" for="node-key">Node key</label>
                    <input
                        id="node-key"
                        v-model="keyDraft"
                        class="ff-input font-mono text-xs"
                        :disabled="readonly"
                        @change="commitKey"
                        @keydown.enter.prevent="commitKey"
                    />
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        Used in expressions:
                        <code v-pre class="font-mono">{{ nodes.</code
                        ><code class="font-mono">{{ node.id }}</code
                        ><code v-pre class="font-mono">.output }}</code>
                    </p>
                </div>
                <template v-if="meta.category !== 'trigger'">
                    <div>
                        <label class="ff-label" for="node-onerror"
                            >On error</label
                        >
                        <select
                            id="node-onerror"
                            class="ff-input"
                            :value="settings.on_error || 'inherit'"
                            :disabled="readonly"
                            @change="
                                setSetting(
                                    'on_error',
                                    $event.target.value === 'inherit'
                                        ? null
                                        : $event.target.value,
                                )
                            "
                        >
                            <option value="inherit">
                                Use workflow failure policy
                            </option>
                            <option value="stop">Stop the workflow</option>
                            <option value="continue">
                                Continue (skip downstream of this node)
                            </option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="ff-label" for="node-retries"
                                >Retries</label
                            >
                            <input
                                id="node-retries"
                                type="number"
                                min="0"
                                max="5"
                                class="ff-input"
                                :value="settings.retries ?? ''"
                                placeholder="default"
                                :disabled="readonly"
                                @change="
                                    setSetting(
                                        'retries',
                                        $event.target.value === ''
                                            ? null
                                            : Number($event.target.value),
                                    )
                                "
                            />
                        </div>
                        <div>
                            <label class="ff-label" for="node-backoff"
                                >Backoff (ms)</label
                            >
                            <input
                                id="node-backoff"
                                type="number"
                                min="0"
                                max="10000"
                                step="100"
                                class="ff-input"
                                :value="settings.retry_backoff_ms ?? ''"
                                placeholder="default"
                                :disabled="readonly"
                                @change="
                                    setSetting(
                                        'retry_backoff_ms',
                                        $event.target.value === ''
                                            ? null
                                            : Number($event.target.value),
                                    )
                                "
                            />
                        </div>
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        Backoff doubles after each failed attempt (exponential).
                    </p>
                </template>
                <div
                    v-if="!readonly"
                    class="flex flex-wrap gap-2 border-t border-border pt-4"
                >
                    <button
                        v-if="meta.category !== 'trigger'"
                        type="button"
                        class="ff-btn-secondary ff-btn-sm"
                        @click="$emit('duplicate')"
                    >
                        <AppIcon name="copy" :size="13" /> Duplicate
                    </button>
                    <button
                        type="button"
                        class="ff-btn-secondary ff-btn-sm text-bad"
                        @click="$emit('delete')"
                    >
                        <AppIcon name="trash-2" :size="13" /> Delete node
                    </button>
                </div>
            </div>

            <!-- Output -->
            <div v-show="tab === 'output'" class="space-y-3">
                <template v-if="run">
                    <div class="flex items-center justify-between">
                        <StatusChip :status="run.status" />
                        <span class="text-xs text-muted-foreground">
                            {{ formatDuration(run.duration_ms)
                            }}<template v-if="run.count > 1">
                                · last of {{ run.count }} runs</template
                            >
                        </span>
                    </div>
                    <div
                        v-if="run.error"
                        class="rounded-xl border border-bad/30 bg-bad/8 p-3 text-xs text-bad"
                    >
                        <div class="mb-1 font-bold">Error</div>
                        {{ run.error }}
                    </div>
                    <JsonView
                        v-if="run.output !== undefined && run.output !== null"
                        :value="run.output"
                        label="Output"
                        max-height="max-h-96"
                    />
                    <JsonView
                        v-if="run.input !== undefined && run.input !== null"
                        :value="run.input"
                        label="Input"
                        max-height="max-h-60"
                    />
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Run the workflow to see this node's input, output and
                    errors.
                </p>
                <button
                    v-if="canRunFromHere && meta.category !== 'trigger'"
                    type="button"
                    class="ff-btn-secondary ff-btn-sm"
                    title="Re-run this node and everything after it using upstream outputs from the last execution"
                    @click="$emit('run-from')"
                >
                    <AppIcon name="square-play" :size="13" /> Execute from this
                    node
                </button>
            </div>

            <!-- Info -->
            <div v-show="tab === 'info'" class="space-y-4 text-sm">
                <p class="text-muted-foreground">{{ meta.description }}</p>
                <div>
                    <div class="ff-label">Type</div>
                    <code class="font-mono text-xs">{{ meta.type }}</code>
                </div>
                <div v-if="meta.integration">
                    <div class="ff-label">Integration</div>
                    <code class="font-mono text-xs">{{
                        meta.integration
                    }}</code>
                </div>
                <div>
                    <div class="ff-label">Outputs</div>
                    <div class="flex flex-wrap gap-1">
                        <span
                            v-for="h in node.data.handles"
                            :key="h"
                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px]"
                            >{{ h }}</span
                        >
                        <span
                            v-if="!node.data.handles.length"
                            class="text-xs text-muted-foreground"
                            >none (ends the workflow)</span
                        >
                    </div>
                </div>
                <div v-if="Object.keys(meta.output_schema || {}).length">
                    <div class="ff-label">Output fields</div>
                    <dl class="space-y-1.5">
                        <div
                            v-for="(desc, key) in meta.output_schema"
                            :key="key"
                            class="text-xs"
                        >
                            <dt class="font-mono font-semibold">{{ key }}</dt>
                            <dd class="text-muted-foreground">{{ desc }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</template>
