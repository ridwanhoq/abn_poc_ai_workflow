<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int|null $workspace_id
 * @property string $key
 * @property string|null $value
 * @property bool $is_secret
 * @property string|null $description
 * @property Carbon|null $updated_at
 */
class WorkflowVariable extends Model
{
    protected $fillable = ['workspace_id', 'key', 'value', 'is_secret', 'description', 'created_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    /**
     * Secret values are encrypted at rest; plain values are stored as-is.
     */
    /**
     * @return Attribute<string|null, string|null>
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes) => $value !== null && ($attributes['is_secret'] ?? false)
                ? Crypt::decryptString($value)
                : $value,
            set: fn (?string $value, array $attributes) => $value !== null && ($attributes['is_secret'] ?? $this->is_secret)
                ? Crypt::encryptString($value)
                : $value,
        );
    }
}
