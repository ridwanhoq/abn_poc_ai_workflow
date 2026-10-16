<?php

namespace Tests\Unit\Workflow;

use App\Workflow\NodeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NodeRegistryTest extends TestCase
{
    #[Test]
    public function every_configured_node_is_registered_with_a_complete_contract(): void
    {
        $registry = app(NodeRegistry::class);
        $this->assertCount(count(config('workflows.nodes')), $registry->all());

        foreach ($registry->all() as $type => $node) {
            $array = $node->toArray();
            $this->assertSame($type, $array['type']);
            $this->assertContains($array['category'], ['trigger', 'logic', 'data', 'notification', 'ai_media', 'utility']);
            $this->assertContains($array['implementation'], ['implemented', 'simulated', 'integration_required']);
            $this->assertIsArray($array['config_schema']);
            $this->assertNotEmpty($array['label']);
            $config = $this->requiredPlaceholders($array['config_schema']) + $array['defaults'];
            if (in_array($type, ['data.create_record', 'data.update_record'], true)) {
                $config = ['model' => 'onboarding_tasks', 'fields' => ['status' => 'pending']] + $config;
            }
            $this->assertSame([], $node->validateConfig($config), "Defaults for {$type} should validate");
        }
    }

    #[Test]
    public function the_spec_node_catalogue_is_available(): void
    {
        $registry = app(NodeRegistry::class);
        foreach ([
            'trigger.manual', 'trigger.schedule', 'trigger.webhook', 'trigger.database_event', 'trigger.file_uploaded', 'trigger.user_created', 'trigger.user_verification_pending',
            'logic.if', 'logic.filter', 'logic.switch', 'logic.delay', 'logic.loop', 'logic.merge', 'logic.set_variables',
            'data.get_record', 'data.find_records', 'data.create_record', 'data.update_record', 'data.execution_log', 'data.transform', 'data.map_fields',
            'notify.email', 'notify.in_app', 'notify.webhook', 'notify.log',
            'ai.text', 'ai.image', 'media.video_metadata', 'media.transcription', 'media.thumbnail', 'media.trailer', 'media.subtitles', 'ai.moderation', 'ai.translation',
            'utility.subworkflow', 'utility.stop', 'utility.return',
        ] as $type) {
            $this->assertTrue($registry->has($type), "Missing node type {$type}");
        }
    }

    #[Test]
    public function unknown_types_cannot_be_resolved(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(NodeRegistry::class)->get('App\\Models\\User');
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    private function requiredPlaceholders(array $schema): array
    {
        $values = [];
        foreach ($schema as $field) {
            if ($field['required'] && empty($field['default'] ?? null)) {
                $values[$field['key']] = match ($field['type']) {
                    'number' => 1,
                    'keyvalue', 'json' => ['a' => 'b'],
                    default => '{{ trigger.value }}',
                };
            }
        }

        return $values;
    }
}
