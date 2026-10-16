<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('workflow'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'definition' => ['sometimes', 'array'],
            'definition.nodes' => ['required_with:definition', 'array', 'max:200'],
            'definition.nodes.*.key' => ['required', 'string', 'max:60'],
            'definition.nodes.*.type' => ['required', 'string', 'max:80'],
            'definition.nodes.*.name' => ['nullable', 'string', 'max:120'],
            'definition.nodes.*.position' => ['nullable', 'array'],
            'definition.nodes.*.config' => ['nullable', 'array'],
            'definition.nodes.*.settings' => ['nullable', 'array'],
            'definition.edges' => ['present_with:definition', 'array', 'max:600'],
            'definition.edges.*.source' => ['required', 'string'],
            'definition.edges.*.target' => ['required', 'string'],
            'definition.edges.*.sourceHandle' => ['nullable', 'string', 'max:40'],
            'settings' => ['sometimes', 'array'],
            'settings.timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:3600'],
            'settings.max_node_executions' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'settings.failure_policy' => ['sometimes', 'in:stop,continue'],
            'settings.default_retries' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'settings.retry_backoff_ms' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'settings.allow_external_side_effects' => ['sometimes', 'boolean'],
            'version_note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
