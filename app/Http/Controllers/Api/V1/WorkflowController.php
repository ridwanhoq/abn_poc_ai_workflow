<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ExecuteWorkflowRequest;
use App\Http\Requests\Workflow\StoreWorkflowRequest;
use App\Http\Requests\Workflow\UpdateWorkflowRequest;
use App\Http\Resources\ExecutionResource;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\ExecutionService;
use App\Services\WorkflowService;
use App\Workflow\Engine\GraphValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly ExecutionService $executions,
        private readonly GraphValidator $validator,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Workflow::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'trigger_type' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:name,updated_at,created_at,status'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $query = Workflow::query()
            ->visibleTo($user)
            ->when($user->current_workspace_id && ! $request->boolean('all_workspaces'), fn ($q) => $q->where('workspace_id', $user->current_workspace_id))
            ->with(['creator:id,name', 'latestExecution'])
            ->withCount(['executions', 'executions as successful_count' => fn ($q) => $q->where('status', 'success')])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('description', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('trigger_type'), fn ($q) => $q->where('trigger_type', $request->string('trigger_type')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by', $user->id))
            ->orderBy($request->input('sort', 'updated_at'), $request->input('direction', 'desc'));

        return WorkflowResource::collection($query->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $workflow = $this->workflows->create($request->validated(), $request->user());

        return (new WorkflowResource($workflow->load('creator')))->response()->setStatusCode(201);
    }

    public function show(Workflow $workflow): WorkflowResource
    {
        Gate::authorize('view', $workflow);

        return new WorkflowResource($workflow->load(['creator:id,name', 'latestExecution']));
    }

    public function update(UpdateWorkflowRequest $request, Workflow $workflow): JsonResponse
    {
        $workflow = $this->workflows->update($workflow, $request->validated(), $request->user());

        return response()->json([
            'data' => new WorkflowResource($workflow->load('creator:id,name')),
            'validation' => $this->validator->validate($workflow->definition),
        ]);
    }

    public function destroy(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('delete', $workflow);
        $this->workflows->delete($workflow, $request->user());

        return response()->json(['message' => 'Workflow deleted.']);
    }

    /**
     * Validate either the posted (unsaved) definition or the saved one.
     */
    public function validateDefinition(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $workflow);
        $request->validate(['definition' => ['sometimes', 'array']]);

        return response()->json($this->validator->validate($request->input('definition', $workflow->definition)));
    }

    public function activate(Request $request, Workflow $workflow): WorkflowResource
    {
        Gate::authorize('activate', $workflow);

        return new WorkflowResource($this->workflows->activate($workflow, $request->user()));
    }

    public function deactivate(Request $request, Workflow $workflow): WorkflowResource
    {
        Gate::authorize('activate', $workflow);

        return new WorkflowResource($this->workflows->deactivate($workflow, $request->user()));
    }

    public function duplicate(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $workflow);
        Gate::authorize('create', Workflow::class);

        return (new WorkflowResource($this->workflows->duplicate($workflow, $request->user())))->response()->setStatusCode(201);
    }

    public function execute(ExecuteWorkflowRequest $request, Workflow $workflow): JsonResponse
    {
        $user = $request->user();

        $execution = $request->filled('start_node_key')
            ? $this->executions->fromNode($workflow, (string) $request->input('start_node_key'), $user)
            : $this->executions->manual($workflow, $user, $request->input('payload'), $request->boolean('test', true));

        return (new ExecutionResource($execution->load('workflow')))->response()->setStatusCode(202);
    }

    public function executions(Request $request, Workflow $workflow): AnonymousResourceCollection
    {
        Gate::authorize('view', $workflow);

        return ExecutionResource::collection(
            $workflow->executions()->with(['workflow', 'initiator:id,name'])->latest('id')->paginate($request->integer('per_page', 15))
        );
    }

    public function versions(Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $workflow);

        return response()->json(['data' => $workflow->versions()->with('creator:id,name')->latest('version')->limit(50)->get()
            ->map(fn (WorkflowVersion $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'note' => $v->note,
                'node_count' => count($v->definition['nodes'] ?? []),
                'creator' => $v->creator?->name,
                'created_at' => $v->created_at?->toIso8601String(),
            ])]);
    }

    public function restoreVersion(Request $request, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        Gate::authorize('update', $workflow);
        abort_unless($version->workflow_id === $workflow->id, 404);

        $workflow = $this->workflows->restoreVersion($workflow, $version, $request->user());

        return response()->json(['data' => new WorkflowResource($workflow), 'message' => "Restored version {$version->version}."]);
    }

    public function rotateWebhook(Request $request, Workflow $workflow): WorkflowResource
    {
        Gate::authorize('update', $workflow);
        abort_unless($workflow->trigger_type === 'webhook', 422, 'This workflow does not use a webhook trigger.');

        return new WorkflowResource($this->workflows->ensureWebhookCredentials($workflow, true));
    }
}
