<?php

namespace App\Workflow\Templates;

use App\Workflow\NodeRegistry;

/**
 * Complete definitions of the six default templates. Sample payloads point at
 * the demo records created by DemoDataSeeder, so every template can be imported
 * and test-run immediately without external credentials.
 */
class TemplateLibrary
{
    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @param  array<string, int|null>  $ids  demo record IDs: media_asset, content, employee, leave_request
     * @return list<array<string, mixed>>
     */
    public function all(array $ids = []): array
    {
        $templates = [
            $this->videoMetadata($ids['media_asset'] ?? 1),
            $this->thumbnailTrailer($ids['content'] ?? 1),
            $this->churnPrevention(),
            $this->unverifiedUsers(),
            $this->employeeOnboarding($ids['employee'] ?? 1),
            $this->leaveRequest($ids['leave_request'] ?? 1),
        ];

        return array_map(fn ($t) => $t + ['required_integrations' => $this->integrationsFor($t['definition'])], $templates);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function integrationsFor(array $definition): array
    {
        $keys = [];
        foreach ($definition['nodes'] as $node) {
            if ($this->registry->has($node['type']) && ($key = $this->registry->get($node['type'])->integration())) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function node(string $key, string $type, string $name, int $x, int $y, array $config = [], array $settings = []): array
    {
        return array_filter([
            'key' => $key,
            'type' => $type,
            'name' => $name,
            'position' => ['x' => $x, 'y' => $y],
            'config' => $config,
            'settings' => $settings ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, string>
     */
    private function edge(string $source, string $target, string $handle = 'main'): array
    {
        return ['id' => "e_{$source}_{$handle}_{$target}", 'source' => $source, 'sourceHandle' => $handle, 'target' => $target];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private function chain(array $keys): array
    {
        $edges = [];
        for ($i = 0; $i < count($keys) - 1; $i++) {
            $edges[] = $this->edge($keys[$i], $keys[$i + 1]);
        }

        return $edges;
    }

    // ------------------------------------------------------------------
    // Template 1 — OTT: Automated Video Metadata and Localization
    // ------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function videoMetadata(int $assetId): array
    {
        $asset = '{{ nodes.load_asset.output.record';
        $nodes = [
            $this->node('asset_uploaded', 'trigger.file_uploaded', 'Video uploaded', 0, 200, [
                'mime_prefix' => 'video/',
                'sample_payload' => ['asset_id' => $assetId, 'title' => 'The Last Lighthouse', 'file_path' => 'media/demo/the-last-lighthouse.mp4', 'language' => 'en'],
            ]),
            $this->node('load_asset', 'data.get_record', 'Load asset record', 280, 200, ['model' => 'media_assets', 'id' => '{{ trigger.asset_id }}']),
            $this->node('extract_metadata', 'media.video_metadata', 'Extract technical metadata', 560, 200, ['asset_id' => '{{ trigger.asset_id }}']),
            $this->node('transcribe', 'media.transcription', 'Transcribe audio', 840, 200, ['asset_id' => '{{ trigger.asset_id }}', 'language' => "{$asset}.language }}"]),
            $this->node('analyze_frames', 'media.frame_analysis', 'Analyze key frames', 1120, 200, ['asset_id' => '{{ trigger.asset_id }}', 'frame_count' => 5]),
            $this->node('generate_metadata', 'ai.text', 'Generate catalogue metadata', 1400, 200, [
                'task' => 'media_metadata',
                'prompt' => "Write an OTT catalogue summary, genres, keywords and tags for \"{$asset}.title }}\" using the transcript and frame labels.",
                'context' => [
                    'title' => "{$asset}.title }}",
                    'transcript' => '{{ nodes.transcribe.output.text }}',
                    'objects' => '{{ nodes.analyze_frames.output.objects }}',
                ],
            ]),
            $this->node('moderate', 'ai.moderation', 'Moderate & rate maturity', 1680, 200, [
                'text' => '{{ nodes.transcribe.output.text }} {{ nodes.generate_metadata.output.summary }}',
                'asset_id' => '{{ trigger.asset_id }}',
            ]),
            $this->node('plan_subtitles', 'media.subtitles', 'Plan subtitles & translations', 1960, 200, [
                'asset_id' => '{{ trigger.asset_id }}',
                'source_language' => "{$asset}.language }}",
                'target_languages' => ['es', 'fr', 'ar'],
            ]),
            $this->node('create_localization_tasks', 'data.create_record', 'Create localization tasks', 2240, 200, [
                'model' => 'localization_tasks',
                'mode' => 'many',
                'items' => '{{ nodes.plan_subtitles.output.tracks }}',
                'fields' => [
                    'media_asset_id' => '{{ trigger.asset_id }}',
                    'execution_id' => '{{ execution.id }}',
                    'task_type' => '{{ item.task_type }}',
                    'language' => '{{ item.language }}',
                    'status' => 'pending',
                    'payload' => '{{ item }}',
                    'is_simulated' => true,
                ],
            ]),
            $this->node('store_insights', 'data.create_record', 'Store generated metadata', 2520, 200, [
                'model' => 'media_asset_insights',
                'fields' => [
                    'media_asset_id' => '{{ trigger.asset_id }}',
                    'execution_id' => '{{ execution.id }}',
                    'summary' => '{{ nodes.generate_metadata.output.summary }}',
                    'genres' => '{{ nodes.generate_metadata.output.genres }}',
                    'keywords' => '{{ nodes.generate_metadata.output.keywords }}',
                    'tags' => '{{ nodes.generate_metadata.output.tags }}',
                    'technical_metadata' => '{{ nodes.extract_metadata.output }}',
                    'moderation' => '{{ nodes.moderate.output }}',
                    'maturity_rating' => '{{ nodes.moderate.output.maturity_rating }}',
                    'transcript_excerpt' => '{{ nodes.transcribe.output.text | truncate(300) }}',
                    'is_simulated' => true,
                ],
            ]),
            $this->node('mark_ready', 'data.update_record', 'Mark asset metadata-ready', 2800, 200, [
                'model' => 'media_assets',
                'id' => '{{ trigger.asset_id }}',
                'fields' => ['status' => 'metadata_ready'],
            ]),
            $this->node('log_summary', 'notify.log', 'Log processing summary', 3080, 200, [
                'message' => "Asset #{{ trigger.asset_id }} \"{$asset}.title }}\" processed (SIMULATED AI): rating {{ nodes.moderate.output.maturity_rating }}, {{ nodes.create_localization_tasks.output.created_count }} localization tasks.",
            ]),
            $this->node('return_summary', 'utility.return', 'Return processing summary', 3360, 200, [
                'output' => [
                    'asset_id' => '{{ trigger.asset_id }}',
                    'title' => "{$asset}.title }}",
                    'insight_id' => '{{ nodes.store_insights.output.record.id }}',
                    'genres' => '{{ nodes.generate_metadata.output.genres }}',
                    'maturity_rating' => '{{ nodes.moderate.output.maturity_rating }}',
                    'content_warnings' => '{{ nodes.moderate.output.content_warnings | length }}',
                    'localization_tasks_created' => '{{ nodes.create_localization_tasks.output.created_count }}',
                    'simulated' => true,
                    'notice' => 'Transcription, frame analysis, metadata generation, moderation and subtitle planning are SIMULATED by mock adapters. No real transcription, visual recognition, dubbing or lip-sync took place.',
                ],
            ]),
        ];

        // Two-row layout so the 13-step pipeline stays readable.
        foreach ($nodes as $i => &$node) {
            $node['position'] = $i < 7 ? ['x' => $i * 300, 'y' => 60] : ['x' => (12 - $i) * 300 + 300, 'y' => 320];
        }
        unset($node);

        return [
            'key' => 'ott_video_metadata_localization',
            'name' => 'Automated Video Metadata & Localization',
            'category' => 'OTT / AI Media Processing',
            'description' => 'Process a newly uploaded video asset: extract metadata, transcribe, analyze frames, generate catalogue metadata and moderation results, and create subtitle/translation tasks.',
            'icon' => 'film',
            'color' => '#6161ff',
            'tags' => ['OTT', 'AI', 'Localization', 'Simulated'],
            'settings' => ['timeout_seconds' => 300, 'max_node_executions' => 200],
            'definition' => ['nodes' => $nodes, 'edges' => $this->chain(array_column($nodes, 'key'))],
        ];
    }

    // ------------------------------------------------------------------
    // Template 2 — OTT: AI Thumbnail and Trailer Generation
    // ------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function thumbnailTrailer(int $contentId): array
    {
        $content = '{{ nodes.load_content.output.record';
        $nodes = [
            $this->node('content_published', 'trigger.database_event', 'Content published', 0, 200, [
                'model' => 'contents',
                'event' => 'updated',
                'match' => ['status' => 'published'],
                'sample_payload' => ['model' => 'contents', 'event' => 'updated', 'id' => $contentId, 'record' => ['id' => $contentId, 'status' => 'published']],
            ]),
            $this->node('load_content', 'data.get_record', 'Load content', 280, 200, ['model' => 'contents', 'id' => '{{ trigger.id }}']),
            $this->node('validate_content', 'logic.if', 'Has title & source asset?', 560, 200, [
                'conditions' => [
                    ['left' => "{$content}.title }}", 'operator' => 'is_not_empty'],
                    ['left' => "{$content}.source_asset_path }}", 'operator' => 'is_not_empty'],
                ],
                'combinator' => 'all',
            ]),
            $this->node('log_invalid', 'data.execution_log', 'Record validation failure', 840, 400, [
                'level' => 'warning',
                'message' => 'Content #{{ trigger.id }} is missing a title or source asset; promotional assets were not prepared.',
            ]),
            $this->node('stop_invalid', 'utility.stop', 'Stop: invalid content', 1120, 400, ['status' => 'failed', 'message' => 'Content is missing a title or source asset.']),
            $this->node('thumbnail_prompt', 'ai.text', 'Write thumbnail prompt', 840, 120, [
                'task' => 'thumbnail_prompt',
                'prompt' => "Thumbnail prompt for {$content}.title }}",
                'context' => ['title' => "{$content}.title }}", 'genre' => "{$content}.genre }}", 'synopsis' => "{$content}.synopsis }}"],
            ]),
            $this->node('generate_thumbnail', 'ai.image', 'Generate thumbnail (simulated)', 1120, 120, [
                'prompt' => '{{ nodes.thumbnail_prompt.output.prompt }}',
                'aspect_ratio' => '16:9',
                'resolution' => '1920x1080',
                'style' => '{{ nodes.thumbnail_prompt.output.style }}',
            ]),
            $this->node('create_thumbnail_task', 'data.create_record', 'Create thumbnail task', 1400, 120, [
                'model' => 'thumbnail_tasks',
                'fields' => [
                    'content_id' => '{{ trigger.id }}',
                    'execution_id' => '{{ execution.id }}',
                    'prompt' => '{{ nodes.thumbnail_prompt.output.prompt }}',
                    'aspect_ratio' => '{{ nodes.generate_thumbnail.output.aspect_ratio }}',
                    'resolution' => '{{ nodes.generate_thumbnail.output.resolution }}',
                    'status' => 'simulated',
                    'result' => '{{ nodes.generate_thumbnail.output }}',
                    'is_simulated' => true,
                ],
            ]),
            $this->node('storyboard', 'media.trailer', 'Generate trailer storyboard', 1680, 120, [
                'content' => "{$content} }}",
                'target_duration' => 60,
                'language' => "{$content}.language }}",
                'aspect_ratio' => '16:9',
            ]),
            $this->node('create_trailer_request', 'data.create_record', 'Create trailer request', 1960, 120, [
                'model' => 'trailer_requests',
                'fields' => [
                    'content_id' => '{{ trigger.id }}',
                    'execution_id' => '{{ execution.id }}',
                    'storyboard' => '{{ nodes.storyboard.output.scenes }}',
                    'target_duration_seconds' => '{{ nodes.storyboard.output.target_duration_seconds }}',
                    'language' => '{{ nodes.storyboard.output.language }}',
                    'status' => 'queued_simulated',
                    'is_simulated' => true,
                ],
            ]),
            $this->node('log_summary', 'notify.log', 'Log execution summary', 2240, 120, [
                'message' => "Promo assets prepared (SIMULATED) for \"{$content}.title }}\": thumbnail task #{{ nodes.create_thumbnail_task.output.record.id }}, trailer request #{{ nodes.create_trailer_request.output.record.id }} with {{ nodes.storyboard.output.scenes | length }} scenes.",
            ]),
            $this->node('return_summary', 'utility.return', 'Return summary', 2520, 120, [
                'output' => [
                    'content_id' => '{{ trigger.id }}',
                    'title' => "{$content}.title }}",
                    'thumbnail_task_id' => '{{ nodes.create_thumbnail_task.output.record.id }}',
                    'trailer_request_id' => '{{ nodes.create_trailer_request.output.record.id }}',
                    'scenes' => '{{ nodes.storyboard.output.scenes | length }}',
                    'image_generated' => false,
                    'video_rendered' => false,
                    'notice' => 'Thumbnail and trailer outputs are SIMULATED specifications; no image or video file was produced.',
                ],
            ]),
        ];

        $edges = [
            $this->edge('content_published', 'load_content'),
            $this->edge('load_content', 'validate_content'),
            $this->edge('validate_content', 'thumbnail_prompt', 'true'),
            $this->edge('validate_content', 'log_invalid', 'false'),
            $this->edge('log_invalid', 'stop_invalid'),
            ...$this->chain(['thumbnail_prompt', 'generate_thumbnail', 'create_thumbnail_task', 'storyboard', 'create_trailer_request', 'log_summary', 'return_summary']),
        ];

        return [
            'key' => 'ott_thumbnail_trailer',
            'name' => 'AI Thumbnail & Trailer Generation',
            'category' => 'OTT / Content Generation',
            'description' => 'Prepare promotional assets when a movie or episode is published: thumbnail prompt and spec, trailer storyboard, and tracked generation tasks.',
            'icon' => 'clapperboard',
            'color' => '#ff7575',
            'tags' => ['OTT', 'AI', 'Promo', 'Simulated'],
            'settings' => ['timeout_seconds' => 300],
            'definition' => ['nodes' => $nodes, 'edges' => $edges],
        ];
    }

    // ------------------------------------------------------------------
    // Template 3 — OTT: Intelligent Churn Prevention and Win-Back
    // ------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function churnPrevention(): array
    {
        $nodes = [
            $this->node('daily_check', 'trigger.schedule', 'Every morning', 0, 200, ['cron' => '0 8 * * *', 'timezone' => 'UTC', 'sample_payload' => ['reason' => 'manual test']]),
            $this->node('thresholds', 'logic.set_variables', 'Set thresholds', 280, 200, ['variables' => ['inactivity_days' => 14, 'risk_threshold' => 60]]),
            $this->node('find_inactive', 'data.find_records', 'Find inactive subscribers', 560, 200, [
                'model' => 'subscribers',
                'filters' => [
                    ['column' => 'status', 'operator' => '=', 'value' => 'active'],
                    ['column' => 'last_active_at', 'operator' => 'older_than_days', 'value' => '{{ variables.inactivity_days }}'],
                ],
                'mode' => 'cursor',
                'batch_size' => 50,
            ]),
            $this->node('each_subscriber', 'logic.loop', 'For each subscriber', 840, 200, ['items' => '{{ nodes.find_inactive.output }}', 'max_iterations' => 5000]),
            $this->node('score', 'ai.churn_score', 'Rule-based churn score', 1120, 80, ['subscriber' => '{{ item }}']),
            $this->node('is_high_risk', 'logic.if', 'Score ≥ threshold?', 1400, 80, [
                'conditions' => [['left' => '{{ nodes.score.output.score }}', 'operator' => 'gte', 'right' => '{{ variables.risk_threshold }}']],
            ]),
            $this->node('write_message', 'ai.text', 'Personalized win-back copy', 1680, 0, [
                'task' => 'retention_message',
                'prompt' => 'Write a short, friendly win-back message for {{ item.name }}.',
                'context' => ['name' => '{{ item.name }}', 'favorite_genre' => '{{ item.favorite_genre }}', 'risk_level' => '{{ nodes.score.output.level }}'],
            ]),
            $this->node('create_campaign', 'data.create_record', 'Create campaign record', 1960, 0, [
                'model' => 'retention_campaigns',
                'fields' => [
                    'subscriber_id' => '{{ item.id }}',
                    'execution_id' => '{{ execution.id }}',
                    'risk_score' => '{{ nodes.score.output.score }}',
                    'risk_level' => '{{ nodes.score.output.level }}',
                    'channel' => 'email',
                    'message' => '{{ nodes.write_message.output.text }}',
                    'status' => 'prepared',
                    'is_simulated' => true,
                ],
            ]),
            $this->node('log_intent', 'notify.log', 'Log intended notification', 2240, 0, [
                'message' => 'Win-back message prepared for subscriber #{{ item.id }} (risk {{ nodes.score.output.score }}/100) — not sent.',
            ]),
            $this->node('summary', 'utility.return', 'Return campaign summary', 1120, 380, [
                'output' => [
                    'subscribers_evaluated' => '{{ nodes.each_subscriber.output.iterations }}',
                    'campaigns_prepared' => '{{ nodes.create_campaign.success_count | default(0) }}',
                    'inactivity_days' => '{{ variables.inactivity_days }}',
                    'risk_threshold' => '{{ variables.risk_threshold }}',
                    'scoring_method' => 'rule_based_v1 (transparent rules, not a trained model)',
                    'notice' => 'Messages are generated by the mock AI adapter and only logged/stored; no push or email notification was sent.',
                ],
            ]),
        ];

        $edges = [
            ...$this->chain(['daily_check', 'thresholds', 'find_inactive', 'each_subscriber']),
            $this->edge('each_subscriber', 'score', 'each'),
            $this->edge('score', 'is_high_risk'),
            $this->edge('is_high_risk', 'write_message', 'true'),
            $this->edge('write_message', 'create_campaign'),
            $this->edge('create_campaign', 'log_intent'),
            $this->edge('each_subscriber', 'summary', 'done'),
        ];

        return [
            'key' => 'ott_churn_winback',
            'name' => 'Intelligent Churn Prevention & Win-Back',
            'category' => 'OTT / Subscriber Retention',
            'description' => 'Find inactive subscribers, score churn risk with transparent rules and prepare personalized win-back campaigns for high-risk subscribers.',
            'icon' => 'heart-handshake',
            'color' => '#00c875',
            'tags' => ['OTT', 'Retention', 'Rule-based'],
            'settings' => ['timeout_seconds' => 900, 'max_node_executions' => 20000, 'failure_policy' => 'stop'],
            'definition' => ['nodes' => $nodes, 'edges' => $edges],
        ];
    }

    // ------------------------------------------------------------------
    // Template 4 — ERP: Send Email to Users Who Have Not Verified Their Profiles
    // ------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function unverifiedUsers(): array
    {
        $nodes = [
            $this->node('daily_schedule', 'trigger.schedule', 'Daily at 09:00', 0, 200, ['cron' => '0 9 * * *', 'timezone' => 'UTC', 'sample_payload' => ['reason' => 'manual test']]),
            // Exact condition: users.verified_at IS NULL  →  User::query()->whereNull('verified_at')
            $this->node('find_unverified', 'data.find_records', 'Find users with verified_at = NULL', 280, 200, [
                'model' => 'users',
                'filters' => [['column' => 'verified_at', 'operator' => 'is_null']],
                'mode' => 'cursor',
                'batch_size' => 100,
            ]),
            $this->node('each_user', 'logic.loop', 'For each unverified user', 560, 200, ['items' => '{{ nodes.find_unverified.output }}', 'max_iterations' => 100000]),
            $this->node('has_valid_email', 'logic.if', 'Valid email address?', 840, 80, [
                'conditions' => [['left' => '{{ item.email }}', 'operator' => 'is_email']],
            ]),
            $this->node('send_reminder', 'notify.email', 'Send verification reminder', 1120, 0, [
                'to' => '{{ item.email }}',
                'subject' => 'Please verify your profile',
                'body' => "Hi {{ item.name }},\n\nYour profile is not verified yet. Please sign in and complete verification so we can keep your account secure.\n\nThank you.",
                'log_message' => 'Email reminder prepared for {{ item.email }} (User ID: {{ item.id }}).',
            ], ['on_error' => 'continue', 'retries' => 1, 'retry_backoff_ms' => 200]),
            $this->node('record_sent', 'data.execution_log', 'Record reminder result', 1400, 0, [
                'level' => 'info',
                'message' => 'Reminder logged for user #{{ item.id }}.',
                'context' => ['user_id' => '{{ item.id }}', 'adapter' => '{{ nodes.send_reminder.output.adapter }}'],
            ]),
            $this->node('record_skipped', 'data.execution_log', 'Record skipped user', 1120, 260, [
                'level' => 'warning',
                'message' => 'Skipped user #{{ item.id }}: invalid email address.',
                'context' => ['user_id' => '{{ item.id }}'],
            ]),
            $this->node('summary', 'utility.return', 'Return reminder summary', 560, 460, [
                'output' => [
                    'users_found' => '{{ nodes.find_unverified.output.total_count }}',
                    'users_processed' => '{{ nodes.each_user.output.iterations }}',
                    'reminders_logged' => '{{ nodes.send_reminder.success_count | default(0) }}',
                    'skipped_invalid_email' => '{{ nodes.record_skipped.success_count | default(0) }}',
                    'failures' => '{{ nodes.send_reminder.error_count | default(0) }}',
                    'batches' => '{{ nodes.each_user.output.batches }}',
                    'delivery' => 'Logged only — demo mode uses the logging email adapter.',
                ],
            ]),
        ];

        $edges = [
            ...$this->chain(['daily_schedule', 'find_unverified', 'each_user']),
            $this->edge('each_user', 'has_valid_email', 'each'),
            $this->edge('has_valid_email', 'send_reminder', 'true'),
            $this->edge('send_reminder', 'record_sent'),
            $this->edge('has_valid_email', 'record_skipped', 'false'),
            $this->edge('each_user', 'summary', 'done'),
        ];

        return [
            'key' => 'erp_unverified_user_reminder',
            'name' => 'Unverified User Reminder',
            'category' => 'ERP / User Management',
            'description' => 'Find registered users whose profile is not verified (users.verified_at IS NULL) and prepare verification reminder emails in batches. Demo mode logs every email instead of sending it.',
            'icon' => 'mail-warning',
            'color' => '#fdab3d',
            'tags' => ['ERP', 'Users', 'Email'],
            'settings' => ['timeout_seconds' => 1800, 'max_node_executions' => 100000],
            'definition' => ['nodes' => $nodes, 'edges' => $edges],
        ];
    }

    // ------------------------------------------------------------------
    // Template 5 — ERP: AI-Driven Employee Onboarding and HR Support
    // ------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function employeeOnboarding(int $employeeId): array
    {
        $employee = '{{ nodes.load_employee.output.record';
        $common = [
            ['title' => 'Sign employment agreement', 'category' => 'hr', 'due_in_days' => 0],
            ['title' => 'Provision corporate email account (simulated)', 'category' => 'it', 'due_in_days' => 0],
            ['title' => 'Complete security awareness training', 'category' => 'training', 'due_in_days' => 7],
        ];

        $nodes = [
            $this->node('employee_created', 'trigger.database_event', 'Employee created', 0, 240, [
                'model' => 'employees',
                'event' => 'created',
                'sample_payload' => ['model' => 'employees', 'event' => 'created', 'id' => $employeeId, 'record' => ['id' => $employeeId]],
            ]),
            $this->node('load_employee', 'data.get_record', 'Load employee', 280, 240, ['model' => 'employees', 'id' => '{{ trigger.id }}']),
            $this->node('route_department', 'logic.switch', 'Department?', 560, 240, [
                'value' => "{$employee}.department }}",
                'cases' => [['value' => 'Engineering'], ['value' => 'Sales']],
            ]),
            $this->node('engineering_tasks', 'logic.set_variables', 'Engineering setup', 840, 80, ['variables' => ['setup_tasks' => [
                ...$common,
                ['title' => 'Request GitHub organisation access (simulated)', 'category' => 'it', 'due_in_days' => 1],
                ['title' => 'Set up local development environment', 'category' => 'engineering', 'due_in_days' => 3],
            ]]]),
            $this->node('sales_tasks', 'logic.set_variables', 'Sales setup', 840, 240, ['variables' => ['setup_tasks' => [
                ...$common,
                ['title' => 'CRM account and pipeline training', 'category' => 'sales', 'due_in_days' => 2],
                ['title' => 'Shadow three discovery calls', 'category' => 'sales', 'due_in_days' => 10],
            ]]]),
            $this->node('default_tasks', 'logic.set_variables', 'General setup', 840, 400, ['variables' => ['setup_tasks' => [
                ...$common,
                ['title' => 'Meet your team and key stakeholders', 'category' => 'general', 'due_in_days' => 5],
            ]]]),
            $this->node('merge_paths', 'logic.merge', 'Merge department paths', 1120, 240, ['mode' => 'combine']),
            $this->node('create_tasks', 'data.create_record', 'Create onboarding tasks', 1400, 240, [
                'model' => 'onboarding_tasks',
                'mode' => 'many',
                'items' => '{{ variables.setup_tasks }}',
                'fields' => [
                    'employee_id' => '{{ trigger.id }}',
                    'execution_id' => '{{ execution.id }}',
                    'title' => '{{ item.title }}',
                    'category' => '{{ item.category }}',
                    'due_date' => "{$employee}.start_date | default(now) | add_days(item.due_in_days) | date('Y-m-d') }}",
                    'status' => 'pending',
                ],
            ]),
            $this->node('checklist', 'ai.text', 'Generate role checklist', 1680, 240, [
                'task' => 'onboarding_checklist',
                'prompt' => "Create an onboarding checklist for {$employee}.name }}, {$employee}.job_title }} in {$employee}.department }}.",
                'context' => ['name' => "{$employee}.name }}", 'department' => "{$employee}.department }}", 'job_title' => "{$employee}.job_title }}"],
            ]),
            $this->node('welcome_email', 'notify.email', 'HR welcome email (logged)', 1960, 240, [
                'to' => "{$employee}.email }}",
                'subject' => "Welcome to the team, {$employee}.name }}!",
                'body' => '{{ nodes.checklist.output.text }}',
                'log_message' => "HR welcome email prepared for {$employee}.email }} (Employee ID: {{ trigger.id }}).",
            ]),
            $this->node('record_result', 'data.execution_log', 'Record checklist & task IDs', 2240, 240, [
                'message' => 'Onboarding prepared for employee #{{ trigger.id }}: {{ nodes.create_tasks.output.created_count }} tasks.',
                'context' => ['task_ids' => '{{ nodes.create_tasks.output.ids }}', 'checklist' => '{{ nodes.checklist.output.checklist }}'],
            ]),
            $this->node('summary', 'utility.return', 'Return completion summary', 2520, 240, [
                'output' => [
                    'employee_id' => '{{ trigger.id }}',
                    'name' => "{$employee}.name }}",
                    'department' => "{$employee}.department }}",
                    'task_ids' => '{{ nodes.create_tasks.output.ids }}',
                    'checklist' => '{{ nodes.checklist.output.checklist }}',
                    'account_provisioning' => 'simulated — no accounts were created',
                    'welcome_email' => 'logged only (demo mode)',
                ],
            ]),
        ];

        $edges = [
            ...$this->chain(['employee_created', 'load_employee', 'route_department']),
            $this->edge('route_department', 'engineering_tasks', 'case_0'),
            $this->edge('route_department', 'sales_tasks', 'case_1'),
            $this->edge('route_department', 'default_tasks', 'default'),
            $this->edge('engineering_tasks', 'merge_paths'),
            $this->edge('sales_tasks', 'merge_paths'),
            $this->edge('default_tasks', 'merge_paths'),
            ...$this->chain(['merge_paths', 'create_tasks', 'checklist', 'welcome_email', 'record_result', 'summary']),
        ];

        return [
            'key' => 'erp_employee_onboarding',
            'name' => 'AI-Driven Employee Onboarding',
            'category' => 'ERP / HR Automation',
            'description' => 'When a new employee joins, create department-specific onboarding tasks, generate a role checklist and prepare the HR welcome email.',
            'icon' => 'user-round-plus',
            'color' => '#a25ddc',
            'tags' => ['ERP', 'HR', 'Onboarding'],
            'settings' => ['timeout_seconds' => 300],
            'definition' => ['nodes' => $nodes, 'edges' => $edges],
        ];
    }

    // ------------------------------------------------------------------
    // Template 6 — ERP: Employee Leave Request Notification
    // ------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function leaveRequest(int $leaveRequestId): array
    {
        $request = '{{ nodes.load_request.output.record';
        $nodes = [
            $this->node('leave_created', 'trigger.database_event', 'Leave request created', 0, 200, [
                'model' => 'leave_requests',
                'event' => 'created',
                'sample_payload' => ['model' => 'leave_requests', 'event' => 'created', 'id' => $leaveRequestId, 'record' => ['id' => $leaveRequestId]],
            ]),
            $this->node('load_request', 'data.get_record', 'Load leave request', 280, 200, ['model' => 'leave_requests', 'id' => '{{ trigger.id }}']),
            $this->node('load_employee', 'data.get_record', 'Load employee', 560, 200, ['model' => 'employees', 'id' => "{$request}.employee_id }}", 'fail_if_missing' => false]),
            $this->node('validate_request', 'logic.if', 'Valid request?', 840, 200, [
                'conditions' => [
                    ['left' => '{{ nodes.load_employee.output.found }}', 'operator' => 'is_true'],
                    ['left' => "{$request}.end_date }}", 'operator' => 'gte', 'right' => "{$request}.start_date }}"],
                ],
            ]),
            $this->node('log_invalid', 'data.execution_log', 'Record invalid request', 1120, 400, [
                'level' => 'warning',
                'message' => 'Leave request #{{ trigger.id }} is invalid (unknown employee or end date before start date).',
            ]),
            $this->node('return_invalid', 'utility.return', 'Return invalid status', 1400, 400, [
                'output' => ['request_id' => '{{ trigger.id }}', 'notification_status' => 'not_sent_invalid_request'],
            ]),
            $this->node('determine_reviewer', 'logic.set_variables', 'Determine reviewer', 1120, 80, [
                'variables' => ['reviewer_id' => "{$request}.reviewer_id | default(nodes.load_employee.output.record.manager_id) }}"],
            ]),
            $this->node('load_reviewer', 'data.get_record', 'Load reviewer', 1400, 80, ['model' => 'employees', 'id' => '{{ variables.reviewer_id }}']),
            $this->node('create_notification', 'data.create_record', 'Create reviewer notification', 1680, 80, [
                'model' => 'reviewer_notifications',
                'fields' => [
                    'leave_request_id' => '{{ trigger.id }}',
                    'reviewer_employee_id' => '{{ variables.reviewer_id }}',
                    'execution_id' => '{{ execution.id }}',
                    'channel' => 'email',
                    'message' => "{{ nodes.load_employee.output.record.name }} requested {$request}.leave_type }} leave from {$request}.start_date | date('M j') }} to {$request}.end_date | date('M j, Y') }}.",
                    'status' => 'logged',
                ],
            ]),
            $this->node('log_notification', 'notify.log', 'Log reviewer notification', 1960, 80, [
                'message' => 'Leave request #{{ trigger.id }}: notification for reviewer {{ nodes.load_reviewer.output.record.name }} <{{ nodes.load_reviewer.output.record.email }}> logged (not delivered).',
            ]),
            $this->node('summary', 'utility.return', 'Return request & status', 2240, 80, [
                'output' => [
                    'request_id' => '{{ trigger.id }}',
                    'reviewer' => '{{ nodes.load_reviewer.output.record.name }}',
                    'notification_id' => '{{ nodes.create_notification.output.record.id }}',
                    'notification_status' => 'logged (delivery simulated)',
                ],
            ]),
        ];

        $edges = [
            ...$this->chain(['leave_created', 'load_request', 'load_employee', 'validate_request']),
            $this->edge('validate_request', 'determine_reviewer', 'true'),
            $this->edge('validate_request', 'log_invalid', 'false'),
            $this->edge('log_invalid', 'return_invalid'),
            ...$this->chain(['determine_reviewer', 'load_reviewer', 'create_notification', 'log_notification', 'summary']),
        ];

        return [
            'key' => 'erp_leave_request_notification',
            'name' => 'Employee Leave Request Notification',
            'category' => 'ERP / HR Automation',
            'description' => 'Notify the appropriate reviewer when a leave request is created: validate the request, determine the reviewer and record the notification.',
            'icon' => 'calendar-check',
            'color' => '#579bfc',
            'tags' => ['ERP', 'HR', 'Approvals'],
            'settings' => ['timeout_seconds' => 120],
            'definition' => ['nodes' => $nodes, 'edges' => $edges],
        ];
    }
}
