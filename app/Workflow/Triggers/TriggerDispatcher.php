<?php

namespace App\Workflow\Triggers;

use App\Enums\WorkflowStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowSchedule;
use App\Services\ExecutionService;
use App\Workflow\Data\DataModelRegistry;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns application events into workflow executions. Only ACTIVE workflows
 * respond to automatic triggers.
 */
class TriggerDispatcher
{
    /**
     * Incremented while the engine runs in this process, so records written by
     * a workflow never fire database-event triggers (prevents trigger loops).
     */
    public static int $engineDepth = 0;

    public function __construct(
        private readonly ExecutionService $executions,
        private readonly DataModelRegistry $data,
    ) {}

    public function modelEvent(string $alias, string $event, Model $record): void
    {
        if (self::$engineDepth > 0) {
            return;
        }

        try {
            $payload = [
                'model' => $alias,
                'event' => $event,
                'id' => $record->getKey(),
                'record' => $this->data->exists($alias) ? $this->data->serialize($alias, $record) : ['id' => $record->getKey()],
            ];
            $version = $record->updated_at?->getTimestamp() ?? time();

            foreach ($this->activeWorkflows(['database_event']) as $workflow) {
                $config = $workflow->triggerNode()['config'] ?? [];
                if (($config['model'] ?? null) !== $alias || ($config['event'] ?? 'created') !== $event) {
                    continue;
                }
                foreach ((array) ($config['match'] ?? []) as $field => $expected) {
                    if ((string) ($payload['record'][$field] ?? $record->getAttribute($field)) !== (string) $expected) {
                        continue 2;
                    }
                }
                $this->executions->start($workflow, 'database_event', $payload, null, 'production', "db:{$alias}:{$event}:{$record->getKey()}:{$version}");
            }

            if ($alias === 'users' && $event === 'created' && $record instanceof User) {
                foreach ($this->activeWorkflows(['user_created']) as $workflow) {
                    $this->executions->start($workflow, 'user_created', [
                        'user_id' => $record->id,
                        'name' => $record->name,
                        'email' => $record->email,
                    ], null, 'production', "user_created:{$record->id}");
                }
            }
        } catch (Throwable $e) {
            // A trigger problem must never break the originating request.
            Log::warning('Workflow trigger dispatch failed', ['model' => $alias, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    public function fileUploaded(MediaAsset $asset): int
    {
        $count = 0;

        foreach ($this->activeWorkflows(['file_uploaded']) as $workflow) {
            $prefix = (string) ($workflow->triggerNode()['config']['mime_prefix'] ?? '');
            if ($prefix !== '' && ! str_starts_with((string) $asset->mime_type, $prefix)) {
                continue;
            }

            $this->executions->start($workflow, 'file_uploaded', [
                'asset_id' => $asset->id,
                'title' => $asset->title,
                'file_path' => $asset->file_path,
                'mime_type' => $asset->mime_type,
                'language' => $asset->language,
                'size_bytes' => $asset->size_bytes,
            ], null, 'production', "file_uploaded:{$asset->id}");
            $count++;
        }

        return $count;
    }

    /**
     * Dispatch every due schedule exactly once per slot. A schedule row is
     * "claimed" with a conditional update, so overlapping scheduler processes
     * cannot both dispatch the same slot.
     */
    public function runDueSchedules(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $dispatched = 0;

        $due = WorkflowSchedule::query()
            ->with('workflow')
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->get();

        foreach ($due as $schedule) {
            $workflow = $schedule->workflow;
            $slot = $schedule->next_run_at;

            if ($workflow === null || $workflow->status !== WorkflowStatus::Active) {
                $schedule->update(['is_active' => false]);

                continue;
            }

            $next = $this->nextRun($schedule->cron_expression, $schedule->timezone, $now);
            $claimed = WorkflowSchedule::query()
                ->whereKey($schedule->id)
                ->where('next_run_at', $slot)
                ->update(['next_run_at' => $next, 'last_run_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            $node = collect($workflow->definition['nodes'] ?? [])->firstWhere('key', $schedule->node_key);
            $slotKey = $slot->format('YmdHi');

            if (($node['type'] ?? null) === 'trigger.user_verification_pending') {
                $dispatched += $this->verificationPending($workflow, (array) ($node['config'] ?? []), $slotKey);

                continue;
            }

            $this->executions->start($workflow, 'schedule', [
                'scheduled_for' => $slot->toIso8601String(),
                'cron' => $schedule->cron_expression,
                'timezone' => $schedule->timezone,
            ], null, 'production', "schedule:{$schedule->id}:{$slotKey}");
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * One execution per user whose verified_at IS NULL.
     *
     * @param  array<string, mixed>  $config
     */
    public function verificationPending(Workflow $workflow, array $config, string $slotKey): int
    {
        $minAge = (int) ($config['min_account_age_hours'] ?? 24);
        $limit = (int) ($config['max_users_per_run'] ?? 100);
        $count = 0;

        User::query()
            ->whereNull('verified_at')
            ->where('created_at', '<=', now()->subHours($minAge))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'verified_at'])
            ->each(function (User $user) use ($workflow, $slotKey, &$count) {
                $this->executions->start($workflow, 'user_verification_pending', [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified_at' => null,
                ], null, 'production', "verification_pending:{$user->id}:{$slotKey}");
                $count++;
            });

        return $count;
    }

    public function nextRun(string $cron, string $timezone, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();
        $next = (new CronExpression($cron))->getNextRunDate($from->setTimezone($timezone)->toDateTime(), 0, false, $timezone);

        return CarbonImmutable::instance($next)->utc();
    }

    /**
     * @param  list<string>  $triggerTypes
     * @return Collection<int, Workflow>
     */
    private function activeWorkflows(array $triggerTypes)
    {
        return Workflow::query()
            ->where('status', WorkflowStatus::Active)
            ->whereIn('trigger_type', $triggerTypes)
            ->get();
    }
}
