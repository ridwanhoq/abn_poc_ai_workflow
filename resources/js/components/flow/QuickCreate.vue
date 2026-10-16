<script>
import { router } from '@inertiajs/vue3';
import { api, errorMessage } from '@/lib/api';
import AppIcon from './AppIcon.vue';
import DropdownMenu from './DropdownMenu.vue';
import MenuItem from './MenuItem.vue';

export default {
    name: 'QuickCreate',
    components: { AppIcon, DropdownMenu, MenuItem },
    data() {
        return { creating: false };
    },
    methods: {
        async newWorkflow() {
            this.creating = true;
            try {
                const { data } = await api.post('/workflows', {
                    name: 'Untitled workflow',
                });
                router.visit(`/workflows/${data.data.id}/edit`);
            } catch (error) {
                this.$toast.error(
                    errorMessage(error, 'Could not create the workflow.'),
                );
            } finally {
                this.creating = false;
            }
        },
    },
};
</script>

<template>
    <DropdownMenu width="w-64">
        <template #trigger="{ toggle }">
            <button
                type="button"
                class="ff-btn-primary h-9 px-3"
                :disabled="creating"
                @click="toggle"
            >
                <AppIcon
                    :name="creating ? 'loader-circle' : 'plus'"
                    :size="16"
                    :class="{ 'animate-spin': creating }"
                />
                <span class="hidden sm:inline">Create</span>
            </button>
        </template>
        <MenuItem
            v-if="$can('workflows.create')"
            icon="workflow"
            @select="newWorkflow"
        >
            Blank workflow
        </MenuItem>
        <MenuItem
            v-if="$can('templates.import')"
            icon="layout-template"
            @select="$inertia.visit('/templates')"
        >
            From a template
        </MenuItem>
        <MenuItem
            v-if="$can('workflows.execute')"
            icon="upload"
            @select="$inertia.visit('/data?upload=1')"
        >
            Upload media (file trigger)
        </MenuItem>
        <MenuItem
            v-if="$can('variables.manage')"
            icon="variable"
            @select="$inertia.visit('/data?variable=1')"
        >
            New variable
        </MenuItem>
        <p
            v-if="!$can('workflows.create') && !$can('variables.manage')"
            class="px-2.5 py-2 text-sm text-muted-foreground"
        >
            Your role has read-only access.
        </p>
    </DropdownMenu>
</template>
