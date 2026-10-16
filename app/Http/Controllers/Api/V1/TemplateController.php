<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('templates.view');

        return TemplateResource::collection(
            WorkflowTemplate::query()
                ->where('is_active', true)
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
                ->orderBy('id')
                ->get()
        );
    }

    public function show(WorkflowTemplate $template): TemplateResource
    {
        Gate::authorize('templates.view');

        return new TemplateResource($template);
    }

    /**
     * Creates a NEW workflow from the template; the template itself is never modified.
     */
    public function import(Request $request, WorkflowTemplate $template, WorkflowService $workflows): JsonResponse
    {
        Gate::authorize('templates.import');
        Gate::authorize('create', Workflow::class);
        $request->validate(['name' => ['nullable', 'string', 'max:120']]);

        $workflow = $workflows->importTemplate($template, $request->user(), $request->input('name'));

        return (new WorkflowResource($workflow))->response()->setStatusCode(201);
    }
}
