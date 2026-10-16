<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal OTT / ERP domain tables used by the seeded templates.
 * These stand in for the production OTT CMS, subscriber and HR modules,
 * which do not exist in this repository.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- OTT -------------------------------------------------------
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('language', 10)->default('en');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 30)->default('uploaded');
            $table->boolean('is_demo')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('media_asset_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->text('summary')->nullable();
            $table->json('genres')->nullable();
            $table->json('keywords')->nullable();
            $table->json('tags')->nullable();
            $table->json('technical_metadata')->nullable();
            $table->json('moderation')->nullable();
            $table->string('maturity_rating', 20)->nullable();
            $table->text('transcript_excerpt')->nullable();
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();
        });

        Schema::create('localization_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->string('task_type', 30); // subtitle | translation | dubbing
            $table->string('language', 10);
            $table->string('status', 30)->default('pending');
            $table->json('payload')->nullable();
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('content_type', 20)->default('movie'); // movie | episode | series
            $table->text('synopsis')->nullable();
            $table->string('genre', 60)->nullable();
            $table->string('language', 10)->default('en');
            $table->string('source_asset_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('thumbnail_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->text('prompt');
            $table->string('aspect_ratio', 10)->default('16:9');
            $table->string('resolution', 20)->default('1920x1080');
            $table->string('status', 30)->default('simulated');
            $table->json('result')->nullable();
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();
        });

        Schema::create('trailer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->json('storyboard');
            $table->unsignedInteger('target_duration_seconds')->default(60);
            $table->string('language', 10)->default('en');
            $table->string('status', 30)->default('queued_simulated');
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();
        });

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('plan', 30)->default('standard');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamp('subscription_renews_at')->nullable();
            $table->unsignedInteger('watch_minutes_30d')->default(0);
            $table->unsignedTinyInteger('failed_payments')->default(0);
            $table->string('favorite_genre', 40)->nullable();
            $table->boolean('is_demo')->default(true);
            $table->timestamps();
        });

        Schema::create('retention_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->unsignedTinyInteger('risk_score');
            $table->string('risk_level', 20);
            $table->string('channel', 20)->default('email');
            $table->text('message');
            $table->string('status', 30)->default('prepared');
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();
        });

        // ---- ERP / HR --------------------------------------------------
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('department', 60);
            $table->string('job_title', 80);
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->string('title');
            $table->string('category', 40)->default('general');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('leave_type', 30)->default('annual');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('reviewer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('workflow_executions')->nullOnDelete();
            $table->string('channel', 20)->default('email');
            $table->text('message');
            $table->string('status', 30)->default('logged');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'reviewer_notifications', 'leave_requests', 'onboarding_tasks', 'employees',
            'retention_campaigns', 'subscribers', 'trailer_requests', 'thumbnail_tasks', 'contents',
            'localization_tasks', 'media_asset_insights', 'media_assets',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
