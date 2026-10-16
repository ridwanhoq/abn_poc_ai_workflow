<?php

namespace App\Http\Resources;

use App\Models\WorkflowTemplate;
use App\Workflow\NodeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkflowTemplate
 */
class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registry = app(NodeRegistry::class);
        $nodes = $this->definition['nodes'] ?? [];
        $simulated = collect($nodes)
            ->filter(fn ($n) => $registry->has($n['type']) && $registry->get($n['type'])->implementation() !== 'implemented')
            ->pluck('name')
            ->values();

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'icon' => $this->icon,
            'color' => $this->color,
            'tags' => $this->tags ?? [],
            'definition' => $this->definition,
            'settings' => $this->settings,
            'node_count' => count($nodes),
            'trigger_type' => collect($nodes)->first(fn ($n) => str_starts_with($n['type'], 'trigger.'))['type'] ?? null,
            'required_integrations' => $this->required_integrations ?? [],
            'simulated_nodes' => $simulated,
            'import_count' => $this->import_count,
        ];
    }
}
