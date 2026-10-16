<?php

namespace App\Models;

use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $execution_id
 * @property string $node_key
 * @property string $node_type
 * @property string|null $node_name
 * @property StepStatus $status
 * @property mixed $input_data
 * @property mixed $output_data
 * @property string|null $error_message
 * @property int $attempt_number
 * @property int|null $iteration
 * @property int $sequence
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
class WorkflowExecutionStep extends Model
{
    protected $fillable = [
        'execution_id', 'node_key', 'node_type', 'node_name', 'status', 'input_data', 'output_data',
        'error_message', 'attempt_number', 'iteration', 'sequence', 'started_at', 'finished_at', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'input_data' => 'array',
            'output_data' => 'array',
            'started_at' => 'datetime:Y-m-d H:i:s.v',
            'finished_at' => 'datetime:Y-m-d H:i:s.v',
        ];
    }

    /**
     * @return BelongsTo<WorkflowExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(WorkflowExecution::class, 'execution_id');
    }
}
