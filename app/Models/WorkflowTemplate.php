<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $definition
 * @property array<string, mixed>|null $settings
 * @property list<string>|null $required_integrations
 * @property list<string>|null $tags
 * @property string $icon
 * @property string $color
 * @property bool $is_active
 * @property int $import_count
 */
class WorkflowTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'category', 'definition', 'settings', 'required_integrations',
        'tags', 'icon', 'color', 'is_active', 'import_count',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'settings' => 'array',
            'required_integrations' => 'array',
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
