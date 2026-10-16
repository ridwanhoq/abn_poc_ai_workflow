<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workflow_id
 * @property int $version
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $definition
 * @property array<string, mixed>|null $settings
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read User|null $creator
 */
class WorkflowVersion extends Model
{
    protected $fillable = ['workflow_id', 'version', 'definition', 'settings', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'settings' => 'array'];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
