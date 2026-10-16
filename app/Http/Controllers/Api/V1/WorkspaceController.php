<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public const COLORS = ['#6161ff', '#00c875', '#ff7575', '#fdab3d', '#a25ddc', '#579bfc', '#e2445c', '#037f4c'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaces = $user->isSuperAdmin() ? Workspace::query()->orderBy('name')->get() : $user->workspaces()->orderBy('name')->get();

        return response()->json(['data' => $workspaces->map->only(['id', 'name', 'slug', 'color']), 'current_id' => $user->current_workspace_id]);
    }

    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate(['workspace_id' => ['required', 'integer', 'exists:workspaces,id']]);
        $user = $request->user();
        abort_unless($user->belongsToWorkspace((int) $data['workspace_id']), 403, 'You are not a member of that workspace.');

        $user->forceFill(['current_workspace_id' => $data['workspace_id']])->save();

        return response()->json(['message' => 'Workspace switched.', 'current_id' => $user->current_workspace_id]);
    }

    /**
     * Workspaces the user may manage (all for Super Admin, own memberships for Admins),
     * with member and workflow counts.
     */
    public function manage(Request $request): JsonResponse
    {
        Gate::authorize('workspaces.manage');
        $user = $request->user();

        $workspaces = Workspace::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->whereHas('users', fn ($q) => $q->whereKey($user->id)))
            ->withCount(['users', 'workflows'])
            ->with('users:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'color' => $w->color,
                'members_count' => $w->getAttribute('users_count'),
                'workflows_count' => $w->getAttribute('workflows_count'),
                'member_ids' => $w->users->pluck('id'),
                'is_current' => $w->id === $user->current_workspace_id,
            ]);

        return response()->json([
            'data' => $workspaces,
            'users' => User::query()->with('role:id,name')->orderBy('name')->get(['id', 'name', 'email', 'role_id'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role?->name]),
            'colors' => self::COLORS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('workspaces.manage');
        $data = $this->validated($request);
        $user = $request->user();

        $workspace = DB::transaction(function () use ($data, $user) {
            $workspace = Workspace::query()->create([...$data, 'slug' => $this->uniqueSlug($data['name'])]);
            $workspace->users()->attach($user->id);

            return $workspace;
        });

        $this->audit->log('workspace.created', $workspace, ['name' => $workspace->name], $user);

        return response()->json(['message' => 'Workspace created.', 'id' => $workspace->id], 201);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $before = $workspace->only(['name', 'color']);
        $workspace->update($this->validated($request));
        $this->audit->log('workspace.updated', $workspace, ['before' => $before, 'after' => $workspace->only(['name', 'color'])], $request->user());

        return response()->json(['message' => 'Workspace updated.']);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        // Deleting would orphan workflows (workspace_id → NULL makes them visible to everyone), so refuse.
        $workflows = Workflow::withTrashed()->where('workspace_id', $workspace->id)->count();
        abort_if($workflows > 0, 422, "This workspace still contains {$workflows} workflow(s). Delete or move them first.");

        $this->audit->log('workspace.deleted', $workspace, ['name' => $workspace->name], $request->user());
        $workspace->delete();

        return response()->json(['message' => 'Workspace deleted.']);
    }

    public function members(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $actor = $request->user();
        $ids = array_map('intval', $data['user_ids']);

        // An admin cannot remove themselves (they would lose access to manage it).
        if (! $actor->isSuperAdmin() && ! in_array($actor->id, $ids, true)) {
            $ids[] = $actor->id;
        }

        $changes = $workspace->users()->sync($ids);
        User::query()->whereIn('id', $changes['detached'])->where('current_workspace_id', $workspace->id)->update(['current_workspace_id' => null]);
        $this->audit->log('workspace.members_changed', $workspace, $changes, $actor);

        return response()->json(['message' => 'Members updated.', 'changes' => $changes]);
    }

    /**
     * @return array{name: string, color: string}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: string, color: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', 'in:'.implode(',', self::COLORS)],
        ]);

        return $data;
    }

    private function authorizeWorkspace(Request $request, Workspace $workspace): void
    {
        Gate::authorize('workspaces.manage');
        abort_unless($request->user()->belongsToWorkspace($workspace->id), 403, 'You are not a member of that workspace.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        for ($i = 2; Workspace::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
