<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Support\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only browser for the data models workflows can access.
 */
class DataController extends Controller
{
    /**
     * Models whose records describe people across all workspaces need a stronger permission.
     *
     * @var array<string, string>
     */
    private const RESTRICTED = ['users' => 'users.manage'];

    public function index(Request $request, DataModelRegistry $data): JsonResponse
    {
        Gate::authorize('variables.view');
        $user = $request->user();

        return response()->json([
            'data' => collect($data->definitions())
                ->filter(fn ($d, $alias) => ! isset(self::RESTRICTED[$alias]) || $user->hasPermission(self::RESTRICTED[$alias]))
                ->map(fn ($d, $alias) => [
                    'alias' => $alias,
                    'label' => $d['label'],
                    'count' => $data->query($alias)->count(),
                    'readable' => $d['readable'],
                    'writable' => $d['writable'],
                    'updatable' => $d['updatable'],
                ])->values(),
            'users_verification' => [
                'verified' => User::query()->whereNotNull('verified_at')->count(),
                'unverified' => User::query()->whereNull('verified_at')->count(),
            ],
            'demo_mode' => (bool) config('workflows.demo_mode'),
        ]);
    }

    public function show(Request $request, string $alias, DataModelRegistry $data): JsonResponse
    {
        Gate::authorize('variables.view');
        abort_unless($data->exists($alias), 404);
        if (isset(self::RESTRICTED[$alias])) {
            Gate::authorize(self::RESTRICTED[$alias]);
        }

        $rows = $data->query($alias)->latest('id')->limit(min(100, $request->integer('limit', 25)))->get()
            ->map(fn ($row) => app(Redactor::class)->redact($data->serialize($alias, $row)));

        return response()->json(['data' => $rows, 'columns' => $data->definition($alias)['readable']]);
    }
}
