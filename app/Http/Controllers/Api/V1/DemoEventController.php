<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\WorkflowExecution;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Demo-mode event simulator. Makes real changes to clearly labelled demo
 * records (is_demo = true) so that ACTIVE workflows with Database Event
 * triggers can be exercised end to end from the UI. Unavailable when
 * WORKFLOW_DEMO_MODE is off.
 */
class DemoEventController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function options(): JsonResponse
    {
        $this->guard();

        return response()->json([
            'contents' => Content::query()->where('is_demo', true)->orderBy('id')->get(['id', 'title', 'status', 'content_type']),
            'employees' => Employee::query()->where('is_demo', true)->orderBy('name')->get(['id', 'name', 'department', 'job_title']),
            'departments' => ['Engineering', 'Sales', 'Marketing', 'HR', 'Operations'],
        ]);
    }

    public function publishContent(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'content_id' => ['required', 'integer', Rule::exists('contents', 'id')->where('is_demo', true)],
        ]);

        return $this->fire($request, 'demo.content_published', function () use ($data) {
            $content = Content::query()->whereKey($data['content_id'])->firstOrFail();
            // Re-publishing counts as a new event, so step through "draft" first.
            if ($content->getAttribute('status') === 'published') {
                $content->forceFill(['status' => 'draft'])->saveQuietly();
            }
            $content->forceFill(['status' => 'published', 'published_at' => now()])->save();

            return $content;
        });
    }

    public function createEmployee(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'department' => ['required', 'string', 'max:60'],
            'job_title' => ['required', 'string', 'max:80'],
        ]);

        return $this->fire($request, 'demo.employee_created', function () use ($data) {
            $manager = Employee::query()->where('department', $data['department'])->whereNull('manager_id')->orderBy('id')->first();

            return Employee::query()->create([
                ...$data,
                'email' => str($data['name'])->slug('.').'.'.str()->lower(str()->random(4)).'@example.test',
                'manager_id' => $manager?->getKey(),
                'start_date' => now()->addDays(7)->toDateString(),
                'is_demo' => true,
            ]);
        });
    }

    public function createLeaveRequest(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('is_demo', true)],
            'leave_type' => ['required', 'in:annual,sick,parental,unpaid'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->fire($request, 'demo.leave_requested', fn () => LeaveRequest::query()->create([
            ...$data,
            'start_date' => CarbonImmutable::parse($data['start_date'])->toDateString(),
            'end_date' => CarbonImmutable::parse($data['end_date'])->toDateString(),
            'status' => 'pending',
            'is_demo' => true,
        ]));
    }

    /**
     * Run the change and report which executions the resulting model event started.
     *
     * @param  Closure(): Model  $change
     */
    private function fire(Request $request, string $action, Closure $change): JsonResponse
    {
        $lastId = (int) WorkflowExecution::query()->max('id');
        $record = $change();

        $executions = WorkflowExecution::query()
            ->with('workflow:id,name')
            ->where('id', '>', $lastId)
            ->where('trigger_type', 'database_event')
            ->get()
            ->map(fn (WorkflowExecution $e) => ['id' => $e->id, 'workflow' => $e->workflow->name, 'status' => $e->status->value])
            ->values();

        $this->audit->log($action, $record, ['executions' => $executions->pluck('id')->all()], $request->user());

        return response()->json([
            'record_id' => $record->getKey(),
            'executions' => $executions,
            'message' => $executions->isEmpty()
                ? 'Event fired, but no new execution started: no active workflow listens for it, or an identical event was already processed this second (idempotency).'
                : 'Event fired — '.$executions->count().' workflow execution(s) started.',
        ], 201);
    }

    private function guard(): void
    {
        Gate::authorize('workflows.execute');
        abort_unless((bool) config('workflows.demo_mode'), 403, 'The event simulator is only available in demo mode.');
    }
}
