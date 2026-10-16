<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExecutionResource;
use App\Models\AiUsage;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Services\ExecutionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExecutionController extends Controller
{
    public function __construct(private readonly ExecutionService $executions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('executions.view');

        $request->validate([
            'status' => ['nullable', 'in:queued,running,waiting,success,partial,failed,cancelled'],
            'workflow_id' => ['nullable', 'integer'],
            'trigger_type' => ['nullable', 'string', 'max:60'],
            'mode' => ['nullable', 'in:production,test'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WorkflowExecution::query()
            ->whereIn('workflow_id', $this->visibleWorkflowIds($request))
            ->with(['workflow:id,name,deleted_at', 'initiator:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('workflow_id'), fn ($q) => $q->where('workflow_id', $request->integer('workflow_id')))
            ->when($request->filled('trigger_type'), fn ($q) => $q->where('trigger_type', $request->string('trigger_type')))
            ->when($request->filled('mode'), fn ($q) => $q->where('mode', $request->string('mode')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->whereNull('parent_execution_id')
            ->latest('id');

        return ExecutionResource::collection($query->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function show(Request $request, WorkflowExecution $execution): JsonResponse
    {
        Gate::authorize('view', $execution);

        $execution->load(['workflow', 'initiator:id,name']);
        $limit = min(1000, max(50, $request->integer('steps_limit', 300)));

        $steps = $execution->steps()->limit($limit)->get()->map(fn ($s) => [
            'id' => $s->id,
            'node_key' => $s->node_key,
            'node_type' => $s->node_type,
            'node_name' => $s->node_name,
            'status' => $s->status->value,
            'input' => $s->input_data,
            'output' => $s->output_data,
            'error' => $s->error_message,
            'attempt' => $s->attempt_number,
            'iteration' => $s->iteration,
            'started_at' => $s->started_at?->format('Y-m-d\TH:i:s.vP'),
            'finished_at' => $s->finished_at?->format('Y-m-d\TH:i:s.vP'),
            'duration_ms' => $s->duration_ms,
        ]);

        // Per-node aggregate (useful for loops with many iterations).
        $summary = $execution->steps()->toBase()
            ->reorder()
            ->select('node_key', 'status', DB::raw('count(*) as total'), DB::raw('avg(duration_ms) as avg_ms'))
            ->groupBy('node_key', 'status')
            ->get()
            ->groupBy('node_key')
            ->map(fn ($rows) => [
                'statuses' => $rows->mapWithKeys(fn ($r) => [$r->status => (int) $r->total]),
                'avg_duration_ms' => (int) round((float) $rows->avg('avg_ms')),
            ]);

        return response()->json([
            'data' => new ExecutionResource($execution),
            'steps' => $steps,
            'steps_total' => $execution->steps()->count(),
            'node_summary' => $summary,
            'logs' => $execution->logs()->latest('id')->limit(200)->get(['id', 'node_key', 'level', 'message', 'context', 'created_at']),
            'definition' => $execution->workflow->definition,
            'ai_usage' => AiUsage::query()->where('execution_id', $execution->id)
                ->orderBy('id')
                ->get(['node_key', 'task', 'provider', 'model', 'simulated', 'input_tokens', 'output_tokens', 'estimated_cost_usd'])
                ->map(fn (AiUsage $u) => [
                    'node_key' => $u->node_key,
                    'task' => $u->task,
                    'provider' => $u->provider,
                    'model' => $u->model,
                    'simulated' => $u->simulated,
                    'input_tokens' => $u->input_tokens,
                    'output_tokens' => $u->output_tokens,
                    'estimated_cost_usd' => (float) $u->estimated_cost_usd,
                ]),
            'can_execute_from_node' => $execution->state !== null && ($request->user()->can('execute', $execution->workflow)),
        ]);
    }

    public function cancel(Request $request, WorkflowExecution $execution): ExecutionResource
    {
        Gate::authorize('cancel', $execution);

        return new ExecutionResource($this->executions->cancel($execution)->load('workflow'));
    }

    public function retry(Request $request, WorkflowExecution $execution): JsonResponse
    {
        Gate::authorize('retry', $execution);
        $retry = $this->executions->retry($execution, $request->user());

        return (new ExecutionResource($retry->load('workflow')))->response()->setStatusCode(202);
    }

    /**
     * Lightweight counters for the top-bar status indicator.
     */
    public function status(Request $request): JsonResponse
    {
        Gate::authorize('executions.view');
        $ids = $this->visibleWorkflowIds($request);

        $counts = WorkflowExecution::query()
            ->whereIn('workflow_id', $ids)
            ->whereIn('status', ['queued', 'running', 'waiting'])
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldestQueued = WorkflowExecution::query()->whereIn('workflow_id', $ids)->where('status', 'queued')->min('created_at');

        return response()->json([
            'queued' => (int) ($counts['queued'] ?? 0),
            'running' => (int) ($counts['running'] ?? 0),
            'waiting' => (int) ($counts['waiting'] ?? 0),
            'failed_24h' => WorkflowExecution::query()->whereIn('workflow_id', $ids)->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            // Hint for the UI: queued jobs older than a minute usually mean no queue worker is running.
            'worker_suspect' => $oldestQueued !== null && now()->diffInSeconds($oldestQueued, true) > 60,
        ]);
    }

    /**
     * @return Builder<Workflow>
     */
    private function visibleWorkflowIds(Request $request)
    {
        return Workflow::withTrashed()->visibleTo($request->user())->select('id');
    }
}
