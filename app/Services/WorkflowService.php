<?php

namespace App\Services;

use App\Enums\WorkflowStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowSchedule;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Workflow\Engine\GraphValidator;
use App\Workflow\Exceptions\InvalidWorkflowException;
use App\Workflow\NodeRegistry;
use App\Workflow\Triggers\TriggerDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workflow lifecycle: create, update (with version snapshots), activate,
 * deactivate, duplicate, delete and template import.
 */
class WorkflowService
{
    public function __construct(
        private readonly GraphValidator $validator,
        private readonly NodeRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly TriggerDispatcher $triggers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user, ?int $templateId = null): Workflow
    {
        return DB::transaction(function () use ($data, $user, $templateId) {
            $definition = $data['definition'] ?? $this->starterDefinition();
            $this->authorizeDefinition($user, $definition);

            $workflow = Workflow::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'status' => WorkflowStatus::Draft,
                'definition' => $definition,
                'trigger_type' => $this->triggerType($definition),
                'settings' => $this->normalizeSettings($data['settings'] ?? []),
                'version' => 1,
                'template_id' => $templateId,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'workspace_id' => $this->resolveWorkspace($user, $data['workspace_id'] ?? null),
            ]);

            $this->snapshot($workflow, $user, $templateId ? 'Imported from template' : 'Created');
            $this->audit->log('workflow.created', $workflow, ['name' => $workflow->name], $user);

            return $workflow;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Workflow $workflow, array $data, User $user): Workflow
    {
        return DB::transaction(function () use ($workflow, $data, $user) {
            $attributes = Arr::only($data, ['name', 'description', 'category']);
            $definitionChanged = array_key_exists('definition', $data) && $data['definition'] !== $workflow->definition;
            $settingsChanged = array_key_exists('settings', $data) && $this->normalizeSettings($data['settings']) !== $workflow->settings;

            if ($definitionChanged) {
                $this->authorizeDefinition($user, $data['definition']);
                $attributes['definition'] = $data['definition'];
                $attributes['trigger_type'] = $this->triggerType($data['definition']);

                // An active workflow must stay valid; otherwise the save is rejected.
                if ($workflow->isActive()) {
                    $report = $this->validator->validate($data['definition']);
                    if (! $report['valid']) {
                        throw new InvalidWorkflowException('Active workflows can only be saved with a valid definition. Deactivate it to save a draft.', $report['errors']);
                    }
                }
            }

            if ($settingsChanged) {
                $attributes['settings'] = $this->normalizeSettings($data['settings']);
            }

            if ($definitionChanged || $settingsChanged) {
                $attributes['version'] = $workflow->version + 1;
            }

            $attributes['updated_by'] = $user->id;
            $before = $workflow->only(['name', 'version', 'status']);
            $workflow->update($attributes);

            if ($definitionChanged || $settingsChanged) {
                $this->snapshot($workflow, $user, $data['version_note'] ?? null);
            }

            if ($workflow->isActive()) {
                $this->syncTriggers($workflow);
            }

            $this->audit->log('workflow.updated', $workflow, ['before' => $before, 'after' => $workflow->only(['name', 'version', 'status'])], $user);

            return $workflow->refresh();
        });
    }

    public function activate(Workflow $workflow, User $user): Workflow
    {
        $report = $this->validator->validate($workflow->definition);

        if (! $report['valid']) {
            throw new InvalidWorkflowException('Fix the validation errors before activating.', $report['errors']);
        }

        DB::transaction(function () use ($workflow, $user) {
            $workflow->update(['status' => WorkflowStatus::Active, 'activated_at' => now(), 'updated_by' => $user->id]);
            $this->syncTriggers($workflow);
            $this->audit->log('workflow.activated', $workflow, [], $user);
        });

        return $workflow->refresh();
    }

    public function deactivate(Workflow $workflow, User $user): Workflow
    {
        DB::transaction(function () use ($workflow, $user) {
            $workflow->update(['status' => WorkflowStatus::Inactive, 'updated_by' => $user->id]);
            $workflow->schedules()->update(['is_active' => false]);
            $this->audit->log('workflow.deactivated', $workflow, [], $user);
        });

        return $workflow->refresh();
    }

    public function duplicate(Workflow $workflow, User $user): Workflow
    {
        $copy = $this->create([
            'name' => $workflow->name.' (copy)',
            'description' => $workflow->description,
            'category' => $workflow->category,
            'definition' => $workflow->definition,
            'settings' => $workflow->settings,
            'workspace_id' => $workflow->workspace_id,
        ], $user, $workflow->template_id);

        $this->audit->log('workflow.duplicated', $copy, ['source_id' => $workflow->id], $user);

        return $copy;
    }

    public function delete(Workflow $workflow, User $user): void
    {
        DB::transaction(function () use ($workflow, $user) {
            $workflow->schedules()->update(['is_active' => false]);
            $workflow->update(['status' => WorkflowStatus::Inactive]);
            $workflow->delete();
            $this->audit->log('workflow.deleted', $workflow, ['name' => $workflow->name], $user);
        });
    }

    public function restoreVersion(Workflow $workflow, WorkflowVersion $version, User $user): Workflow
    {
        return $this->update($workflow, [
            'definition' => $version->definition,
            'settings' => $version->settings ?? [],
            'version_note' => "Restored from v{$version->version}",
        ], $user);
    }

    public function importTemplate(WorkflowTemplate $template, User $user, ?string $name = null): Workflow
    {
        $workflow = $this->create([
            'name' => $name ?: $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'definition' => $template->definition,
            'settings' => $template->settings ?? [],
        ], $user, $template->id);

        $template->increment('import_count');
        $this->audit->log('template.imported', $workflow, ['template' => $template->key], $user);

        return $workflow;
    }

