<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('users.manage');

        $users = User::query()->with(['role:id,slug,name', 'workspaces:id,name'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%')->orWhere('email', 'like', '%'.$request->string('q').'%')))
            ->orderBy('id')
            ->paginate(25);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->only(['id', 'slug', 'name']),
                'workspaces' => $u->workspaces->map->only(['id', 'name']),
                'verified_at' => $u->verified_at?->toIso8601String(),
                'is_demo' => $u->is_demo,
            ]),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
            'roles' => Role::query()->with('permissions:id,slug')->get()->map(fn (Role $r) => [
                'id' => $r->id, 'slug' => $r->slug, 'name' => $r->name, 'description' => $r->description, 'permissions' => $r->permissions->pluck('slug'),
            ]),
            'workspaces' => Workspace::query()->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('users.manage');

        $data = $request->validate([
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'workspace_ids' => ['sometimes', 'array'],
            'workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        $actor = $request->user();
        $superAdminRole = Role::query()->where('slug', Role::SUPER_ADMIN)->value('id');

        // Only Super Admins may grant, change or revoke Super Admin access.
        if (! $actor->isSuperAdmin() && ($user->isSuperAdmin() || (int) ($data['role_id'] ?? 0) === $superAdminRole)) {
            abort(403, 'Only a Super Admin can manage Super Admin accounts.');
        }
        abort_if($actor->id === $user->id && isset($data['role_id']) && (int) $data['role_id'] !== $user->role_id, 422, 'You cannot change your own role.');

        $before = ['role_id' => $user->role_id, 'workspaces' => $user->workspaces()->pluck('workspaces.id')];

        if (isset($data['role_id'])) {
            $user->forceFill(['role_id' => $data['role_id']])->save();
        }
        if (isset($data['workspace_ids'])) {
            $user->workspaces()->sync($data['workspace_ids']);
        }

        $audit->log('user.access_changed', $user, ['before' => $before, 'after' => $data], $actor);

        return response()->json(['message' => 'User access updated.']);
    }
}
