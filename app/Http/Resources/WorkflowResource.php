<?php

namespace App\Http\Resources;

use App\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canUpdate = $user?->can('update', $this->resource) ?? false;
        $executions = $this->executions_count ?? null;
        $successful = $this->successful_count ?? null;
        $isDetail = $request->route('workflow') !== null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status->value,
            'trigger_type' => $this->trigger_type,
            'version' => $this->version,
            'workspace_id' => $this->workspace_id,
            'template_id' => $this->template_id,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'node_count' => count($this->definition['nodes'] ?? []),
            'definition' => $this->when($isDetail, $this->definition),
            'settings' => $this->effectiveSettings(),
            'executions_count' => $executions,
            'success_rate' => $executions ? round(($successful / $executions) * 100, 1) : null,
            'last_execution' => $this->whenLoaded('latestExecution', fn () => $this->latestExecution ? [
                'id' => $this->latestExecution->id,
                'status' => $this->latestExecution->status->value,
                'created_at' => $this->latestExecution->created_at?->toIso8601String(),
            ] : null),
            'webhook' => $this->when($isDetail && $canUpdate && $this->trigger_type === 'webhook' && $this->webhook_token, fn () => [
                'url' => url("/api/v1/hooks/{$this->webhook_token}"),
                'secret' => $this->webhook_secret,
                'signature_header' => 'X-Workflow-Signature',
                'algorithm' => 'sha256=HMAC(secret, raw body)',
            ]),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $canUpdate,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'execute' => $user?->can('execute', $this->resource) ?? false,
                'activate' => $user?->can('activate', $this->resource) ?? false,
            ],
        ];
    }
}
