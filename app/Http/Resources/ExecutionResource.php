<?php

namespace App\Http\Resources;

use App\Models\WorkflowExecution;
use App\Workflow\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowExecution
 */
class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'workflow' => $this->whenLoaded('workflow', fn () => [
                'id' => $this->workflow->id,
                'name' => $this->workflow->name,
                'deleted' => $this->workflow->trashed(),
            ]),
            'workflow_version' => $this->workflow_version,
            'status' => $this->status->value,
            'mode' => $this->mode,
            'trigger_type' => $this->trigger_type,
            'trigger_data' => app(Redactor::class)->forStorage($this->trigger_data),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'error_message' => $this->error_message,
            'result' => $this->result,
            'node_executions' => $this->node_executions,
            'initiator' => $this->whenLoaded('initiator', fn () => $this->initiator ? ['id' => $this->initiator->id, 'name' => $this->initiator->name] : null),
            'retry_of_id' => $this->retry_of_id,
            'parent_execution_id' => $this->parent_execution_id,
            'start_node_key' => $this->start_node_key,
            'resume_at' => $this->resume_at?->toIso8601String(),
            'cancel_requested_at' => $this->cancel_requested_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'cancel' => $this->status->isCancellable() && ($user?->can('cancel', $this->resource) ?? false),
                'retry' => $this->status->isFinished() && $this->status->value !== 'success'
                    && ! $this->workflow->trashed()
                    && ($user?->can('retry', $this->resource) ?? false),
            ],
        ];
    }
}
