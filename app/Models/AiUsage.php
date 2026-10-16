<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $execution_id
 * @property int|null $workflow_id
 * @property int|null $workspace_id
 * @property string $node_key
 * @property string|null $task
 * @property string $provider
 * @property string $model
 * @property bool $simulated
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $estimated_cost_usd
 * @property string $mode
 * @property Carbon|null $created_at
 */
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected $fillable = [
        'execution_id', 'workflow_id', 'workspace_id', 'node_key', 'task', 'provider', 'model', 'simulated',
        'input_tokens', 'output_tokens', 'estimated_cost_usd', 'mode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['simulated' => 'boolean', 'estimated_cost_usd' => 'decimal:6'];
    }
}
