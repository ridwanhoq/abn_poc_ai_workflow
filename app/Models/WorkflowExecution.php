<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Workflow\Support\ExecutionBroadcaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workflow_id
 * @property int|null $workflow_version
 * @property string $trigger_type
 * @property array<string, mixed>|null $trigger_data
 * @property ExecutionStatus $status
 * @property string $mode
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $error_message
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $state
 * @property int|null $initiated_by
 * @property string|null $idempotency_key
 * @property int|null $parent_execution_id
 * @property int|null $retry_of_id
 * @property string|null $start_node_key
 * @property Carbon|null $resume_at
 * @property Carbon|null $cancel_requested_at
 * @property int $node_executions
 * @property Carbon|null $created_at
 * @property-read Workflow $workflow
 * @property-read User|null $initiator
 */
class WorkflowExecution extends Model
{
    use Prunable;

    protected $fillable = [
        'uuid', 'workflow_id', 'workflow_version', 'trigger_type', 'trigger_data', 'status', 'mode',
        'started_at', 'finished_at', 'duration_ms', 'error_message', 'result', 'state', 'initiated_by',
        'idempotency_key', 'parent_execution_id', 'retry_of_id', 'start_node_key', 'resume_at',
        'cancel_requested_at', 'node_executions',
    ];

    protected $hidden = ['state'];

    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'trigger_data' => 'array',
            'result' => 'array',
            'state' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'resume_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $execution) {
            $execution->uuid ??= (string) Str::uuid();
        });

        // Real-time updates (no-op unless broadcasting is configured).
        static::created(fn (self $execution) => app(ExecutionBroadcaster::class)->notify($execution, true));
        static::updated(function (self $execution) {
            if ($execution->wasChanged('status')) {
                app(ExecutionBroadcaster::class)->notify($execution, true);
            }
        });
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * @return HasMany<WorkflowExecutionStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowExecutionStep::class, 'execution_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<WorkflowExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowExecutionLog::class, 'execution_id');
    }

    /**
     * @return BelongsTo<WorkflowExecution, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_execution_id');
    }

    /**
     * @return BelongsTo<WorkflowExecution, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /**
     * Guarded status transition. Throws when the state machine forbids the move.
     */
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(ExecutionStatus $next, array $attributes = []): void
    {
        if ($this->status !== $next && ! $this->status->canTransitionTo($next)) {
            throw new LogicException("Invalid execution transition {$this->status->value} → {$next->value}");
        }

        $this->forceFill(['status' => $next, ...$attributes])->save();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<', now()->subDays((int) config('workflows.retention_days', 30)))
            ->whereIn('status', ['success', 'partial', 'failed', 'cancelled']);
    }
}
