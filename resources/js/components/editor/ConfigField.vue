<script>
import AppIcon from '@/components/flow/AppIcon.vue';
import DropdownMenu from '@/components/flow/DropdownMenu.vue';
import ToggleSwitch from '@/components/flow/ToggleSwitch.vue';

/**
 * Renders one node configuration field based on its schema type:
 * text, textarea, number, select, boolean, json, keyvalue, conditions,
 * filters, list, cases.
 */
export default {
    name: 'ConfigField',
    components: { AppIcon, DropdownMenu, ToggleSwitch },
    props: {
        field: { type: Object, required: true },
        modelValue: { default: null },
        error: { type: String, default: null },
        readonly: { type: Boolean, default: false },
        references: { type: Array, default: () => [] },
        columns: { type: Array, default: () => [] },
        filterOperators: { type: Object, default: () => ({}) },
    },
    emits: ['update:modelValue'],
    data() {
        return { jsonText: '', jsonError: null, kvRows: [] };
    },
    computed: {
        id() {
            return `cfg-${this.field.key}`;
        },
        isExpression() {
            return (
                typeof this.modelValue === 'string' &&
                this.modelValue.includes('{{')
            );
        },
        listText() {
            return Array.isArray(this.modelValue)
                ? this.modelValue.join(', ')
                : this.modelValue || '';
        },
        operators() {
            return this.field.operators || {};
        },
        unary() {
            return this.field.unary || [];
        },
    },
    watch: {
        modelValue: {
            immediate: true,
            handler(value) {
                if (this.field.type === 'json') {
                    const text =
                        typeof value === 'string'
                            ? value
                            : JSON.stringify(value ?? {}, null, 2);
                    if (!this.jsonError || text !== this.jsonText) {
                        this.jsonText = text;
                    }
                }
                if (this.field.type === 'keyvalue') {
                    this.syncKvRows(value);
                }
            },
        },
    },
    methods: {
        emit(value) {
            this.$emit('update:modelValue', value);
        },
        insert(reference) {
            const current =
                typeof this.modelValue === 'string' ? this.modelValue : '';
            this.emit(`${current}{{ ${reference} }}`);
        },
        onNumber(event) {
            const raw = event.target.value;
            this.emit(
                raw === '' ? null : raw.includes('{{') ? raw : Number(raw),
            );
        },
        onJson(text) {
            this.jsonText = text;
            if (
                text.includes('{{') &&
                !text.trim().startsWith('{') &&
                !text.trim().startsWith('[')
            ) {
                this.jsonError = null;
                this.emit(text);
                return;
            }
            try {
                this.emit(JSON.parse(text || '{}'));
                this.jsonError = null;
            } catch (e) {
                this.jsonError = 'Invalid JSON: ' + e.message;
            }
        },
        // ---- key/value -------------------------------------------------
        syncKvRows(value) {
            const entries =
                value && typeof value === 'object' && !Array.isArray(value)
                    ? Object.entries(value)
                    : [];
            const current = this.kvRows
                .filter((r) => r.key !== '')
                .map((r) => [r.key, r.raw]);
            if (
                JSON.stringify(current) ===
                JSON.stringify(entries.map(([k, v]) => [k, v]))
            ) {
                return;
            }
            this.kvRows = entries.map(([key, raw]) => ({
                key,
                raw,
                text: typeof raw === 'string' ? raw : JSON.stringify(raw),
            }));
        },
        kvParse(text) {
            const trimmed = text.trim();
            if (
                /^[[{]/.test(trimmed) ||
                /^(true|false|null|-?\d+(\.\d+)?)$/.test(trimmed)
            ) {
                try {
                    return JSON.parse(trimmed);
                } catch {
                    return text;
                }
            }
            return text;
        },
        kvCommit() {
            const out = {};
            for (const row of this.kvRows) {
                if (row.key !== '') {
                    row.raw = this.kvParse(row.text);
                    out[row.key] = row.raw;
                }
            }
            this.emit(out);
        },
        kvAdd() {
            this.kvRows.push({ key: '', raw: '', text: '' });
        },
        kvRemove(index) {
            this.kvRows.splice(index, 1);
            this.kvCommit();
        },
        // ---- list rows (conditions / filters / cases) -------------------
        rows() {
            return Array.isArray(this.modelValue) ? this.modelValue : [];
        },
        updateRow(index, patch) {
            const rows = this.rows().map((r, i) =>
                i === index ? { ...r, ...patch } : r,
            );
            this.emit(rows);
        },
        addRow(template) {
            this.emit([...this.rows(), template]);
        },
        removeRow(index) {
            this.emit(this.rows().filter((_, i) => i !== index));
        },
    },
};
</script>

<template>
    <div>
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <label :for="id" class="text-xs font-semibold text-foreground/80">
                {{ field.label
                }}<span v-if="field.required" class="text-bad"> *</span>
            </label>
            <DropdownMenu
                v-if="
                    !readonly &&
                    field.expression &&
                    ['text', 'textarea', 'number', 'list'].includes(
                        field.type,
                    ) &&
                    references.length
                "
                width="w-72"
            >
                <template #trigger="{ toggle }">
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-bold text-brand-500 hover:bg-brand-500/10"
                        title="Insert a reference"
                        @click="toggle"
                    >
                        <AppIcon name="braces" :size="11" /> {{ '{' }}{{ '{' }}
                        {{ '}' }}{{ '}' }}
                    </button>
                </template>
                <div
                    class="px-2 pt-1 pb-1.5 text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Insert reference
                </div>
                <div class="max-h-64 overflow-y-auto">
                    <button
                        v-for="ref in references"
                        :key="ref.path"
                        type="button"
                        data-close
                        class="flex w-full cursor-pointer flex-col rounded-md px-2 py-1.5 text-left hover:bg-muted"
                        @click="insert(ref.path)"
                    >
                        <span class="font-mono text-[11px] text-foreground">{{
                            ref.path
                        }}</span>
                        <span class="text-[10px] text-muted-foreground">{{
                            ref.label
                        }}</span>
                    </button>
                </div>
            </DropdownMenu>
        </div>

        <!-- text -->
        <input
            v-if="field.type === 'text'"
            :id="id"
            class="ff-input"
            :class="{ 'font-mono text-xs': isExpression }"
            :value="modelValue ?? ''"
            :placeholder="field.placeholder || ''"
            :disabled="readonly"
            @input="emit($event.target.value)"
        />

        <!-- textarea -->
        <textarea
            v-else-if="field.type === 'textarea'"
            :id="id"
            class="ff-textarea"
            :class="{ 'font-mono text-xs': isExpression }"
            rows="3"
            :value="modelValue ?? ''"
            :placeholder="field.placeholder || ''"
            :disabled="readonly"
            @input="emit($event.target.value)"
        />

        <!-- number -->
        <input
            v-else-if="field.type === 'number'"
            :id="id"
            class="ff-input"
            :type="field.expression ? 'text' : 'number'"
            inputmode="numeric"
            :min="field.min"
            :max="field.max"
            :value="modelValue ?? ''"
            :disabled="readonly"
            @input="onNumber"
        />

        <!-- select -->
        <select
            v-else-if="field.type === 'select'"
            :id="id"
            class="ff-input"
            :value="modelValue ?? ''"
            :disabled="readonly"
            @change="emit($event.target.value)"
        >
            <option
                v-for="option in field.options || []"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>

        <!-- boolean -->
        <div
            v-else-if="field.type === 'boolean'"
            class="flex items-center gap-2"
        >
            <ToggleSwitch
                :model-value="Boolean(modelValue)"
                :disabled="readonly"
                :label="field.label"
                @update:model-value="emit"
            />
            <span class="text-sm text-muted-foreground">{{
                modelValue ? 'Yes' : 'No'
            }}</span>
        </div>

        <!-- list -->
        <input
            v-else-if="field.type === 'list'"
            :id="id"
            class="ff-input"
            :value="listText"
            placeholder="comma, separated, values"
            :disabled="readonly"
            @change="
                emit(
                    $event.target.value.includes('{{')
                        ? $event.target.value
                        : $event.target.value
                              .split(',')
                              .map((s) => s.trim())
                              .filter(Boolean),
                )
            "
        />

        <!-- json -->
        <div v-else-if="field.type === 'json'">
            <textarea
                :id="id"
                class="ff-textarea font-mono text-xs"
                rows="6"
                spellcheck="false"
                :value="jsonText"
                :disabled="readonly"
                @input="onJson($event.target.value)"
            />
            <p v-if="jsonError" class="mt-1 text-xs text-bad">
                {{ jsonError }}
            </p>
        </div>

        <!-- key / value -->
        <div v-else-if="field.type === 'keyvalue'" class="space-y-1.5">
            <div
                v-for="(row, index) in kvRows"
                :key="index"
                class="flex items-start gap-1.5"
            >
                <input
                    v-model="row.key"
                    class="ff-input h-8 w-[38%] font-mono text-xs"
                    placeholder="key"
                    :disabled="readonly"
                    @change="kvCommit"
                />
                <input
                    v-model="row.text"
                    class="ff-input h-8 flex-1 font-mono text-xs"
                    placeholder="value or {{ expression }}"
                    :disabled="readonly"
                    @change="kvCommit"
                />
                <button
                    v-if="!readonly"
                    type="button"
                    class="ff-icon-btn size-8"
                    aria-label="Remove"
                    @click="kvRemove(index)"
                >
                    <AppIcon name="x" :size="14" />
                </button>
            </div>
            <button
                v-if="!readonly"
                type="button"
                class="ff-btn-ghost ff-btn-sm"
                @click="kvAdd"
            >
                <AppIcon name="plus" :size="13" /> Add field
            </button>
        </div>

        <!-- conditions -->
        <div v-else-if="field.type === 'conditions'" class="space-y-2">
            <div
                v-for="(row, index) in rows()"
                :key="index"
                class="rounded-xl border border-border bg-muted/30 p-2"
            >
                <div class="flex gap-1.5">
                    <input
                        class="ff-input h-8 flex-1 font-mono text-xs"
                        :value="row.left ?? ''"
                        placeholder="{{ item.email }}"
                        :disabled="readonly"
                        @input="updateRow(index, { left: $event.target.value })"
                    />
                    <button
                        v-if="!readonly"
                        type="button"
                        class="ff-icon-btn size-8"
                        aria-label="Remove condition"
                        @click="removeRow(index)"
                    >
                        <AppIcon name="x" :size="14" />
                    </button>
                </div>
                <div class="mt-1.5 flex gap-1.5">
                    <select
                        class="ff-input h-8 w-1/2 text-xs"
                        :value="row.operator"
                        :disabled="readonly"
                        @change="
                            updateRow(index, { operator: $event.target.value })
                        "
                    >
                        <option
                            v-for="(label, op) in operators"
                            :key="op"
                            :value="op"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <input
                        v-if="!unary.includes(row.operator)"
                        class="ff-input h-8 flex-1 font-mono text-xs"
                        :value="
                            typeof row.right === 'object' && row.right !== null
                                ? JSON.stringify(row.right)
                                : (row.right ?? '')
                        "
                        placeholder="value"
                        :disabled="readonly"
                        @input="
                            updateRow(index, { right: $event.target.value })
                        "
                    />
                </div>
            </div>
            <button
                v-if="!readonly"
                type="button"
                class="ff-btn-ghost ff-btn-sm"
                @click="addRow({ left: '', operator: 'equals', right: '' })"
            >
                <AppIcon name="plus" :size="13" /> Add condition
            </button>
        </div>

        <!-- filters -->
        <div v-else-if="field.type === 'filters'" class="space-y-2">
            <div
                v-for="(row, index) in rows()"
                :key="index"
                class="flex flex-wrap gap-1.5 rounded-xl border border-border bg-muted/30 p-2"
            >
                <select
                    class="ff-input h-8 min-w-0 flex-1 text-xs"
                    :value="row.column"
                    :disabled="readonly"
                    @change="updateRow(index, { column: $event.target.value })"
                >
                    <option value="" disabled>column</option>
                    <option
                        v-for="column in columns"
                        :key="column"
                        :value="column"
                    >
                        {{ column }}
                    </option>
                </select>
                <select
                    class="ff-input h-8 w-[42%] text-xs"
                    :value="row.operator"
                    :disabled="readonly"
                    @change="
                        updateRow(index, { operator: $event.target.value })
                    "
                >
                    <option
                        v-for="(label, op) in filterOperators"
                        :key="op"
                        :value="op"
                    >
                        {{ label }}
                    </option>
                </select>
                <input
                    v-if="!['is_null', 'is_not_null'].includes(row.operator)"
                    class="ff-input h-8 w-full font-mono text-xs"
                    :value="row.value ?? ''"
                    placeholder="value or {{ expression }}"
                    :disabled="readonly"
                    @input="updateRow(index, { value: $event.target.value })"
                />
                <button
                    v-if="!readonly"
                    type="button"
                    class="ff-btn-ghost ml-auto ff-btn-sm text-bad"
                    @click="removeRow(index)"
                >
                    Remove
                </button>
            </div>
            <button
                v-if="!readonly"
                type="button"
                class="ff-btn-ghost ff-btn-sm"
                @click="
                    addRow({
                        column: columns[0] || '',
                        operator: '=',
                        value: '',
                    })
                "
            >
                <AppIcon name="plus" :size="13" /> Add filter
            </button>
        </div>

        <!-- switch cases -->
        <div v-else-if="field.type === 'cases'" class="space-y-1.5">
            <div
                v-for="(row, index) in rows()"
                :key="index"
                class="flex items-center gap-1.5"
            >
                <span
                    class="w-14 shrink-0 font-mono text-[10px] text-muted-foreground"
                    >case_{{ index }}</span
                >
                <input
                    class="ff-input h-8 flex-1 text-xs"
                    :value="row.value ?? ''"
                    placeholder="value"
                    :disabled="readonly"
                    @input="updateRow(index, { value: $event.target.value })"
                />
                <button
                    v-if="!readonly"
                    type="button"
                    class="ff-icon-btn size-8"
                    aria-label="Remove case"
                    @click="removeRow(index)"
                >
                    <AppIcon name="x" :size="14" />
                </button>
            </div>
            <button
                v-if="!readonly"
                type="button"
                class="ff-btn-ghost ff-btn-sm"
                @click="addRow({ value: '' })"
            >
                <AppIcon name="plus" :size="13" /> Add case
            </button>
        </div>

        <p v-if="error" class="mt-1 text-xs font-medium text-bad">
            {{ error }}
        </p>
        <p
            v-else-if="field.help"
            class="mt-1 text-[11px] text-muted-foreground"
        >
            {{ field.help }}
        </p>
    </div>
</template>
