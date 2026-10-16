<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use App\Workflow\NodeRegistry;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $category
 * @property WorkflowStatus $status
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $definition
 * @property string|null $trigger_type
 * @property array<string, mixed>|null $settings
 * @property int $version
 * @property string|null $webhook_token
 * @property string|null $webhook_secret
 * @property int|null $template_id
 * @property int|null $workspace_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $executions_count
 * @property int|null $successful_count
 * @property-read User|null $creator
 * @property-read WorkflowExecution|null $latestExecution
 */
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'category', 'status', 'definition', 'trigger_type', 'settings',
        'version', 'webhook_token', 'webhook_secret', 'template_id', 'created_by', 'updated_by',
        'workspace_id', 'activated_at',
    ];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'definition' => 'array',
            'settings' => 'array',
            'webhook_secret' => 'encrypted',
            'activated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The trigger node is the single source of truth for trigger_type.
        static::saving(function (self $workflow) {
            $node = $workflow->triggerNode();
            $workflow->trigger_type = $node ? Str::after($node['type'], 'trigger.') : null;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    /**
     * @return HasMany<WorkflowExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class);
    }

    /**
     * @return HasOne<WorkflowExecution, $this>
     */
    public function latestExecution(): HasOne
    {
        return $this->hasOne(WorkflowExecution::class)->latestOfMany();
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /**
     * @return HasMany<WorkflowSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(WorkflowSchedule::class);
    }

    public function isActive(): bool
    {
        return $this->status === WorkflowStatus::Active;
    }

    /**
     * Effective settings merged over platform defaults.
     *
     * @return array<string, mixed>
     */
    public function effectiveSettings(): array
    {
        return array_merge(config('workflows.default_settings'), $this->settings ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function triggerNode(): ?array
    {
        $registry = app(NodeRegistry::class);

        foreach ($this->definition['nodes'] ?? [] as $node) {
            if ($registry->has($node['type'] ?? '') && $registry->get($node['type'])->category() === 'trigger') {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  Builder<Workflow>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $ids = $user->accessibleWorkspaceIds();

        if ($ids !== null) {
            // Workflows without a workspace (legacy data) are visible to Super Admins only.
            $query->whereIn('workspace_id', $ids);
        }
    }
}
