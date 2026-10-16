<script>
import { Head, Link } from '@inertiajs/vue3';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MarkerType, VueFlow } from '@vue-flow/core';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import ConfigField from '@/components/editor/ConfigField.vue';
import FlowNode from '@/components/editor/FlowNode.vue';
import NodeConfigPanel from '@/components/editor/NodeConfigPanel.vue';
import NodeLibrary from '@/components/editor/NodeLibrary.vue';
import AppIcon from '@/components/flow/AppIcon.vue';
import ModalDialog from '@/components/flow/ModalDialog.vue';
import StatusChip from '@/components/flow/StatusChip.vue';
import ToggleSwitch from '@/components/flow/ToggleSwitch.vue';
import { errorMessage } from '@/lib/api';
import { onExecutionUpdated, realtime } from '@/lib/realtime';
import { CATEGORY_META, debounce, formatDuration, timeAgo } from '@/lib/format';

const FINISHED = ['success', 'partial', 'failed', 'cancelled'];
const HISTORY_LIMIT = 60;

function clone(value) {
    return JSON.parse(JSON.stringify(value ?? null));
}

export default {
    name: 'WorkflowEditor',
    components: {
        AppIcon,
        Background,
        ConfigField,
        Controls,
        FlowNode,
        Head,
        Link,
        MiniMap,
        ModalDialog,
        NodeConfigPanel,
        NodeLibrary,
        StatusChip,
        ToggleSwitch,
        VueFlow,
    },
    props: {
        workflowId: { type: Number, required: true },
    },
    data() {
        return {
            loading: true,
            workflow: null,
            nodeTypes: [],
            typeMap: {},
            dataModels: [],
            filterOperators: {},
            nodes: [],
            edges: [],
            name: '',
            description: '',
            savedSnapshot: '',
            selectedKey: null,
            validation: { valid: true, errors: [], warnings: [] },
            validating: false,
            saving: false,
            toggling: false,
            history: [],
            future: [],
            restoring: false,
            leaving: false,
            execution: null,
            runByNode: {},
            pollTimer: null,
            queuedSince: null,
            runOpen: false,
            runPayload: '{}',
            runPayloadError: null,
            settingsOpen: false,
            settingsDraft: {},
            versionsOpen: false,
            versions: [],
            detailsOpen: false,
            libraryOpen: true,
            issuesOpen: false,
            flow: null,
            defaultEdgeOptions: {
                type: 'smoothstep',
                markerEnd: MarkerType.ArrowClosed,
            },
        };
    },
    computed: {
        canEdit() {
            return Boolean(this.workflow?.can?.update);
        },
        canRun() {
            return Boolean(this.workflow?.can?.execute);
        },
        definition() {
            return {
                nodes: this.nodes.map((n) => {
                    const node = {
                        key: n.id,
                        type: n.data.type,
                        name: n.data.name,
                        position: {
                            x: Math.round(n.position.x),
                            y: Math.round(n.position.y),
                        },
                        config: n.data.config,
                    };
                    if (
                        n.data.settings &&
                        Object.keys(n.data.settings).length
                    ) {
                        node.settings = n.data.settings;
                    }
                    return node;
                }),
                edges: this.edges.map((e) => ({
                    id: e.id,
                    source: e.source,
                    target: e.target,
                    sourceHandle: e.sourceHandle || 'main',
                })),
            };
        },
        snapshot() {
            return JSON.stringify({
                d: this.definition,
                n: this.name,
                s: this.description,
            });
        },
        dirty() {
            return !this.loading && this.snapshot !== this.savedSnapshot;
        },
        selectedNode() {
            return this.nodes.find((n) => n.id === this.selectedKey) || null;
        },
        hasTrigger() {
            return this.nodes.some((n) => n.data.meta?.category === 'trigger');
        },
        issuesByNode() {
            const map = {};
            for (const error of this.validation.errors || []) {
                const key = error.node || '_workflow';
                (map[key] ||= []).push(error);
            }
            return map;
        },
        workflowIssues() {
            return this.issuesByNode._workflow || [];
        },
        isRunning() {
            return this.execution && !FINISHED.includes(this.execution.status);
        },
        references() {
            return this.selectedNode
                ? this.referencesFor(this.selectedNode.id)
                : [];
        },
        canRunFromHere() {
            return (
                this.canRun &&
                Boolean(
                    this.execution && FINISHED.includes(this.execution.status),
                )
            );
        },
        statusLabel() {
            return this.workflow?.status || 'draft';
        },
    },
    watch: {
        snapshot() {
            if (!this.loading) {
                this.debouncedValidate();
                if (!this.restoring) {
                    this.debouncedHistory();
                }
            }
        },
        validation() {
            this.decorateNodes();
        },
    },
    created() {
        this.debouncedValidate = debounce(this.validate, 650);
        this.debouncedHistory = debounce(this.pushHistory, 400);
    },
    async mounted() {
        window.addEventListener('keydown', this.onKeydown);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        this.removeInertiaGuard = this.$inertia.on('before', (event) => {
            const visit = event.detail.visit;
            if (this.dirty && !this.leaving && visit.method === 'get') {
                event.preventDefault();
                this.$confirm({
                    title: 'Discard unsaved changes?',
                    message:
                        'You have changes that are not saved. Leaving the editor discards them.',
                    confirmText: 'Leave without saving',
                    danger: true,
                }).then((ok) => {
                    if (ok) {
                        this.leaving = true;
                        this.$inertia.visit(visit.url);
                    }
                });
            }
        });
        if (window.innerWidth < 1024) {
            this.libraryOpen = false;
        }
        await this.load();
    },
    beforeUnmount() {
        window.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.removeInertiaGuard?.();
        clearTimeout(this.pollTimer);
        this.unsubscribeExecution?.();
    },
    methods: {
        formatDuration,
        timeAgo,
        // ------------------------------------------------------------------
        // Loading & mapping
        // ------------------------------------------------------------------
        async load() {
            this.loading = true;
            try {
                const [types, workflow] = await Promise.all([
                    this.$api.get('/node-types'),
                    this.$api.get(`/workflows/${this.workflowId}`),
                ]);
                this.nodeTypes = types.data.data;
                this.typeMap = Object.fromEntries(
                    this.nodeTypes.map((t) => [t.type, t]),
                );
                this.dataModels = types.data.data_models;
                this.filterOperators = types.data.filter_operators;
                this.applyWorkflow(workflow.data.data);
                this.history = [this.snapshot];
                await this.validate();
                this.loadLatestExecution();
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not load the workflow.'),
                );
            } finally {
                this.loading = false;
                this.$nextTick(() =>
                    this.flow?.fitView({
                        padding: 0.15,
                        maxZoom: 1,
                        minZoom: window.innerWidth < 768 ? 0.35 : 0.7,
                    }),
                );
            }
        },
        applyWorkflow(workflow) {
            this.workflow = workflow;
            this.name = workflow.name;
            this.description = workflow.description || '';
            this.setDefinition(workflow.definition);
            this.savedSnapshot = this.snapshot;
        },
        setDefinition(definition) {
            this.nodes = (definition?.nodes || []).map((n) =>
                this.toFlowNode(n),
            );
            this.edges = (definition?.edges || []).map((e) =>
                this.toFlowEdge(e),
            );
            if (
                this.selectedKey &&
                !this.nodes.some((n) => n.id === this.selectedKey)
            ) {
                this.selectedKey = null;
            }
            this.decorateNodes();
        },
        toFlowNode(node) {
            const meta = this.typeMap[node.type] || {
                label: node.type,
                category: 'utility',
                config_schema: [],
                output_handles: ['main'],
                accepts_input: true,
                icon: 'box',
            };
            const config = {
                ...clone(meta.defaults || {}),
                ...clone(node.config || {}),
            };
            return {
                id: node.key,
                type: 'workflow',
                position: {
                    x: node.position?.x ?? 0,
                    y: node.position?.y ?? 0,
                },
                data: {
                    type: node.type,
                    name: node.name || meta.label,
                    config,
                    settings: clone(node.settings || {}),
                    meta,
                    handles: this.handlesFor(meta, config),
                    editable: this.canEdit,
                    issues: 0,
                    run: this.runByNode[node.key] || null,
                },
            };
        },
        toFlowEdge(edge) {
            const handle = edge.sourceHandle || 'main';
            return {
                id: edge.id || `e_${edge.source}_${handle}_${edge.target}`,
                source: edge.source,
                target: edge.target,
                sourceHandle: handle,
                targetHandle: 'in',
                type: 'smoothstep',
                markerEnd: MarkerType.ArrowClosed,
                style:
                    handle === 'false' ? { strokeDasharray: '5 4' } : undefined,
            };
        },
        handlesFor(meta, config) {
            if (meta.dynamic_handles && meta.type === 'logic.switch') {
                const count = Array.isArray(config.cases)
                    ? config.cases.length
                    : 0;
                return [
                    ...Array.from({ length: count }, (_, i) => `case_${i}`),
                    'default',
                ];
            }
            return meta.output_handles || ['main'];
        },
        decorateNodes() {
            for (const node of this.nodes) {
                node.data.issues = (this.issuesByNode[node.id] || []).length;
                node.data.editable = this.canEdit;
                node.data.run = this.runByNode[node.id] || null;
                const handles = this.handlesFor(
                    node.data.meta,
                    node.data.config,
                );
                if (
                    JSON.stringify(handles) !==
                    JSON.stringify(node.data.handles)
                ) {
                    node.data.handles = handles;
                    // Drop edges whose source handle no longer exists (e.g. a removed switch case).
                    this.edges = this.edges.filter(
                        (e) =>
                            e.source !== node.id ||
                            handles.includes(e.sourceHandle || 'main'),
                    );
                }
            }
            const running = this.isRunning;
            for (const edge of this.edges) {
                const source = this.runByNode[edge.source];
                const target = this.runByNode[edge.target];
                edge.animated = Boolean(
                    running &&
                    source?.status === 'success' &&
                    (!target || target.status === 'running'),
                );
                edge.class =
                    source?.status === 'success' &&
                    target &&
                    target.status !== 'skipped'
                        ? 'ff-edge-ran'
                        : '';
            }
        },
        // ------------------------------------------------------------------
        // Canvas interactions
        // ------------------------------------------------------------------
        onInit(instance) {
            this.flow = instance;
        },
        uniqueKey(type) {
            const meta = this.typeMap[type];
            const base =
                (meta?.label || type)
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '')
                    .replace(/^(\d)/, 'n_$1')
                    .slice(0, 40) || 'node';
            let key = base;
            let i = 2;
            while (this.nodes.some((n) => n.id === key)) {
                key = `${base}_${i++}`;
            }
            return key;
        },
        addNode(type, position = null) {
            if (!this.canEdit) {
                return;
            }
            const meta = this.typeMap[type];
            if (!meta) {
                return;
            }
            if (meta.category === 'trigger' && this.hasTrigger) {
                this.$toast.warning(
                    'A workflow can only have one trigger node.',
                );
                return;
            }
            if (!position) {
                const rightmost = this.nodes.reduce(
                    (max, n) => (n.position.x > max.x ? n.position : max),
                    { x: -200, y: 120 },
                );
                position = { x: rightmost.x + 280, y: rightmost.y };
            }
            const node = this.toFlowNode({
                key: this.uniqueKey(type),
                type,
                name: meta.label,
                position,
                config: {},
            });
            this.nodes = [...this.nodes, node];
            this.selectedKey = node.id;
            if (window.innerWidth < 1024) {
                this.libraryOpen = false;
            }
        },
        onDragOver(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        },
        onDrop(event) {
            const type = event.dataTransfer.getData(
                'application/flowforge-node',
            );
            if (!type || !this.flow) {
                return;
            }
            const position = this.flow.screenToFlowCoordinate({
                x: event.clientX - 110,
                y: event.clientY - 30,
            });
            this.addNode(type, position);
        },
        isValidConnection(connection) {
            const { source, target } = connection;
            if (!this.canEdit || source === target) {
                return false;
            }
            const targetNode = this.nodes.find((n) => n.id === target);
            if (!targetNode?.data.meta.accepts_input) {
                return false;
            }
            const handle = connection.sourceHandle || 'main';
            if (
                this.edges.some(
                    (e) =>
                        e.source === source &&
                        e.target === target &&
                        (e.sourceHandle || 'main') === handle,
                )
            ) {
                return false;
            }
            // Reject connections that would create a cycle (target must not reach source).
            const stack = [target];
            const seen = new Set();
            while (stack.length) {
                const current = stack.pop();
                if (current === source) {
                    return false;
                }
                if (!seen.has(current)) {
                    seen.add(current);
                    this.edges
                        .filter((e) => e.source === current)
                        .forEach((e) => stack.push(e.target));
                }
            }
            return true;
        },
        onConnect(connection) {
            if (!this.isValidConnection(connection)) {
                this.$toast.warning(
                    'That connection is not allowed (cycle, duplicate or trigger input).',
                );
                return;
            }
            this.edges = [
                ...this.edges,
                this.toFlowEdge({
                    source: connection.source,
                    target: connection.target,
                    sourceHandle: connection.sourceHandle || 'main',
                }),
            ];
        },
        onNodeClick({ node }) {
            this.selectedKey = node.id;
        },
        onPaneClick() {
            this.selectedKey = null;
        },
        deleteSelected() {
            if (!this.canEdit || !this.selectedKey) {
                return;
            }
            const key = this.selectedKey;
            this.nodes = this.nodes.filter((n) => n.id !== key);
            this.edges = this.edges.filter(
                (e) => e.source !== key && e.target !== key,
            );
            this.selectedKey = null;
        },
        duplicateSelected() {
            const node = this.selectedNode;
            if (
                !node ||
                !this.canEdit ||
                node.data.meta.category === 'trigger'
            ) {
                return;
            }
            const copy = this.toFlowNode({
                key: this.uniqueKey(node.data.type),
                type: node.data.type,
                name: `${node.data.name} (copy)`,
                position: { x: node.position.x + 40, y: node.position.y + 120 },
                config: clone(node.data.config),
                settings: clone(node.data.settings),
            });
            this.nodes = [...this.nodes, copy];
            this.selectedKey = copy.id;
        },
        renameKey({ from, to, revert }) {
            if (!/^[a-z][a-z0-9_]{0,59}$/.test(to)) {
                this.$toast.error(
                    'Keys must start with a letter and use lowercase letters, numbers or underscores.',
                );
                revert();
                return;
            }
            if (this.nodes.some((n) => n.id === to)) {
                this.$toast.error(`Key "${to}" is already used.`);
                revert();
                return;
            }
            const definition = clone(this.definition);
            definition.nodes.forEach(
                (n) => (n.key = n.key === from ? to : n.key),
            );
            definition.edges.forEach((e) => {
                e.source = e.source === from ? to : e.source;
                e.target = e.target === from ? to : e.target;
                e.id = `e_${e.source}_${e.sourceHandle}_${e.target}`;
            });
            this.setDefinition(definition);
            this.selectedKey = to;
            this.$toast.info(
                'Key renamed',
                'Update any expressions that reference the old key.',
            );
        },
        onNodeConfigChange() {
            const node = this.selectedNode;
            if (node) {
                node.data.handles = this.handlesFor(
                    node.data.meta,
                    node.data.config,
                );
            }
            this.decorateNodes();
        },
        referencesFor(key) {
            const refs = [{ path: 'trigger', label: 'Trigger payload' }];
            const parents = new Set();
            const stack = [key];
            let inLoop = false;
            while (stack.length) {
                const current = stack.pop();
                for (const edge of this.edges.filter(
                    (e) => e.target === current,
                )) {
                    if (edge.sourceHandle === 'each') {
                        inLoop = true;
                    }
                    if (!parents.has(edge.source)) {
                        parents.add(edge.source);
                        stack.push(edge.source);
                    }
                }
            }
            if (inLoop) {
                refs.push(
                    { path: 'item', label: 'Current loop item' },
                    { path: 'index', label: 'Current loop index' },
                );
            }
            for (const node of this.nodes) {
                if (
                    parents.has(node.id) &&
                    node.data.meta.category !== 'trigger'
                ) {
                    refs.push({
                        path: `nodes.${node.id}.output`,
                        label: `Output of “${node.data.name}”`,
                    });
                }
                if (node.data.type === 'logic.set_variables') {
                    Object.keys(node.data.config.variables || {}).forEach((v) =>
                        refs.push({
                            path: `variables.${v}`,
                            label: `Variable from “${node.data.name}”`,
                        }),
                    );
                }
            }
            refs.push(
                { path: 'execution.id', label: 'Execution ID' },
                { path: 'workflow.name', label: 'Workflow name' },
                { path: 'now', label: 'Current timestamp' },
            );
            return refs;
        },
        // ------------------------------------------------------------------
        // Undo / redo
        // ------------------------------------------------------------------
        pushHistory() {
            const last = this.history[this.history.length - 1];
            if (last === this.snapshot) {
                return;
            }
            this.history.push(this.snapshot);
            if (this.history.length > HISTORY_LIMIT) {
                this.history.shift();
            }
            this.future = [];
        },
        restore(snapshot) {
            this.restoring = true;
            const { d, n, s } = JSON.parse(snapshot);
            this.setDefinition(d);
            this.name = n;
            this.description = s;
            this.$nextTick(() => (this.restoring = false));
        },
        undo() {
            if (this.history.length < 2) {
                return;
            }
            this.future.push(this.history.pop());
            this.restore(this.history[this.history.length - 1]);
        },
        redo() {
            if (!this.future.length) {
                return;
            }
            const next = this.future.pop();
            this.history.push(next);
            this.restore(next);
        },
        onKeydown(event) {
            const target = event.target;
            const typing =
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.tagName === 'SELECT' ||
                    target.isContentEditable);
            const mod = event.metaKey || event.ctrlKey;
            if (mod && event.key.toLowerCase() === 's') {
                event.preventDefault();
                this.save();
            } else if (!typing && mod && event.key.toLowerCase() === 'z') {
                event.preventDefault();
                if (event.shiftKey) {
                    this.redo();
                } else {
                    this.undo();
                }
            } else if (!typing && mod && event.key.toLowerCase() === 'y') {
                event.preventDefault();
                this.redo();
            } else if (!typing && mod && event.key.toLowerCase() === 'd') {
                event.preventDefault();
                this.duplicateSelected();
            } else if (
                !typing &&
                (event.key === 'Delete' || event.key === 'Backspace') &&
                this.selectedKey
            ) {
                event.preventDefault();
                this.deleteSelected();
            }
        },
        onBeforeUnload(event) {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        },
        // ------------------------------------------------------------------
        // Validation, save, activation
        // ------------------------------------------------------------------
        async validate() {
            if (!this.workflow) {
                return;
            }
            this.validating = true;
            try {
                const { data } = await this.$api.post(
                    `/workflows/${this.workflow.id}/validate`,
                    { definition: this.definition },
                );
                this.validation = data;
            } catch {
                // Validation is advisory here; save/activate validate again on the server.
            } finally {
                this.validating = false;
            }
        },
        async save({ silent = false } = {}) {
            if (!this.canEdit || this.saving) {
                return false;
            }
            if (!this.name.trim()) {
                this.$toast.error('Give the workflow a name before saving.');
                return false;
            }
            this.saving = true;
            try {
                const { data } = await this.$api.put(
                    `/workflows/${this.workflow.id}`,
                    {
                        name: this.name.trim(),
                        description: this.description,
                        definition: this.definition,
                    },
                );
                this.workflow = data.data;
                this.validation = data.validation;
                this.savedSnapshot = this.snapshot;
                if (!silent) {
                    this.$toast.success(
                        'Workflow saved',
                        `Version ${data.data.version}${data.validation.valid ? '' : ' · has validation issues'}`,
                    );
                }
                return true;
            } catch (error) {
                if (error?.response?.data?.validation) {
                    this.validation = error.response.data.validation;
                    this.issuesOpen = true;
                }
                this.$toast.error(errorMessage(error, 'Save failed.'));
                return false;
            } finally {
                this.saving = false;
            }
        },
        async saveAsDraft() {
            if (this.workflow.status === 'active') {
                const ok = await this.$confirm({
                    title: 'Save as draft?',
                    message:
                        'The workflow will be deactivated (it stops responding to triggers) and your changes saved as a draft.',
                    confirmText: 'Deactivate & save',
                });
                if (!ok) {
                    return;
                }
                try {
                    this.workflow = (
                        await this.$api.post(
                            `/workflows/${this.workflow.id}/deactivate`,
                        )
                    ).data.data;
                } catch (error) {
                    this.$toast.error(errorMessage(error));
                    return;
                }
            }
            await this.save();
        },
        async toggleActive() {
            const activate = this.workflow.status !== 'active';
            if (activate) {
                if (this.dirty && !(await this.save({ silent: true }))) {
                    return;
                }
                await this.validate();
                if (!this.validation.valid) {
                    this.issuesOpen = true;
                    this.$toast.error(
                        'Fix the validation issues before activating.',
                    );
                    return;
                }
                const ok = await this.$confirm({
                    title: 'Activate workflow?',
                    message: `Once active, "${this.name}" runs automatically on its ${this.workflow.trigger_type?.replace('_', ' ') || ''} trigger.`,
                    confirmText: 'Activate',
                });
                if (!ok) {
                    return;
                }
            }
            this.toggling = true;
            try {
                const { data } = await this.$api.post(
                    `/workflows/${this.workflow.id}/${activate ? 'activate' : 'deactivate'}`,
                );
                this.workflow = { ...this.workflow, ...data.data };
                if (activate) {
                    // Reload to receive webhook credentials, if any.
                    this.workflow = (
                        await this.$api.get(`/workflows/${this.workflow.id}`)
                    ).data.data;
                }
                this.$toast.success(
                    activate ? 'Workflow activated' : 'Workflow deactivated',
                );
            } catch (error) {
                if (error?.response?.data?.validation) {
                    this.validation = error.response.data.validation;
                    this.issuesOpen = true;
                }
                this.$toast.error(errorMessage(error));
            } finally {
                this.toggling = false;
            }
        },
        async rotateWebhook() {
            const ok = await this.$confirm({
                title: 'Rotate webhook credentials?',
                message:
                    'The current URL and secret stop working immediately. Update every sender.',
                confirmText: 'Rotate',
                danger: true,
            });
            if (!ok) {
                return;
            }
            try {
                await this.$api.post(
                    `/workflows/${this.workflow.id}/webhook/rotate`,
                );
                this.workflow = (
                    await this.$api.get(`/workflows/${this.workflow.id}`)
                ).data.data;
                this.$toast.success('Webhook credentials rotated');
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        // ------------------------------------------------------------------
        // Running
        // ------------------------------------------------------------------
        openRun() {
            const trigger = this.nodes.find(
                (n) => n.data.meta.category === 'trigger',
            );
            const sample = trigger?.data.config.sample_payload;
            // PHP serializes an empty sample payload as [] - treat anything but a plain object as {}.
            const payload =
                sample && typeof sample === 'object' && !Array.isArray(sample)
                    ? sample
                    : {};
            this.runPayload = JSON.stringify(payload, null, 2);
            this.runPayloadError = null;
            this.runOpen = true;
        },
        async startRun() {
            let payload;
            try {
                payload = JSON.parse(this.runPayload || '{}');
                if (typeof payload !== 'object' || Array.isArray(payload)) {
                    throw new Error('Payload must be a JSON object.');
                }
            } catch (e) {
                this.runPayloadError = e.message;
                return;
            }
            if (this.dirty) {
                if (!this.canEdit) {
                    this.$toast.warning(
                        'Runs use the saved version of the workflow.',
                    );
                } else if (!(await this.save({ silent: true }))) {
                    return;
                }
            }
            this.runOpen = false;
            await this.execute({ payload, test: true });
        },
        async runFromNode() {
            if (this.dirty && !(await this.save({ silent: true }))) {
                return;
            }
            await this.execute({ start_node_key: this.selectedKey });
        },
        async execute(body) {
            try {
                const { data } = await this.$api.post(
                    `/workflows/${this.workflow.id}/execute`,
                    body,
                );
                window.dispatchEvent(new Event('ff:execution-started'));
                this.execution = data.data;
                this.runByNode = {};
                this.queuedSince = Date.now();
                this.detailsOpen = true;
                this.decorateNodes();
                this.$toast.info(
                    `Execution #${data.data.id} started`,
                    body.start_node_key
                        ? `From node "${body.start_node_key}"`
                        : 'Test run — uses the saved version.',
                );
                this.poll();
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not start the run.'),
                );
            }
        },
        async loadLatestExecution() {
            try {
                const { data } = await this.$api.get(
                    `/workflows/${this.workflow.id}/executions`,
                    { params: { per_page: 1 } },
                );
                if (data.data[0]) {
                    this.execution = data.data[0];
                    await this.fetchExecution();
                    if (this.isRunning) {
                        this.poll();
                    }
                }
            } catch {
                // Optional context; the editor works without it.
            }
        },
        async fetchExecution() {
            const { data } = await this.$api.get(
                `/executions/${this.execution.id}`,
            );
            this.execution = {
                ...data.data,
                steps: data.steps,
                steps_total: data.steps_total,
                logs: data.logs,
            };
            const byNode = {};
            for (const step of data.steps) {
                const previous = byNode[step.node_key];
                byNode[step.node_key] = {
                    status: step.status,
                    duration_ms: step.duration_ms,
                    output: step.output,
                    input: step.input,
                    error: step.error,
                    count: (previous?.count || 0) + 1,
                    failed:
                        (previous?.failed || 0) +
                        (step.status === 'failed' ? 1 : 0),
                };
                if (previous?.failed && step.status !== 'failed') {
                    byNode[step.node_key].error = previous.error;
                }
            }
            this.runByNode = byNode;
            this.decorateNodes();
        },
        watchExecution(id) {
            if (this.watchedExecutionId === id) {
                return;
            }
            this.unsubscribeExecution?.();
            this.watchedExecutionId = id;
            // WebSocket push (when Reverb is configured) triggers an immediate refresh.
            this.unsubscribeExecution = onExecutionUpdated(
                `executions.${id}`,
                () => this.poll(),
            );
        },
        async poll() {
            clearTimeout(this.pollTimer);
            if (this.execution) {
                this.watchExecution(this.execution.id);
            }
            try {
                await this.fetchExecution();
            } catch {
                // keep polling; transient errors are fine
            }
            if (this.isRunning) {
                // Polling stays as the fallback; slower when push updates are connected.
                this.pollTimer = setTimeout(
                    this.poll,
                    realtime.connected ? 5000 : 1000,
                );
            } else if (this.execution) {
                const status = this.execution.status;
                const message = {
                    success: 'Run succeeded',
                    partial: 'Run finished with failures',
                    failed: 'Run failed',
                    cancelled: 'Run cancelled',
                }[status];
                (status === 'success'
                    ? this.$toast.success
                    : status === 'partial'
                      ? this.$toast.warning
                      : this.$toast.error)(
                    message,
                    this.execution.error_message ||
                        formatDuration(this.execution.duration_ms),
                );
                window.dispatchEvent(new Event('ff:execution-started'));
            }
        },
        async cancelRun() {
            try {
                await this.$api.post(`/executions/${this.execution.id}/cancel`);
                this.$toast.info('Cancellation requested');
                this.poll();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        // ------------------------------------------------------------------
        // Settings & versions
        // ------------------------------------------------------------------
        openSettings() {
            this.settingsDraft = clone(this.workflow.settings);
            this.settingsOpen = true;
        },
        async onSideEffectsToggle(value) {
            if (value) {
                const ok = await this.$confirm({
                    title: 'Allow real external side effects?',
                    message:
                        'When demo mode is off, production runs of this workflow may send real emails and outbound HTTP requests. Test runs are never affected.',
                    confirmText: 'Allow',
                    danger: true,
                });
                if (!ok) {
                    return;
                }
            }
            this.settingsDraft.allow_external_side_effects = value;
        },
        async saveSettings() {
            try {
                const { data } = await this.$api.put(
                    `/workflows/${this.workflow.id}`,
                    { settings: this.settingsDraft },
                );
                this.workflow = data.data;
                this.settingsOpen = false;
                this.$toast.success('Workflow settings saved');
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        async openVersions() {
            this.versionsOpen = true;
            try {
                this.versions = (
                    await this.$api.get(
                        `/workflows/${this.workflow.id}/versions`,
                    )
                ).data.data;
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        async restoreVersion(version) {
            const ok = await this.$confirm({
                title: `Restore version ${version.version}?`,
                message:
                    'The current definition stays in history as its own version.',
                confirmText: 'Restore',
            });
            if (!ok) {
                return;
            }
            try {
                const { data } = await this.$api.post(
                    `/workflows/${this.workflow.id}/versions/${version.id}/restore`,
                );
                this.applyWorkflow(data.data);
                this.history = [this.snapshot];
                this.future = [];
                this.versionsOpen = false;
                this.$toast.success(data.message);
                this.validate();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
        focusNode(key) {
            this.selectedKey = key;
            this.issuesOpen = false;
            const node = this.nodes.find((n) => n.id === key);
            if (node && this.flow) {
                this.flow.setCenter(
                    node.position.x + 116,
                    node.position.y + 40,
                    { zoom: 1, duration: 300 },
                );
            }
        },
        categoryColor(category) {
            return CATEGORY_META[category]?.color || '#7e8494';
        },
    },
};
</script>

<template>
    <Head :title="name || 'Workflow editor'" />

    <div class="flex h-[calc(100vh-4rem)] flex-col">
        <!-- Toolbar -->
        <div
            class="flex flex-wrap items-center gap-2 border-b border-border bg-card px-3 py-2 sm:px-4"
        >
            <Link
                href="/workflows"
                class="ff-icon-btn"
                aria-label="Back to workflows"
                ><AppIcon name="arrow-left" :size="17"
            /></Link>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <input
                        v-model="name"
                        class="min-w-0 flex-1 truncate rounded-md bg-transparent px-1 text-base font-bold outline-none hover:bg-muted focus:bg-muted sm:max-w-md"
                        :disabled="!canEdit"
                        aria-label="Workflow name"
                        maxlength="120"
                    />
                    <StatusChip
                        v-if="workflow"
                        :status="statusLabel"
                        size="sm"
                    />
                    <span
                        v-if="dirty"
                        class="ff-chip bg-wait/15 px-2 py-px text-[11px] text-wait"
                        title="Unsaved changes"
                    >
                        <span class="size-1.5 rounded-full bg-wait" /> Unsaved
                    </span>
                    <span
                        v-else-if="workflow && !loading"
                        class="hidden text-[11px] text-muted-foreground sm:inline"
                        >v{{ workflow.version }} · saved</span
                    >
                </div>
                <input
                    v-model="description"
                    class="mt-0.5 hidden w-full truncate rounded-md bg-transparent px-1 text-xs text-muted-foreground outline-none hover:bg-muted focus:bg-muted sm:block sm:max-w-xl"
                    placeholder="Add a description…"
                    :disabled="!canEdit"
                    aria-label="Workflow description"
                    maxlength="2000"
                />
            </div>

            <div class="flex flex-wrap items-center gap-1">
                <button
                    class="ff-icon-btn"
                    :disabled="!canEdit || history.length < 2"
                    title="Undo (Ctrl/⌘+Z)"
                    @click="undo"
                >
                    <AppIcon name="undo-2" :size="16" />
                </button>
                <button
                    class="ff-icon-btn"
                    :disabled="!canEdit || !future.length"
                    title="Redo (Ctrl/⌘+Shift+Z)"
                    @click="redo"
                >
                    <AppIcon name="redo-2" :size="16" />
                </button>
                <button
                    class="ff-btn-ghost ff-btn-sm"
                    :class="validation.valid ? 'text-ok' : 'text-bad'"
                    :title="
                        validation.valid
                            ? 'Valid workflow'
                            : 'Show validation issues'
                    "
                    @click="issuesOpen = !issuesOpen"
                >
                    <AppIcon
                        :name="
                            validating
                                ? 'loader-circle'
                                : validation.valid
                                  ? 'badge-check'
                                  : 'triangle-alert'
                        "
                        :size="15"
                        :class="{ 'animate-spin': validating }"
                    />
                    <span class="hidden md:inline">{{
                        validation.valid
                            ? 'Valid'
                            : `${validation.errors.length} issue${validation.errors.length === 1 ? '' : 's'}`
                    }}</span>
                </button>
                <button
                    class="ff-icon-btn"
                    title="Version history"
                    @click="openVersions"
                >
                    <AppIcon name="history" :size="16" />
                </button>
                <button
                    class="ff-icon-btn"
                    title="Workflow settings"
                    @click="openSettings"
                >
                    <AppIcon name="sliders-horizontal" :size="16" />
                </button>
                <div
                    v-if="workflow?.can.activate"
                    class="mx-1 flex items-center gap-2 rounded-lg border border-border px-2.5 py-1.5"
                    title="Active workflows respond to their trigger automatically"
                >
                    <ToggleSwitch
                        :model-value="workflow.status === 'active'"
                        :disabled="toggling"
                        label="Active"
                        @update:model-value="toggleActive"
                    />
                    <span class="text-xs font-semibold">Active</span>
                </div>
                <button
                    v-if="canEdit && workflow?.status === 'active'"
                    class="ff-btn-secondary hidden sm:inline-flex"
                    :disabled="saving"
                    @click="saveAsDraft"
                >
                    Save as draft
                </button>
                <button
                    v-if="canEdit"
                    class="ff-btn-secondary"
                    :disabled="saving || !dirty"
                    title="Save (Ctrl/⌘+S)"
                    @click="save()"
                >
                    <AppIcon
                        :name="saving ? 'loader-circle' : 'save'"
                        :size="15"
                        :class="{ 'animate-spin': saving }"
                    />
                    Save
                </button>
                <button
                    v-if="isRunning && execution?.can?.cancel"
                    class="ff-btn-danger"
                    @click="cancelRun"
                >
                    <AppIcon name="ban" :size="15" /> Cancel run
                </button>
                <button
                    v-else-if="canRun"
                    class="ff-btn-primary"
                    :disabled="loading || !hasTrigger"
                    @click="openRun"
                >
                    <AppIcon name="play" :size="15" /> Test run
                </button>
            </div>
        </div>

        <div
            v-if="!loading && !canEdit"
            class="flex items-center gap-2 border-b border-border bg-run/10 px-4 py-1.5 text-xs text-foreground/80"
        >
            <AppIcon name="eye" :size="13" /> Read-only — your role can view
            this workflow but not change it.
        </div>

        <div class="relative flex min-h-0 flex-1">
            <!-- Node library -->
            <aside
                v-if="canEdit"
                class="absolute inset-y-0 left-0 z-20 w-64 border-r border-border bg-card shadow-xl transition-transform duration-200 lg:static lg:shadow-none"
                :class="
                    libraryOpen
                        ? 'translate-x-0'
                        : '-translate-x-full lg:hidden'
                "
            >
                <NodeLibrary
                    :node-types="nodeTypes"
                    :has-trigger="hasTrigger"
                    :disabled="!canEdit"
                    @add="addNode"
                />
            </aside>

            <!-- Canvas -->
            <div
                class="relative min-w-0 flex-1"
                @drop="onDrop"
                @dragover="onDragOver"
            >
                <div
                    v-if="loading"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-background/60"
                >
                    <AppIcon
                        name="loader-circle"
                        :size="28"
                        class="animate-spin text-brand-500"
                    />
                </div>
                <VueFlow
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    class="ff-canvas"
                    :default-edge-options="defaultEdgeOptions"
                    :nodes-draggable="canEdit"
                    :nodes-connectable="canEdit"
                    :edges-updatable="false"
                    :delete-key-code="null"
                    :snap-to-grid="true"
                    :snap-grid="[10, 10]"
                    :min-zoom="0.2"
                    :max-zoom="2"
                    :connect-on-click="false"
                    @init="onInit"
                    @connect="onConnect"
                    @node-click="onNodeClick"
                    @pane-click="onPaneClick"
                >
                    <template #node-workflow="nodeProps">
                        <FlowNode v-bind="nodeProps" />
                    </template>
                    <Background :gap="22" :size="1.4" pattern-color="#c3c6d4" />
                    <Controls position="bottom-left" />
                    <MiniMap
                        class="hidden md:block"
                        position="bottom-right"
                        pannable
                        zoomable
                        :node-color="
                            (n) => categoryColor(n.data?.meta?.category)
                        "
                        :node-border-radius="8"
                        mask-color="rgba(97, 97, 255, 0.08)"
                    />
                </VueFlow>

                <div class="absolute top-3 left-3 z-10 flex gap-2">
                    <button
                        v-if="canEdit"
                        class="ff-btn-secondary ff-btn-sm shadow-sm"
                        @click="libraryOpen = !libraryOpen"
                    >
                        <AppIcon
                            :name="libraryOpen ? 'panel-left' : 'plus'"
                            :size="14"
                        />
                        {{ libraryOpen ? 'Hide nodes' : 'Add node' }}
                    </button>
                </div>

                <div
                    v-if="!loading && !nodes.length"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center p-6"
                >
                    <div
                        class="pointer-events-auto max-w-sm ff-card p-6 text-center"
                    >
                        <AppIcon
                            name="mouse-pointer-click"
                            :size="26"
                            class="mx-auto text-brand-500"
                        />
                        <h3 class="mt-2 font-bold">Start with a trigger</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Drag a trigger from the node library, then connect
                            actions to it.
                        </p>
                    </div>
                </div>

                <!-- Validation issues popover -->
                <Transition name="ff-fade">
                    <div
                        v-if="issuesOpen"
                        class="absolute top-3 right-3 z-20 w-80 max-w-[calc(100%-1.5rem)] ff-card p-3 shadow-xl"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-bold">Validation</span>
                            <button
                                class="ff-icon-btn size-7"
                                aria-label="Close"
                                @click="issuesOpen = false"
                            >
                                <AppIcon name="x" :size="14" />
                            </button>
                        </div>
                        <p
                            v-if="validation.valid"
                            class="flex items-center gap-2 text-sm text-ok"
                        >
                            <AppIcon name="badge-check" :size="15" /> No issues
                            — ready to activate.
                        </p>
                        <ul v-else class="max-h-72 space-y-1 overflow-y-auto">
                            <li
                                v-for="(error, i) in validation.errors"
                                :key="i"
                            >
                                <button
                                    class="flex w-full cursor-pointer gap-2 rounded-md p-1.5 text-left text-xs hover:bg-muted"
                                    :disabled="!error.node"
                                    @click="error.node && focusNode(error.node)"
                                >
                                    <AppIcon
                                        name="triangle-alert"
                                        :size="13"
                                        class="mt-px shrink-0 text-bad"
                                    />
                                    <span>
                                        <code
                                            v-if="error.node"
                                            class="font-mono font-semibold"
                                            >{{ error.node }}</code
                                        >
                                        {{ error.message }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <ul
                            v-if="validation.warnings?.length"
                            class="mt-2 space-y-1 border-t border-border pt-2"
                        >
                            <li
                                v-for="(w, i) in validation.warnings"
                                :key="i"
                                class="flex gap-2 text-xs text-wait"
                            >
                                <AppIcon
                                    name="info"
                                    :size="13"
                                    class="mt-px shrink-0"
                                />
                                {{ w.node ? w.node + ': ' : '' }}{{ w.message }}
                            </li>
                        </ul>
                    </div>
                </Transition>

                <!-- Execution drawer -->
                <div
                    v-if="execution"
                    class="absolute right-3 bottom-3 left-3 z-10 md:right-auto md:left-16 md:w-[460px]"
                >
                    <div class="overflow-hidden ff-card shadow-xl">
                        <button
                            class="flex w-full cursor-pointer items-center gap-3 px-3 py-2.5 text-left"
                            @click="detailsOpen = !detailsOpen"
                        >
                            <StatusChip :status="execution.status" size="sm" />
                            <span
                                class="min-w-0 flex-1 truncate text-sm font-semibold"
                            >
                                Execution #{{ execution.id }}
                                <span class="font-normal text-muted-foreground"
                                    >· {{ execution.mode }} ·
                                    {{ timeAgo(execution.created_at) }}</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">{{
                                formatDuration(execution.duration_ms)
                            }}</span>
                            <AppIcon
                                :name="
                                    detailsOpen ? 'chevron-down' : 'chevron-up'
                                "
                                :size="15"
                            />
                        </button>
                        <div
                            v-if="detailsOpen"
                            class="max-h-64 space-y-2 overflow-y-auto border-t border-border p-3 text-xs"
                        >
                            <p
                                v-if="
                                    execution.status === 'queued' &&
                                    queuedSince &&
                                    Date.now() - queuedSince > 4000
                                "
                                class="rounded-lg bg-wait/10 p-2 text-wait"
                            >
                                Still queued — make sure a queue worker is
                                running: <code>php artisan queue:work</code>
                            </p>
                            <p
                                v-if="execution.error_message"
                                class="rounded-lg bg-bad/10 p-2 text-bad"
                            >
                                {{ execution.error_message }}
                            </p>
                            <div
                                v-for="step in (execution.steps || []).slice(
                                    -40,
                                )"
                                :key="step.id"
                                class="flex items-center gap-2"
                            >
                                <StatusChip
                                    :status="step.status"
                                    size="sm"
                                    :label="' '"
                                />
                                <button
                                    class="min-w-0 flex-1 truncate text-left font-mono hover:text-brand-500"
                                    @click="focusNode(step.node_key)"
                                >
                                    {{ step.node_key
                                    }}<span
                                        v-if="step.iteration !== null"
                                        class="text-muted-foreground"
                                    >
                                        [{{ step.iteration }}]</span
                                    >
                                </button>
                                <span class="text-muted-foreground">{{
                                    formatDuration(step.duration_ms)
                                }}</span>
                            </div>
                            <p
                                v-if="execution.steps_total > 40"
                                class="text-muted-foreground"
                            >
                                Showing last 40 of
                                {{ execution.steps_total }} steps.
                            </p>
                            <Link
                                :href="`/executions/${execution.id}`"
                                class="inline-flex items-center gap-1 font-semibold text-brand-500 hover:underline"
                            >
                                Open full execution details
                                <AppIcon name="external-link" :size="12" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Config panel -->
            <Transition name="ff-slide">
                <aside
                    v-if="selectedNode"
                    class="absolute inset-0 z-30 border-l border-border sm:inset-y-0 sm:right-0 sm:left-auto sm:w-[380px] lg:static lg:w-[380px] lg:shrink-0"
                >
                    <NodeConfigPanel
                        :node="selectedNode"
                        :readonly="!canEdit"
                        :issues="issuesByNode[selectedNode.id] || []"
                        :references="references"
                        :data-models="dataModels"
                        :filter-operators="filterOperators"
                        :run="runByNode[selectedNode.id] || null"
                        :webhook="workflow?.webhook || null"
                        :workflow-status="workflow?.status"
                        :can-run-from-here="canRunFromHere"
                        @close="selectedKey = null"
                        @change="onNodeConfigChange"
                        @rename-key="renameKey"
                        @delete="deleteSelected"
                        @duplicate="duplicateSelected"
                        @run-from="runFromNode"
                        @rotate-webhook="rotateWebhook"
                    />
                </aside>
            </Transition>
        </div>
    </div>

    <!-- Run dialog -->
    <ModalDialog
        :open="runOpen"
        title="Test run"
        description="Runs the saved workflow on the queue. Test runs never send real emails or outbound requests."
        @close="runOpen = false"
    >
        <label class="ff-label" for="run-payload">Trigger payload (JSON)</label>
        <textarea
            id="run-payload"
            v-model="runPayload"
            class="ff-textarea font-mono text-xs"
            rows="10"
            spellcheck="false"
        />
        <p v-if="runPayloadError" class="mt-1 text-xs text-bad">
            {{ runPayloadError }}
        </p>
        <p class="mt-2 text-xs text-muted-foreground">
            Pre-filled from the trigger node's sample payload.
        </p>
        <p
            v-if="dirty"
            class="mt-2 rounded-lg bg-wait/10 p-2 text-xs text-wait"
        >
            Unsaved changes will be saved before the run starts.
        </p>
        <template #footer>
            <button class="ff-btn-secondary" @click="runOpen = false">
                Cancel
            </button>
            <button class="ff-btn-primary" @click="startRun">
                <AppIcon name="play" :size="15" /> Run now
            </button>
        </template>
    </ModalDialog>

    <!-- Settings dialog -->
    <ModalDialog
        :open="settingsOpen"
        title="Workflow settings"
        description="Execution limits and failure handling."
        @close="settingsOpen = false"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ff-label" for="s-timeout"
                    >Timeout (seconds)</label
                >
                <input
                    id="s-timeout"
                    v-model.number="settingsDraft.timeout_seconds"
                    type="number"
                    min="5"
                    max="3600"
                    class="ff-input"
                    :disabled="!canEdit"
                />
            </div>
            <div>
                <label class="ff-label" for="s-max">Max node executions</label>
                <input
                    id="s-max"
                    v-model.number="settingsDraft.max_node_executions"
                    type="number"
                    min="1"
                    max="100000"
                    class="ff-input"
                    :disabled="!canEdit"
                />
            </div>
            <div>
                <label class="ff-label" for="s-policy">Failure policy</label>
                <select
                    id="s-policy"
                    v-model="settingsDraft.failure_policy"
                    class="ff-input"
                    :disabled="!canEdit"
                >
                    <option value="stop">Stop on first failed node</option>
                    <option value="continue">
                        Continue (skip failed branches)
                    </option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="ff-label" for="s-retries">Retries</label>
                    <input
                        id="s-retries"
                        v-model.number="settingsDraft.default_retries"
                        type="number"
                        min="0"
                        max="5"
                        class="ff-input"
                        :disabled="!canEdit"
                    />
                </div>
                <div>
                    <label class="ff-label" for="s-backoff">Backoff ms</label>
                    <input
                        id="s-backoff"
                        v-model.number="settingsDraft.retry_backoff_ms"
                        type="number"
                        min="0"
                        max="10000"
                        class="ff-input"
                        :disabled="!canEdit"
                    />
                </div>
            </div>
        </div>
        <div
            class="mt-5 flex items-start gap-3 rounded-xl border border-bad/25 bg-bad/5 p-3"
        >
            <ToggleSwitch
                :model-value="
                    Boolean(settingsDraft.allow_external_side_effects)
                "
                :disabled="!canEdit"
                label="Allow external side effects"
                @update:model-value="onSideEffectsToggle"
            />
            <div class="text-sm">
                <div class="font-semibold">
                    Allow real external side effects
                </div>
                <p class="text-xs text-muted-foreground">
                    Real email delivery and outbound HTTP for production runs.
                    Ignored while platform demo mode is on
                    <strong
                        >({{
                            $page.props.platform?.demo_mode
                                ? 'currently ON'
                                : 'currently off'
                        }})</strong
                    >.
                </p>
            </div>
        </div>
        <template #footer>
            <button class="ff-btn-secondary" @click="settingsOpen = false">
                Close
            </button>
            <button v-if="canEdit" class="ff-btn-primary" @click="saveSettings">
                Save settings
            </button>
        </template>
    </ModalDialog>

    <!-- Versions dialog -->
    <ModalDialog
        :open="versionsOpen"
        title="Version history"
        description="A snapshot is stored every time the definition or settings change."
        @close="versionsOpen = false"
    >
        <ul class="divide-y divide-border">
            <li
                v-for="v in versions"
                :key="v.id"
                class="flex items-center gap-3 py-2.5"
            >
                <span
                    class="flex size-8 items-center justify-center rounded-lg bg-brand-500/10 text-xs font-bold text-brand-500"
                    >v{{ v.version }}</span
                >
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold">
                        {{ v.note || 'Edited' }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ v.creator || 'System' }} ·
                        {{ timeAgo(v.created_at) }} · {{ v.node_count }} nodes
                    </div>
                </div>
                <span
                    v-if="v.version === workflow?.version"
                    class="ff-chip bg-ok/15 text-ok"
                    >Current</span
                >
                <button
                    v-else-if="canEdit"
                    class="ff-btn-secondary ff-btn-sm"
                    @click="restoreVersion(v)"
                >
                    Restore
                </button>
            </li>
        </ul>
        <p v-if="!versions.length" class="text-sm text-muted-foreground">
            Loading…
        </p>
    </ModalDialog>
</template>

<style>
.ff-canvas {
    background: var(--background);
}
.dark .ff-canvas .vue-flow__background pattern circle {
    fill: #3a3d5c;
}
.ff-edge-ran .vue-flow__edge-path {
    stroke: #00c875;
    stroke-width: 2.25;
}
</style>
