<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Demo OTT/ERP domain model. Writes from workflows go through
 * App\Workflow\Data\DataModelRegistry, which whitelists writable columns.
 */
class Subscriber extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'subscription_renews_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
