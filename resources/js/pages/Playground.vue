<script>
import { Head } from '@inertiajs/vue3';
import AppIcon from '@/components/flow/AppIcon.vue';
import JsonView from '@/components/flow/JsonView.vue';
import PageHeader from '@/components/flow/PageHeader.vue';
import { errorMessage } from '@/lib/api';
import { formatDuration } from '@/lib/format';

const TOOLS = [
    {
        key: 'text',
        label: 'Text generation',
        icon: 'sparkles',
        color: '#6161ff',
    },
    { key: 'image', label: 'Image spec', icon: 'image', color: '#ff7575' },
    {
        key: 'moderation',
        label: 'Moderation',
        icon: 'shield-alert',
        color: '#e2445c',
    },
    {
        key: 'translation',
        label: 'Translation',
        icon: 'languages',
        color: '#00c875',
    },
    { key: 'churn', label: 'Churn score', icon: 'gauge', color: '#fdab3d' },
];

const TASKS = {
    generic: 'Free-form text',
    summary: 'Summarize',
    media_metadata: 'OTT metadata',
    thumbnail_prompt: 'Thumbnail prompt',
    retention_message: 'Win-back message',
    onboarding_checklist: 'Onboarding checklist',
};

export default {
    name: 'PlaygroundPage',
    components: { AppIcon, Head, JsonView, PageHeader },
    data() {
        return {
            tools: TOOLS,
            tasks: TASKS,
            tool: 'text',
            task: 'media_metadata',
            prompt: 'A lighthouse keeper discovers that the storms are being caused by someone on the mainland.',
            context: '{\n  "title": "The Last Lighthouse"\n}',
            targetLanguage: 'es',
            aspectRatio: '16:9',
            subscriber:
                '{\n  "last_active_at": "2026-08-01",\n  "watch_minutes_30d": 20,\n  "failed_payments": 1,\n  "plan": "basic"\n}',
            running: false,
            result: null,
            duration: null,
        };
    },
    methods: {
        formatDuration,
        async run() {
            const body = { tool: this.tool, prompt: this.prompt };
            try {
                if (this.tool === 'text') {
                    body.task = this.task;
                    body.context = JSON.parse(this.context || '{}');
                }
                if (this.tool === 'churn') {
                    body.subscriber = JSON.parse(this.subscriber || '{}');
                }
            } catch {
                this.$toast.error('Context must be valid JSON.');
                return;
            }
            body.target_language = this.targetLanguage;
            body.aspect_ratio = this.aspectRatio;

            this.running = true;
            try {
                const { data } = await this.$api.post('/playground', body);
                this.result = data.result;
                this.duration = data.duration_ms;
            } catch (error) {
                this.$toast.error(errorMessage(error));
            } finally {
                this.running = false;
            }
        },
    },
};
</script>

