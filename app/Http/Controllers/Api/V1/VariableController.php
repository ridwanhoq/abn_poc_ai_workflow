<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkflowVariable;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VariableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('variables.view');
        $workspaceId = $request->user()->current_workspace_id;

        return response()->json(['data' => WorkflowVariable::query()
            ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))
            ->orderBy('key')
            ->get()
            ->map(fn (WorkflowVariable $v) => [
                'id' => $v->id,
                'key' => $v->key,
                'value' => $v->is_secret ? null : $v->value,
                'is_secret' => $v->is_secret,
                'scope' => $v->workspace_id ? 'workspace' : 'global',
                'description' => $v->description,
                'updated_at' => $v->updated_at?->toIso8601String(),
            ])]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('variables.manage');
        $workspaceId = $request->boolean('global') ? null : $request->user()->current_workspace_id;

        $data = $request->validate([
            'key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z_]\w*$/', Rule::unique('workflow_variables')->where('workspace_id', $workspaceId)],
            'value' => ['nullable', 'string', 'max:5000'],
            'is_secret' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $variable = new WorkflowVariable(['workspace_id' => $workspaceId, 'is_secret' => (bool) ($data['is_secret'] ?? false)]);
        $variable->fill(['key' => $data['key'], 'description' => $data['description'] ?? null, 'created_by' => $request->user()->id]);
        $variable->value = $data['value'] ?? null;
        $variable->save();
        $audit->log('variable.created', $variable, ['key' => $variable->key], $request->user());

        return response()->json(['message' => 'Variable created.', 'id' => $variable->id], 201);
    }

    public function update(Request $request, WorkflowVariable $variable, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('variables.manage');
        $this->assertScope($request, $variable);

        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('value', $data) && ($data['value'] !== null || ! $variable->is_secret)) {
            $variable->value = $data['value'];
        }
        $variable->description = $data['description'] ?? $variable->description;
        $variable->save();
        $audit->log('variable.updated', $variable, ['key' => $variable->key], $request->user());

        return response()->json(['message' => 'Variable updated.']);
    }

    public function destroy(Request $request, WorkflowVariable $variable, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('variables.manage');
        $this->assertScope($request, $variable);
        $variable->delete();
        $audit->log('variable.deleted', $variable, ['key' => $variable->key], $request->user());

        return response()->json(['message' => 'Variable deleted.']);
    }

    private function assertScope(Request $request, WorkflowVariable $variable): void
    {
        abort_unless($variable->workspace_id === null || $request->user()->belongsToWorkspace($variable->workspace_id), 403);
    }
}
