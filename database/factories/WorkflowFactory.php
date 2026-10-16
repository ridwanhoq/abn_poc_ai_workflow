<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::random(5),
            'description' => fake()->sentence(),
            'status' => WorkflowStatus::Draft,
            'definition' => [
                'nodes' => [
                    ['key' => 'start', 'type' => 'trigger.manual', 'name' => 'Start', 'position' => ['x' => 0, 'y' => 0], 'config' => ['sample_payload' => ['name' => 'Ada']]],
                    ['key' => 'log', 'type' => 'notify.log', 'name' => 'Log', 'position' => ['x' => 250, 'y' => 0], 'config' => ['message' => 'Hello {{ trigger.name }}']],
                ],
                'edges' => [['id' => 'e1', 'source' => 'start', 'target' => 'log', 'sourceHandle' => 'main']],
            ],
            'trigger_type' => 'manual',
            'settings' => [],
            'version' => 1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function withGraph(array $nodes, array $edges): static
    {
        return $this->state(fn () => ['definition' => ['nodes' => $nodes, 'edges' => $edges]]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => WorkflowStatus::Active, 'activated_at' => now()]);
    }
}
