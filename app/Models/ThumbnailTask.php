<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Demo OTT/ERP domain model. Writes from workflows go through
 * App\Workflow\Data\DataModelRegistry, which whitelists writable columns.
 */
class ThumbnailTask extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
            'is_simulated' => 'boolean',
        ];
    }
}
