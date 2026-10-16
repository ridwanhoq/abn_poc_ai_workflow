<?php

namespace App\Events;

use App\Models\WorkflowExecution;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time "something changed" signal for an execution. Deliberately carries
 * only identifiers and status: clients re-fetch details through the authorized
 * REST API, so nothing sensitive travels over the socket.
 */
class ExecutionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @var array{id: int, workflow_id: int, status: string} */
    private readonly array $payload;

    private readonly ?int $workspaceId;

    /**
     * The payload is captured now, so each event reports the state at the moment it happened.
     */
    public function __construct(WorkflowExecution $execution)
    {
        $this->payload = [
            'id' => $execution->id,
            'workflow_id' => $execution->workflow_id,
            'status' => $execution->status->value,
        ];
        $this->workspaceId = $execution->workflow->workspace_id;
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("executions.{$this->payload['id']}")];

        if ($this->workspaceId !== null) {
            $channels[] = new PrivateChannel("workspaces.{$this->workspaceId}.executions");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'execution.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
