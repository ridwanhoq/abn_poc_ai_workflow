<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workflow_id
 * @property string $node_key
 * @property string $cron_expression
 * @property string $timezone
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property bool $is_active
 * @property-read Workflow|null $workflow
 */
class WorkflowSchedule extends Model
{
    protected $fillable = ['workflow_id', 'node_key', 'cron_expression', 'timezone', 'next_run_at', 'last_run_at', 'is_active'];

    protected function casts(): array
    {
        return ['next_run_at' => 'datetime', 'last_run_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
