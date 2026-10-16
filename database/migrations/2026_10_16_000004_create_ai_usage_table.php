<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per AI call made by a workflow node. Kept when executions are pruned
        // (execution_id is nulled) so cost history outlives execution retention.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key');
            $table->string('task', 40)->nullable();
            $table->string('provider', 40);
            $table->string('model', 80);
            $table->boolean('simulated')->default(true);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->string('mode', 20)->default('production');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
