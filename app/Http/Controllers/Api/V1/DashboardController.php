<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\AiUsage;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('workflows.view');

        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'workflow_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
            'trigger_type' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $request->user();
        $to = $request->filled('date_to') ? CarbonImmutable::parse($request->input('date_to'))->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = $request->filled('date_from') ? CarbonImmutable::parse($request->input('date_from'))->startOfDay() : $to->subDays(13)->startOfDay();

        $workflows = Workflow::query()->visibleTo($user)
            ->when($user->current_workspace_id, fn ($q) => $q->where('workspace_id', $user->current_workspace_id));
        $workflowIds = (clone $workflows)->pluck('id');

        $executions = WorkflowExecution::query()
            ->whereIn('workflow_id', $workflowIds)
            ->whereNull('parent_execution_id')
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('workflow_id'), fn ($q) => $q->where('workflow_id', $request->integer('workflow_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('trigger_type'), fn ($q) => $q->where('trigger_type', $request->string('trigger_type')));

        $byStatus = (clone $executions)->toBase()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $total = (int) $byStatus->sum();
        $success = (int) ($byStatus['success'] ?? 0);
        $failed = (int) ($byStatus['failed'] ?? 0);
        $finished = $success + $failed + (int) ($byStatus['partial'] ?? 0);

        // Daily activity (portable across MySQL / SQLite).
        $daily = (clone $executions)->toBase()
            ->select(DB::raw('DATE(created_at) as day'), 'status', DB::raw('count(*) as total'))
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');

        $activity = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $rows = $daily->get($day->toDateString(), collect());
            $activity[] = [
                'date' => $day->toDateString(),
                'success' => (int) $rows->where('status', 'success')->sum('total'),
                'failed' => (int) $rows->where('status', 'failed')->sum('total'),
                'other' => (int) $rows->whereNotIn('status', ['success', 'failed'])->sum('total'),
            ];
        }

        $top = (clone $executions)->toBase()
            ->select('workflow_id', DB::raw('count(*) as runs'), DB::raw("sum(case when status = 'success' then 1 else 0 end) as successes"))
            ->groupBy('workflow_id')
            ->orderByDesc('runs')
            ->limit(5)
            ->get();
        $names = Workflow::withTrashed()->whereIn('id', $top->pluck('workflow_id'))->pluck('name', 'id');

        // Prefer templates this workspace has not imported yet, then the most popular.
        $importedTemplateIds = Workflow::query()->whereIn('id', $workflowIds)->whereNotNull('template_id')->pluck('template_id')->all();
        $suggested = WorkflowTemplate::query()->where('is_active', true)
            ->orderByDesc('import_count')
            ->get()
            ->sortBy(fn (WorkflowTemplate $t) => in_array($t->id, $importedTemplateIds) ? 1 : 0)
            ->take(3)
            ->values();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'workflows' => (clone $workflows)->count(),
                'active_workflows' => (clone $workflows)->where('status', 'active')->count(),
                'executions' => $total,
                'successful' => $success,
                'failed' => $failed,
                'partial' => (int) ($byStatus['partial'] ?? 0),
                'in_progress' => (int) (($byStatus['queued'] ?? 0) + ($byStatus['running'] ?? 0) + ($byStatus['waiting'] ?? 0)),
                'success_rate' => $finished > 0 ? round($success / $finished * 100, 1) : null,
                'avg_duration_ms' => (int) round((float) (clone $executions)->whereNotNull('duration_ms')->avg('duration_ms')),
            ],
            'ai_usage' => $this->aiUsage(array_values(array_map('intval', $workflowIds->all())), $from, $to, $request->filled('workflow_id') ? $request->integer('workflow_id') : null),
            'status_breakdown' => $byStatus,
            'trigger_breakdown' => (clone $executions)->toBase()->select('trigger_type', DB::raw('count(*) as total'))->groupBy('trigger_type')->pluck('total', 'trigger_type'),
            'activity' => $activity,
            'top_workflows' => $top->map(fn ($row) => [
                'id' => $row->workflow_id,
                'name' => $names[$row->workflow_id] ?? 'Deleted workflow',
                'runs' => (int) $row->runs,
                'success_rate' => $row->runs ? round($row->successes / $row->runs * 100, 1) : null,
            ]),
            'recent_executions' => (clone $executions)->with('workflow:id,name')->latest('id')->limit(8)->get()->map(fn ($e) => [
                'id' => $e->id,
                'workflow' => $e->workflow->name,
                'workflow_id' => $e->workflow_id,
                'status' => $e->status->value,
                'mode' => $e->mode,
                'trigger_type' => $e->trigger_type,
                'duration_ms' => $e->duration_ms,
                'created_at' => $e->created_at?->toIso8601String(),
            ]),
            'suggested_templates' => TemplateResource::collection($suggested),
            'workflow_options' => (clone $workflows)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Token usage and estimated cost of AI calls in the dashboard range.
     *
     * @param  list<int>  $workflowIds
     * @return array<string, mixed>
     */
    private function aiUsage(array $workflowIds, CarbonImmutable $from, CarbonImmutable $to, ?int $workflowId): array
    {
        $base = AiUsage::query()->toBase()
            ->whereIn('workflow_id', $workflowIds)
            ->whereBetween('created_at', [$from, $to])
            ->when($workflowId, fn ($q) => $q->where('workflow_id', $workflowId));

        $totals = (clone $base)->selectRaw(
            'count(*) as calls, sum(case when simulated = 0 then 1 else 0 end) as real_calls, '
            .'coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, '
            .'coalesce(sum(estimated_cost_usd), 0) as cost'
        )->first();

        $byModel = (clone $base)
            ->select('provider', 'model', 'simulated')
            ->selectRaw('count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(estimated_cost_usd) as cost')
            ->groupBy('provider', 'model', 'simulated')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'model' => $row->model,
                'simulated' => (bool) $row->simulated,
                'calls' => (int) $row->calls,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'estimated_cost_usd' => round((float) $row->cost, 4),
            ]);

        return [
            'calls' => (int) ($totals->calls ?? 0),
            'real_calls' => (int) ($totals->real_calls ?? 0),
            'simulated_calls' => (int) ($totals->calls ?? 0) - (int) ($totals->real_calls ?? 0),
            'input_tokens' => (int) ($totals->input_tokens ?? 0),
            'output_tokens' => (int) ($totals->output_tokens ?? 0),
            'estimated_cost_usd' => round((float) ($totals->cost ?? 0), 4),
            'by_model' => $byModel,
        ];
    }
}
