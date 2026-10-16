<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']])['q']);
        $user = $request->user();
        $results = [];

        if ($user->hasPermission('workflows.view')) {
            foreach (Workflow::query()->visibleTo($user)->where('name', 'like', "%{$q}%")->limit(6)->get(['id', 'name', 'status']) as $w) {
                $results[] = ['type' => 'workflow', 'id' => $w->id, 'title' => $w->name, 'subtitle' => ucfirst($w->status->value), 'url' => "/workflows/{$w->id}/edit"];
            }
        }

        if ($user->hasPermission('templates.view')) {
            foreach (WorkflowTemplate::query()->where('name', 'like', "%{$q}%")->limit(4)->get(['id', 'name', 'category']) as $t) {
                $results[] = ['type' => 'template', 'id' => $t->id, 'title' => $t->name, 'subtitle' => $t->category, 'url' => "/templates?preview={$t->id}"];
            }
        }

        if ($user->hasPermission('executions.view') && ctype_digit(ltrim($q, '#'))) {
            $execution = WorkflowExecution::query()->with('workflow')->find((int) ltrim($q, '#'));
            if ($execution && $user->can('view', $execution)) {
                $results[] = ['type' => 'execution', 'id' => $execution->id, 'title' => "Execution #{$execution->id}", 'subtitle' => $execution->workflow->name.' · '.$execution->status->value, 'url' => "/executions/{$execution->id}"];
            }
        }

        return response()->json(['data' => $results]);
    }
}
