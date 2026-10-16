<script>
import { api, errorMessage } from '@/lib/api';
import AppIcon from './AppIcon.vue';
import DropdownMenu from './DropdownMenu.vue';

export default {
    name: 'WorkspaceSwitcher',
    components: { AppIcon, DropdownMenu },
    data() {
        return { workspaces: [], currentId: null };
    },
    computed: {
        current() {
            return (
                this.workspaces.find((w) => w.id === this.currentId) ||
                this.$page.props.auth.user?.current_workspace || {
                    name: 'No workspace',
                    color: '#9ca3af',
                }
            );
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        async load() {
            try {
                const { data } = await api.get('/workspaces');
                this.workspaces = data.data;
                this.currentId = data.current_id;
            } catch {
                // Non-critical: the switcher simply shows the current workspace.
            }
        },
        open(toggle) {
            // Refresh on open so newly created or joined workspaces appear.
            this.load();
            toggle();
        },
        async select(workspace) {
            if (workspace.id === this.currentId) {
                return;
            }
            try {
                await api.put('/workspaces/current', {
                    workspace_id: workspace.id,
                });
                this.$toast.success(`Switched to ${workspace.name}`);
                window.location.reload();
            } catch (error) {
                this.$toast.error(errorMessage(error));
            }
        },
    },
};
</script>

<template>
    <DropdownMenu align="left" width="w-60">
        <template #trigger="{ toggle }">
            <button
                type="button"
                class="ff-btn-ghost h-9 max-w-[200px] gap-2 px-2"
                aria-label="Switch workspace"
                @click="open(toggle)"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-md text-[11px] font-extrabold text-white"
                    :style="{ backgroundColor: current.color }"
                    >{{ (current.name || '?').charAt(0) }}</span
                >
                <span
                    class="hidden truncate font-semibold text-foreground md:inline"
                    >{{ current.name }}</span
                >
                <AppIcon name="chevron-down" :size="14" />
            </button>
        </template>
        <div
            class="px-2.5 pt-1 pb-2 text-xs font-semibold text-muted-foreground uppercase"
        >
            Workspaces
        </div>
        <button
            v-for="workspace in workspaces"
            :key="workspace.id"
            type="button"
            data-close
            class="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm hover:bg-muted"
            @click="select(workspace)"
        >
            <span
                class="flex size-6 items-center justify-center rounded-md text-[11px] font-extrabold text-white"
                :style="{ backgroundColor: workspace.color }"
                >{{ workspace.name.charAt(0) }}</span
            >
            <span class="flex-1 truncate">{{ workspace.name }}</span>
            <AppIcon
                v-if="workspace.id === currentId"
                name="check"
                :size="15"
                class="text-ok"
            />
        </button>
        <p
            v-if="!workspaces.length"
            class="px-2.5 py-2 text-sm text-muted-foreground"
        >
            You are not a member of any workspace yet. Ask an admin for access.
        </p>
        <template v-if="$can('workspaces.manage')">
            <div class="my-1 border-t border-border" />
            <button
                type="button"
                data-close
                class="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="$inertia.visit('/settings/workspaces')"
            >
                <AppIcon name="settings" :size="15" /> Manage workspaces
            </button>
        </template>
    </DropdownMenu>
</template>
