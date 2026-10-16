<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Demo OTT/ERP domain model. Writes from workflows go through
 * App\Workflow\Data\DataModelRegistry, which whitelists writable columns.
 */
class MediaAssetInsight extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'keywords' => 'array',
            'tags' => 'array',
            'technical_metadata' => 'array',
            'moderation' => 'array',
            'is_simulated' => 'boolean',
        ];
    }
}
