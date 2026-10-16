<script>
import { Background } from '@vue-flow/background';
import { MarkerType, VueFlow } from '@vue-flow/core';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import FlowNode from './FlowNode.vue';

/**
 * Read-only diagram of a workflow definition (templates, execution details).
 */
export default {
    name: 'GraphPreview',
    components: { Background, FlowNode, VueFlow },
    props: {
        definition: { type: Object, required: true },
        typeMap: { type: Object, default: () => ({}) },
        runByNode: { type: Object, default: () => ({}) },
        height: { type: String, default: 'h-96' },
    },
    emits: ['node-click'],
    computed: {
        nodes() {
            return (this.definition.nodes || []).map((n) => {
                const meta = this.typeMap[n.type] || {
                    label: n.type,
                    category: 'utility',
                    output_handles: ['main'],
                    accepts_input: true,
                };
                let handles = meta.output_handles || ['main'];
                if (n.type === 'logic.switch') {
                    handles = [
                        ...(n.config?.cases || []).map((_, i) => `case_${i}`),
                        'default',
                    ];
                }
                return {
                    id: n.key,
                    type: 'workflow',
                    position: n.position || { x: 0, y: 0 },
                    draggable: false,
                    data: {
                        type: n.type,
                        name: n.name,
                        config: n.config || {},
                        meta,
                        handles,
                        editable: false,
                        issues: 0,
                        run: this.runByNode[n.key] || null,
                    },
                };
            });
        },
        edges() {
            return (this.definition.edges || []).map((e) => ({
                id: e.id || `${e.source}-${e.target}-${e.sourceHandle}`,
                source: e.source,
                target: e.target,
                sourceHandle: e.sourceHandle || 'main',
                targetHandle: 'in',
                type: 'smoothstep',
                markerEnd: MarkerType.ArrowClosed,
                class:
                    this.runByNode[e.source]?.status === 'success' &&
                    this.runByNode[e.target] &&
                    this.runByNode[e.target].status !== 'skipped'
                        ? 'ff-edge-ran'
                        : '',
            }));
        },
    },
    methods: {
        onInit(instance) {
            setTimeout(
                () => instance.fitView({ padding: 0.15, maxZoom: 1 }),
                30,
            );
        },
    },
};
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-background"
        :class="height"
    >
        <VueFlow
            :nodes="nodes"
            :edges="edges"
            :nodes-draggable="false"
            :nodes-connectable="false"
            :elements-selectable="false"
            :zoom-on-double-click="false"
            :min-zoom="0.15"
            @init="onInit"
            @node-click="$emit('node-click', $event.node.id)"
        >
            <template #node-workflow="nodeProps">
                <FlowNode v-bind="nodeProps" />
            </template>
            <Background :gap="22" :size="1.2" pattern-color="#c3c6d4" />
        </VueFlow>
    </div>
</template>
