<?php

namespace App\Http\Requests\Workflow;

use App\Models\Workflow;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Workflow::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:80'],
            'definition' => ['sometimes', 'array'],
            'definition.nodes' => ['required_with:definition', 'array', 'max:200'],
            'definition.edges' => ['present_with:definition', 'array', 'max:600'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