<template>
    <Head title="AI Playground" />

    <PageHeader
        title="AI Playground"
        description="Try the same adapters that workflow nodes use. Current adapters are deterministic mocks — every result says so."
    />

    <div class="mb-5 flex flex-wrap gap-2">
        <button
            v-for="t in tools"
            :key="t.key"
            type="button"
            class="flex cursor-pointer items-center gap-2.5 ff-card px-4 py-3 text-sm font-semibold transition"
            :class="
                tool === t.key
                    ? 'ring-2 ring-brand-500'
                    : 'hover:-translate-y-0.5'
            "
            @click="((tool = t.key), (result = null))"
        >
            <span
                class="flex size-7 items-center justify-center rounded-lg"
                :style="{ backgroundColor: t.color + '1f', color: t.color }"
                ><AppIcon :name="t.icon" :size="15"
            /></span>
            {{ t.label }}
        </button>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="space-y-4 ff-card p-5">
            <div v-if="tool === 'text'">
                <label class="ff-label" for="pg-task">Task</label>
                <select id="pg-task" v-model="task" class="ff-input">
                    <option
                        v-for="(label, key) in tasks"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select>
            </div>
            <div v-if="tool !== 'churn'">
                <label class="ff-label" for="pg-prompt">{{
                    tool === 'moderation'
                        ? 'Text to moderate'
                        : tool === 'translation'
                          ? 'Text to translate'
                          : 'Prompt'
                }}</label>
                <textarea
                    id="pg-prompt"
                    v-model="prompt"
                    class="ff-textarea"
                    rows="5"
                    maxlength="4000"
                />
            </div>
            <div v-if="tool === 'text'">
                <label class="ff-label" for="pg-context">Context (JSON)</label>
                <textarea
                    id="pg-context"
                    v-model="context"
                    class="ff-textarea font-mono text-xs"
                    rows="5"
                    spellcheck="false"
                />
            </div>
            <div v-if="tool === 'translation'">
                <label class="ff-label" for="pg-lang">Target language</label>
                <input
                    id="pg-lang"
                    v-model="targetLanguage"
                    class="ff-input w-32"
                    maxlength="10"
                />
            </div>
            <div v-if="tool === 'image'">
                <label class="ff-label" for="pg-ar">Aspect ratio</label>
                <select id="pg-ar" v-model="aspectRatio" class="ff-input w-40">
                    <option
                        v-for="r in ['16:9', '9:16', '1:1', '4:3', '2:3']"
                        :key="r"
                    >
                        {{ r }}
                    </option>
                </select>
            </div>
            <div v-if="tool === 'churn'">
                <label class="ff-label" for="pg-sub"
                    >Subscriber record (JSON)</label
                >
                <textarea
                    id="pg-sub"
                    v-model="subscriber"
                    class="ff-textarea font-mono text-xs"
                    rows="8"
                    spellcheck="false"
                />
                <p class="mt-1 text-xs text-muted-foreground">
                    Transparent rule-based scoring — not a trained model.
                </p>
            </div>
            <button
                class="ff-btn-primary w-full"
                :disabled="running"
                @click="run"
            >
                <AppIcon
                    :name="running ? 'loader-circle' : 'play'"
                    :size="15"
                    :class="{ 'animate-spin': running }"
                />
                Run
            </button>
        </section>

        <section class="ff-card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold">Result</h2>
                <div class="flex items-center gap-2">
                    <span
                        v-if="result?.simulated"
                        class="ff-chip bg-cat-ai/15 text-cat-ai"
                        ><AppIcon name="flask-conical" :size="12" />
                        Simulated</span
                    >
                    <span v-else-if="result" class="ff-chip bg-ok/15 text-ok"
                        ><AppIcon name="check" :size="12" /> Implemented</span
                    >
                    <span
                        v-if="duration !== null"
                        class="text-xs text-muted-foreground"
                        >{{ formatDuration(duration) }}</span
                    >
                </div>
            </div>
            <div
                v-if="!result"
                class="flex h-64 flex-col items-center justify-center text-center text-sm text-muted-foreground"
            >
                <AppIcon
                    name="sparkle"
                    :size="28"
                    class="mb-2 text-brand-500"
                />
                Run a tool to see its structured output.
            </div>
            <template v-else>
                <p
                    v-if="result.notice"
                    class="mb-3 rounded-lg bg-cat-ai/10 p-2.5 text-xs text-cat-ai"
                >
                    {{ result.notice }}
                </p>
                <div
                    v-if="result.text"
                    class="mb-3 rounded-xl border border-border bg-muted/40 p-3 text-sm whitespace-pre-line"
                >
                    {{ result.text }}
                </div>
                <div
                    v-if="tool === 'churn'"
                    class="mb-3 flex items-center gap-4"
                >
                    <div
                        class="text-4xl font-extrabold"
                        :class="
                            result.level === 'high'
                                ? 'text-bad'
                                : result.level === 'medium'
                                  ? 'text-wait'
                                  : 'text-ok'
                        "
                    >
                        {{ result.score }}
                    </div>
                    <div class="text-sm">
                        <div class="font-semibold capitalize">
                            {{ result.level }} risk
                        </div>
                        <div class="text-muted-foreground">
                            {{ result.factors.length }} contributing rules
                        </div>
                    </div>
                </div>
                <JsonView :value="result" max-height="max-h-[420px]" />
            </template>
        </section>
    </div>
</template>
