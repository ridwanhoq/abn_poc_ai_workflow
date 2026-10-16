<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $provider
 * @property string $category
 * @property string|null $description
 * @property array<string, mixed>|null $configuration
 * @property array<string, string>|null $credentials
 * @property string $status
 * @property Carbon|null $last_checked_at
 */
class WorkflowIntegration extends Model
{
    protected $fillable = [
        'key', 'name', 'provider', 'category', 'description', 'configuration', 'credentials', 'status',
        'last_checked_at', 'created_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'credentials' => 'encrypted:array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->credentials);
    }
}
