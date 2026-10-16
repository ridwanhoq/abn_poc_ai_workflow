<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Engine\ExpressionResolver;
use App\Workflow\NodeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class NodeTypeController extends Controller
{
    public function __invoke(NodeRegistry $registry, DataModelRegistry $data): JsonResponse
    {
        Gate::authorize('workflows.view');

        return response()->json([
            'data' => array_values(array_map(fn ($node) => $node->toArray(), $registry->all())),
            'categories' => [
                ['key' => 'trigger', 'label' => 'Triggers', 'color' => '#ff7575'],
                ['key' => 'logic', 'label' => 'Logic', 'color' => '#fdab3d'],
                ['key' => 'data', 'label' => 'Data', 'color' => '#579bfc'],
                ['key' => 'notification', 'label' => 'Notifications', 'color' => '#00c875'],
                ['key' => 'ai_media', 'label' => 'AI & Media', 'color' => '#a25ddc'],
                ['key' => 'utility', 'label' => 'Utilities', 'color' => '#7e8494'],
            ],
            'data_models' => collect($data->definitions())->map(fn ($d, $alias) => [
                'alias' => $alias,
                'label' => $d['label'],
                'readable' => $d['readable'],
                'writable' => $d['writable'],
                'updatable' => $d['updatable'],
            ])->values(),
            'filter_operators' => DataModelRegistry::OPERATORS,
            'expression' => [
                'roots' => ExpressionResolver::ROOTS,
                'filters' => ExpressionResolver::FILTERS,
            ],
            'demo_mode' => (bool) config('workflows.demo_mode'),
        ]);
    }
}
