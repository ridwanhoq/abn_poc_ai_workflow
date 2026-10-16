<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('execute', $this->route('workflow'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payload' => ['nullable', 'array'],
            'test' => ['sometimes', 'boolean'],
            'start_node_key' => ['nullable', 'string', 'max:60'],
        ];
    }
}