    /**
     * Keep schedules and webhook credentials in line with the trigger node.
     */
    public function syncTriggers(Workflow $workflow): void
    {
        $trigger = $workflow->triggerNode();
        $type = $trigger['type'] ?? null;

        if (in_array($type, ['trigger.schedule', 'trigger.user_verification_pending'], true) && $workflow->isActive()) {
            $cron = (string) ($trigger['config']['cron'] ?? '0 9 * * *');
            $timezone = (string) ($trigger['config']['timezone'] ?? 'UTC');

            WorkflowSchedule::query()->updateOrCreate(
                ['workflow_id' => $workflow->id, 'node_key' => $trigger['key']],
                [
                    'cron_expression' => $cron,
                    'timezone' => $timezone,
                    'next_run_at' => $this->triggers->nextRun($cron, $timezone),
                    'is_active' => true,
                ],
            );
            $workflow->schedules()->where('node_key', '!=', $trigger['key'])->update(['is_active' => false]);
        } else {
            $workflow->schedules()->update(['is_active' => false]);
        }

        if ($type === 'trigger.webhook') {
            $this->ensureWebhookCredentials($workflow);
        }
    }

    public function ensureWebhookCredentials(Workflow $workflow, bool $rotate = false): Workflow
    {
        if ($rotate || ! $workflow->webhook_token) {
            $workflow->forceFill([
                'webhook_token' => Str::random(40),
                'webhook_secret' => Str::random(48),
            ])->save();
        }

        return $workflow;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function triggerType(array $definition): ?string
    {
        foreach ($definition['nodes'] ?? [] as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($this->registry->isTrigger($type)) {
                return Str::after($type, 'trigger.');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $settings): array
    {
        $defaults = config('workflows.default_settings');

        return [
            'timeout_seconds' => max(5, min(3600, (int) ($settings['timeout_seconds'] ?? $defaults['timeout_seconds']))),
            'max_node_executions' => max(1, min(100000, (int) ($settings['max_node_executions'] ?? $defaults['max_node_executions']))),
            'failure_policy' => in_array($settings['failure_policy'] ?? null, ['stop', 'continue'], true) ? $settings['failure_policy'] : $defaults['failure_policy'],
            'default_retries' => max(0, min(5, (int) ($settings['default_retries'] ?? $defaults['default_retries']))),
            'retry_backoff_ms' => max(0, min(10000, (int) ($settings['retry_backoff_ms'] ?? $defaults['retry_backoff_ms']))),
            'allow_external_side_effects' => (bool) ($settings['allow_external_side_effects'] ?? false),
        ];
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function starterDefinition(): array
    {
        return [
            'nodes' => [[
                'key' => 'manual_trigger',
                'type' => 'trigger.manual',
                'name' => 'Manual Trigger',
                'position' => ['x' => 80, 'y' => 200],
                'config' => ['sample_payload' => []],
            ]],
            'edges' => [],
        ];
    }

    /**
     * A workflow may only do what the person saving it is allowed to do:
     * - data nodes on the cross-workspace `users` directory require users.manage;
     * - subworkflow nodes may only call workflows the editor is allowed to run.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws AuthorizationException
     */
    public function authorizeDefinition(User $user, array $definition): void
    {
        foreach ((array) ($definition['nodes'] ?? []) as $node) {
            $type = (string) ($node['type'] ?? '');
            $config = (array) ($node['config'] ?? []);
            $label = (string) ($node['name'] ?? $node['key'] ?? $type);

            if (str_starts_with($type, 'data.') && ($config['model'] ?? null) === 'users' && ! $user->hasPermission('users.manage')) {
                throw new AuthorizationException("Node \"{$label}\" reads the platform users directory, which requires the \"Manage users and roles\" permission.");
            }

            if ($type === 'utility.subworkflow' && is_numeric($config['workflow_id'] ?? null)) {
                $child = Workflow::query()->find((int) $config['workflow_id']);
                if ($child !== null && ! $user->can('execute', $child)) {
                    throw new AuthorizationException("Node \"{$label}\" calls workflow #{$child->id}, which you are not allowed to run.");
                }
            }
        }
    }

    /**
     * Every workflow belongs to a workspace: the requested one, else the user's current one,
     * else their first membership. A workspace-less workflow would be visible to no one but
     * Super Admins, so creating one is refused.
     */
    private function resolveWorkspace(User $user, mixed $requested): int
    {
        $candidates = array_filter([
            is_numeric($requested) ? (int) $requested : null,
            $user->current_workspace_id,
        ], fn ($id) => $id !== null && $user->belongsToWorkspace($id));

        $workspaceId = reset($candidates) ?: $user->workspaces()->orderBy('workspaces.id')->value('workspaces.id');

        if ($workspaceId === null && $user->isSuperAdmin()) {
            $workspaceId = Workspace::query()->orderBy('id')->value('id');
        }

        if ($workspaceId === null) {
            throw ValidationException::withMessages(['workspace' => 'You are not a member of any workspace. Ask an administrator for access.']);
        }

        return (int) $workspaceId;
    }

    private function snapshot(Workflow $workflow, User $user, ?string $note = null): void
    {
        WorkflowVersion::query()->updateOrCreate(
            ['workflow_id' => $workflow->id, 'version' => $workflow->version],
            ['definition' => $workflow->definition, 'settings' => $workflow->settings, 'note' => $note, 'created_by' => $user->id],
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workflow';
        $slug = $base;
        $i = 2;

        while (Workflow::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
