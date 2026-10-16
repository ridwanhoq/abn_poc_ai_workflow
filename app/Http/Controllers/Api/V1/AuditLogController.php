<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');

        $logs = AuditLog::query()->with('user:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'user' => $l->user?->name,
                'subject' => $l->subject_type ? "{$l->subject_type} #{$l->subject_id}" : null,
                'changes' => $l->changes,
                'ip_address' => $l->ip_address,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
