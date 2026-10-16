<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $verified_at Profile verification (used by the unverified-user reminder template)
 * @property string $password
 * @property int|null $role_id
 * @property int|null $current_workspace_id
 * @property bool $is_demo
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role|null $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'password' => 'hashed',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === Role::SUPER_ADMIN;
    }

    public function hasRole(string ...$slugs): bool
    {
        return in_array($this->role?->slug, $slugs, true);
    }

    /**
     * @return list<string>
     */
    public function permissionSlugs(): array
    {
        return $this->permissionCache ??= $this->role
            ? array_values(array_map('strval', $this->role->permissions()->pluck('slug')->all()))
            : [];
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isSuperAdmin() || in_array($permission, $this->permissionSlugs(), true);
    }

    public function belongsToWorkspace(?int $workspaceId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($workspaceId === null) {
            return false; // workspace-less records are Super Admin only
        }

        return $this->workspaces()->whereKey($workspaceId)->exists();
    }

    /**
     * IDs of workspaces this user can see; null means "all" (super admin).
     *
     * @return list<int>|null
     */
    public function accessibleWorkspaceIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return array_values(array_map('intval', $this->workspaces()->pluck('workspaces.id')->all()));
    }
}
