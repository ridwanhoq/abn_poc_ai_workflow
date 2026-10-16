<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowExecutionLog extends Model
{
    protected $fillable = ['execution_id', 'node_key', 'level', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
