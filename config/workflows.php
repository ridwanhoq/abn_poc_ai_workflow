<?php

use App\Models\Content;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LocalizationTask;
use App\Models\MediaAsset;
use App\Models\MediaAssetInsight;
use App\Models\OnboardingTask;
use App\Models\RetentionCampaign;
use App\Models\ReviewerNotification;
use App\Models\Subscriber;
use App\Models\ThumbnailTask;
use App\Models\TrailerRequest;
use App\Models\User;
use App\Workflow\Nodes;

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    | While demo mode is on, no workflow can send a real email or a real outbound
    | HTTP request: the email node always uses the logging adapter and webhook
    | requests are simulated. Turn it off only in an environment with a configured
    | mail provider, and additionally enable "allow_external_side_effects" on the
    | individual workflow.
    */
    'demo_mode' => (bool) env('WORKFLOW_DEMO_MODE', true),

    // Executions older than this (in a finished state) are removed by `model:prune`.
    'retention_days' => (int) env('WORKFLOW_RETENTION_DAYS', 30),

    // Queue used for workflow jobs. Default queue keeps `php artisan queue:work` sufficient.
    'queue' => env('WORKFLOW_QUEUE', 'default'),

    'max_subworkflow_depth' => 3,

    // Delay nodes up to this many seconds sleep inline; longer delays park the
    // execution in the "waiting" state and resume it through a delayed job.
    'inline_delay_max_seconds' => 5,

    // Upper bound for any stored step payload (bytes of JSON) before truncation.
    'max_payload_bytes' => 65536,

    // Upper bound for the encrypted engine snapshot used for resume / execute-from-node.
    'max_state_bytes' => 524288,

    'uploads' => [
        // Filesystem disk for uploaded media: "local" (default) or "s3" (configure AWS_* in .env).
        'disk' => env('WORKFLOW_UPLOAD_DISK', 'local'),
        'max_kb' => (int) env('WORKFLOW_MAX_UPLOAD_KB', 51200),
        'mimetypes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska', 'video/x-msvideo'],
    ],

    // Max webhook request body; larger payloads are rejected (413) before anything is stored.
    'webhook_max_kb' => (int) env('WORKFLOW_WEBHOOK_MAX_KB', 256),

    'http' => [
        'timeout_seconds' => 10,
        // Optional allow-list of hostnames for the Send Webhook Request node. Empty = any public host.
        'allowed_hosts' => array_filter(explode(',', (string) env('WORKFLOW_HTTP_ALLOWED_HOSTS', ''))),
    ],

    'default_settings' => [
        'timeout_seconds' => 300,
        'max_node_executions' => 1000,
        'failure_policy' => 'stop',          // stop | continue
        'default_retries' => 0,
        'retry_backoff_ms' => 500,
        'allow_external_side_effects' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Node registry whitelist
    |--------------------------------------------------------------------------
    | Only classes listed here can ever be resolved from a workflow definition.
    */
    'nodes' => [
        // Triggers
        Nodes\Triggers\ManualTrigger::class,
        Nodes\Triggers\ScheduleTrigger::class,
        Nodes\Triggers\WebhookTrigger::class,
        Nodes\Triggers\DatabaseEventTrigger::class,
        Nodes\Triggers\FileUploadedTrigger::class,
        Nodes\Triggers\UserCreatedTrigger::class,
        Nodes\Triggers\UserVerificationPendingTrigger::class,
        // Logic
        Nodes\Logic\IfElseNode::class,
        Nodes\Logic\FilterNode::class,
        Nodes\Logic\SwitchNode::class,
        Nodes\Logic\DelayNode::class,
        Nodes\Logic\LoopNode::class,
        Nodes\Logic\MergeNode::class,
        Nodes\Logic\SetVariablesNode::class,
        // Data
        Nodes\Data\GetRecordNode::class,
        Nodes\Data\FindRecordsNode::class,
        Nodes\Data\CreateRecordNode::class,
        Nodes\Data\UpdateRecordNode::class,
        Nodes\Data\InsertExecutionLogNode::class,
        Nodes\Data\TransformDataNode::class,
        Nodes\Data\MapFieldsNode::class,
        // Notifications
        Nodes\Notifications\SendEmailNode::class,
        Nodes\Notifications\InAppNotificationNode::class,
        Nodes\Notifications\WebhookRequestNode::class,
        Nodes\Notifications\WriteLogNode::class,
        // AI & media
        Nodes\Ai\AiTextNode::class,
        Nodes\Ai\AiImageNode::class,
        Nodes\Ai\VideoMetadataNode::class,
        Nodes\Ai\TranscriptionNode::class,
        Nodes\Ai\FrameAnalysisNode::class,
        Nodes\Ai\ThumbnailNode::class,
        Nodes\Ai\TrailerNode::class,
        Nodes\Ai\SubtitleNode::class,
        Nodes\Ai\ModerationNode::class,
        Nodes\Ai\TranslationNode::class,
        Nodes\Ai\ChurnScoreNode::class,
        // Utilities
        Nodes\Utility\SubworkflowNode::class,
        Nodes\Utility\StopNode::class,
        Nodes\Utility\ReturnOutputNode::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data models available to data-operation nodes
    |--------------------------------------------------------------------------
    | Nodes can only touch these models and only these columns. `writable`
    | columns may be set on create, `updatable` columns on update. The `users`
    | model is read-only: workflows can never change verified_at.
    */
    'data_models' => [
        'users' => [
            'label' => 'Users',
            'model' => User::class,
            'readable' => ['id', 'name', 'email', 'verified_at', 'email_verified_at', 'is_demo', 'created_at', 'updated_at'],
            'writable' => [],
            'updatable' => [],
        ],
        'media_assets' => [
            'label' => 'Media assets',
            'model' => MediaAsset::class,
            'readable' => ['id', 'title', 'file_path', 'original_filename', 'mime_type', 'size_bytes', 'language', 'duration_seconds', 'status', 'is_demo', 'uploaded_by', 'created_at'],
            'writable' => [],
            'updatable' => ['status', 'duration_seconds'],
        ],
        'media_asset_insights' => [
            'label' => 'Media asset insights',
            'model' => MediaAssetInsight::class,
            'readable' => ['id', 'media_asset_id', 'execution_id', 'summary', 'genres', 'keywords', 'tags', 'technical_metadata', 'moderation', 'maturity_rating', 'transcript_excerpt', 'is_simulated', 'created_at'],
            'writable' => ['media_asset_id', 'execution_id', 'summary', 'genres', 'keywords', 'tags', 'technical_metadata', 'moderation', 'maturity_rating', 'transcript_excerpt', 'is_simulated'],
            'updatable' => [],
        ],
        'localization_tasks' => [
            'label' => 'Localization tasks',
            'model' => LocalizationTask::class,
            'readable' => ['id', 'media_asset_id', 'execution_id', 'task_type', 'language', 'status', 'payload', 'is_simulated', 'created_at'],
            'writable' => ['media_asset_id', 'execution_id', 'task_type', 'language', 'status', 'payload', 'is_simulated'],
            'updatable' => ['status'],
        ],
        'contents' => [
            'label' => 'OTT contents',
            'model' => Content::class,
            'readable' => ['id', 'title', 'content_type', 'synopsis', 'genre', 'language', 'source_asset_path', 'status', 'published_at', 'is_demo'],
            'writable' => [],
            'updatable' => [],
        ],
        'thumbnail_tasks' => [
            'label' => 'Thumbnail tasks',
            'model' => ThumbnailTask::class,
            'readable' => ['id', 'content_id', 'execution_id', 'prompt', 'aspect_ratio', 'resolution', 'status', 'result', 'is_simulated', 'created_at'],
            'writable' => ['content_id', 'execution_id', 'prompt', 'aspect_ratio', 'resolution', 'status', 'result', 'is_simulated'],
            'updatable' => ['status'],
        ],
        'trailer_requests' => [
            'label' => 'Trailer requests',
            'model' => TrailerRequest::class,
            'readable' => ['id', 'content_id', 'execution_id', 'storyboard', 'target_duration_seconds', 'language', 'status', 'is_simulated', 'created_at'],
            'writable' => ['content_id', 'execution_id', 'storyboard', 'target_duration_seconds', 'language', 'status', 'is_simulated'],
            'updatable' => ['status'],
        ],
        'subscribers' => [
            'label' => 'Subscribers (demo dataset)',
            'model' => Subscriber::class,
            'readable' => ['id', 'name', 'email', 'plan', 'status', 'last_active_at', 'subscription_renews_at', 'watch_minutes_30d', 'failed_payments', 'favorite_genre', 'is_demo'],
            'writable' => [],
            'updatable' => [],
        ],
        'retention_campaigns' => [
            'label' => 'Retention campaigns',
            'model' => RetentionCampaign::class,
            'readable' => ['id', 'subscriber_id', 'execution_id', 'risk_score', 'risk_level', 'channel', 'message', 'status', 'is_simulated', 'created_at'],
            'writable' => ['subscriber_id', 'execution_id', 'risk_score', 'risk_level', 'channel', 'message', 'status', 'is_simulated'],
            'updatable' => ['status'],
        ],
        'employees' => [
            'label' => 'Employees',
            'model' => Employee::class,
            'readable' => ['id', 'user_id', 'name', 'email', 'department', 'job_title', 'manager_id', 'start_date', 'status', 'is_demo'],
            'writable' => [],
            'updatable' => [],
        ],
        'onboarding_tasks' => [
            'label' => 'Onboarding tasks',
            'model' => OnboardingTask::class,
            'readable' => ['id', 'employee_id', 'execution_id', 'title', 'category', 'due_date', 'status', 'created_at'],
            'writable' => ['employee_id', 'execution_id', 'title', 'category', 'due_date', 'status'],
            'updatable' => ['status'],
        ],
        'leave_requests' => [
            'label' => 'Leave requests',
            'model' => LeaveRequest::class,
            'readable' => ['id', 'employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 'status', 'reviewer_id', 'is_demo', 'created_at'],
            'writable' => [],
            'updatable' => [],
        ],
        'reviewer_notifications' => [
            'label' => 'Reviewer notifications',
            'model' => ReviewerNotification::class,
            'readable' => ['id', 'leave_request_id', 'reviewer_employee_id', 'execution_id', 'channel', 'message', 'status', 'created_at'],
            'writable' => ['leave_request_id', 'reviewer_employee_id', 'execution_id', 'channel', 'message', 'status'],
            'updatable' => ['status'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models that emit "database event" triggers
    |--------------------------------------------------------------------------
    */
    'event_models' => [
        'users' => User::class,
        'employees' => Employee::class,
        'leave_requests' => LeaveRequest::class,
        'contents' => Content::class,
        'media_assets' => MediaAsset::class,
        'subscribers' => Subscriber::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI pricing (USD per 1M tokens) for cost estimates in AI usage reports
    |--------------------------------------------------------------------------
    | Anthropic first-party list prices; update when pricing changes. Claude Haiku 5.5's
    | rate shown is for prompts up to 100K tokens. Unknown models are estimated at $0.
    */
    'ai_pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration catalogue (seeded into workflow_integrations)
    |--------------------------------------------------------------------------
    */
    'integrations' => [
        ['key' => 'laravel_log', 'name' => 'Laravel Log', 'provider' => 'laravel', 'category' => 'core', 'status' => 'connected', 'description' => 'Writes structured entries to the application log (storage/logs).'],
        ['key' => 'database', 'name' => 'Application Database', 'provider' => 'laravel', 'category' => 'core', 'status' => 'connected', 'description' => 'Reads and writes whitelisted application tables.'],
        ['key' => 'in_app', 'name' => 'In-app Notifications', 'provider' => 'laravel', 'category' => 'notifications', 'status' => 'connected', 'description' => 'Laravel database notifications shown in the top-bar bell.'],
        ['key' => 'mock_email', 'name' => 'Email (logging adapter)', 'provider' => 'mock', 'category' => 'notifications', 'status' => 'simulated', 'description' => 'Writes every intended email to the Laravel log. No message is delivered.'],
        ['key' => 'smtp_mail', 'name' => 'Laravel Mail (production)', 'provider' => 'laravel-mail', 'category' => 'notifications', 'status' => 'not_configured', 'description' => 'Real delivery via the configured Laravel mailer. Requires demo mode off and per-workflow opt-in.'],
        ['key' => 'http', 'name' => 'Outbound HTTP', 'provider' => 'laravel-http', 'category' => 'notifications', 'status' => 'simulated', 'description' => 'Outbound webhook requests with SSRF protection. Simulated while demo mode is on.'],
        ['key' => 'mock_ai_text', 'name' => 'AI Text (mock)', 'provider' => 'mock', 'category' => 'ai', 'status' => 'simulated', 'description' => 'Deterministic, template-based text generation. No language model is called.'],
        ['key' => 'mock_ai_image', 'name' => 'AI Image (mock)', 'provider' => 'mock', 'category' => 'ai', 'status' => 'simulated', 'description' => 'Returns an image specification only. No image file is produced.'],
        ['key' => 'mock_video', 'name' => 'Video processing (mock)', 'provider' => 'mock', 'category' => 'media', 'status' => 'simulated', 'description' => 'Metadata, frame analysis, thumbnails, trailers and subtitles with deterministic sample output.'],
        ['key' => 'mock_transcription', 'name' => 'Transcription (mock)', 'provider' => 'mock', 'category' => 'media', 'status' => 'simulated', 'description' => 'Deterministic sample transcript. No audio is processed.'],
        ['key' => 'mock_moderation', 'name' => 'Content moderation (mock)', 'provider' => 'mock', 'category' => 'ai', 'status' => 'simulated', 'description' => 'Keyword rules plus deterministic sample warnings.'],
        ['key' => 'mock_translation', 'name' => 'Translation (mock)', 'provider' => 'mock', 'category' => 'ai', 'status' => 'simulated', 'description' => 'Pseudo-translation marked with the target language code.'],
        ['key' => 'anthropic', 'name' => 'Anthropic Claude', 'provider' => 'anthropic', 'category' => 'ai', 'status' => 'not_configured', 'description' => 'Real text generation for the AI Text Generation node via the Claude API. Store an API key and enable it; until then the mock adapter is used.'],
        ['key' => 'openai', 'name' => 'OpenAI / LLM provider', 'provider' => 'openai', 'category' => 'ai', 'status' => 'unavailable', 'description' => 'Adapter not implemented yet. Credentials can be stored (encrypted) for future use.'],
        ['key' => 's3', 'name' => 'Cloud storage (S3)', 'provider' => 'aws', 'category' => 'storage', 'status' => 'not_configured', 'description' => 'Store uploaded media in S3 (or an S3-compatible service): set WORKFLOW_UPLOAD_DISK=s3 and the AWS_* variables. Uses the local disk until then.'],
    ],
];
