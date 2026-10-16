<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('status', 20)->default('draft')->index(); // draft | active | inactive
            $table->json('definition');
            $table->string('trigger_type', 60)->nullable()->index();
            $table->json('settings')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->text('webhook_secret')->nullable(); // encrypted cast
            $table->foreignId('template_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->json('settings')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workflow_id', 'version']);
        });

        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('workflow_version')->nullable();
            $table->string('trigger_type', 60)->index();
            $table->json('trigger_data')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->string('mode', 20)->default('production'); // production | test
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('result')->nullable();
            $table->longText('state')->nullable(); // encrypted engine snapshot (resume / execute-from-node)
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->foreignId('parent_execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->string('start_node_key')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->unsignedInteger('node_executions')->default(0);
            $table->timestamps();
            $table->unique(['workflow_id', 'idempotency_key']);
            $table->index(['workflow_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('workflow_execution_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('workflow_executions')->cascadeOnDelete();
            $table->string('node_key');
            $table->string('node_type', 80);
            $table->string('node_name')->nullable();
            $table->string('status', 20); // running | success | failed | skipped | cancelled
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->unsignedInteger('iteration')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['execution_id', 'sequence']);
            $table->index(['execution_id', 'node_key']);
        });

        Schema::create('workflow_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('workflow_executions')->cascadeOnDelete();
            $table->string('node_key')->nullable();
            $table->string('level', 20)->default('info');
            $table->string('message', 1000);
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['execution_id', 'level']);
        });

        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->index();
            $table->json('definition');
            $table->json('settings')->nullable();
            $table->json('required_integrations')->nullable();
            $table->json('tags')->nullable();
            $table->string('icon', 60)->default('workflow');
            $table->string('color', 20)->default('#6161ff');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('import_count')->default(0);
            $table->timestamps();
        });

        Schema::create('workflow_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('node_key');
            $table->string('cron_expression', 120);
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['workflow_id', 'node_key']);
        });

        Schema::create('workflow_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('provider');
            $table->string('category', 40)->default('general');
            $table->string('description', 500)->nullable();
            $table->json('configuration')->nullable(); // non-secret settings only
            $table->text('credentials')->nullable(); // encrypted:array
            $table->string('status', 20)->default('not_configured'); // connected | not_configured | simulated | unavailable | error
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->text('value')->nullable(); // encrypted when is_secret
            $table->boolean('is_secret')->default(false);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('workflow_variables');
        Schema::dropIfExists('workflow_integrations');
        Schema::dropIfExists('workflow_schedules');
        Schema::dropIfExists('workflow_templates');
        Schema::dropIfExists('workflow_execution_logs');
        Schema::dropIfExists('workflow_execution_steps');
        Schema::dropIfExists('workflow_executions');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
