<?php

namespace Tests\Unit\Workflow;

use App\Workflow\NodeRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NodeConfigValidationTest extends TestCase
{
    #[Test]
    public function required_fields_and_types_are_enforced(): void
    {
        $registry = app(NodeRegistry::class);

        $errors = $registry->get('notify.email')->validateConfig(['to' => '', 'subject' => 'Hi']);
        $this->assertArrayHasKey('to', $errors);
        $this->assertArrayHasKey('body', $errors);

        $errors = $registry->get('trigger.schedule')->validateConfig(['cron' => 'not a cron']);
        $this->assertArrayHasKey('cron', $errors);

        $errors = $registry->get('logic.delay')->validateConfig(['seconds' => 'abc']);
        $this->assertArrayHasKey('seconds', $errors);

        $errors = $registry->get('logic.if')->validateConfig(['conditions' => [['left' => 'a', 'operator' => 'nope', 'right' => 'b']]]);
        $this->assertArrayHasKey('conditions', $errors);
    }

    #[Test]
    public function data_nodes_only_accept_whitelisted_models_and_columns(): void
    {
        $registry = app(NodeRegistry::class);

        $this->assertArrayHasKey('model', $registry->get('data.get_record')->validateConfig(['model' => 'password_reset_tokens', 'id' => 1]));
        $this->assertArrayHasKey('filters', $registry->get('data.find_records')->validateConfig([
            'model' => 'users', 'filters' => [['column' => 'password', 'operator' => '=', 'value' => 'x']],
        ]));
        // The users model is read-only: workflows can never change verified_at.
        $this->assertArrayHasKey('fields', $registry->get('data.update_record')->validateConfig([
            'model' => 'users', 'id' => 1, 'fields' => ['verified_at' => '2026-01-01'],
        ]));
    }

    #[Test]
    public function invalid_expressions_are_reported(): void
    {
        $errors = app(NodeRegistry::class)->get('notify.log')->validateConfig(['message' => '{{ config.app_key }}']);
        $this->assertArrayHasKey('message', $errors);
    }
}
