<?php

namespace Database\Seeders;

use App\Models\WorkflowTemplate;
use App\Workflow\Engine\GraphValidator;
use App\Workflow\Templates\TemplateLibrary;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Registers the six default templates (idempotent: keyed by template key).
 * Templates are blueprints only — nothing is executed by seeding.
 */
class WorkflowTemplateSeeder extends Seeder
{
    public function run(TemplateLibrary $library, GraphValidator $validator): void
    {
        foreach ($library->all(DemoDataSeeder::demoIds()) as $template) {
            $report = $validator->validate($template['definition']);
            if (! $report['valid']) {
                throw new RuntimeException("Template {$template['key']} is invalid: ".json_encode($report['errors']));
            }

            WorkflowTemplate::query()->updateOrCreate(['key' => $template['key']], [
                'name' => $template['name'],
                'description' => $template['description'],
                'category' => $template['category'],
                'definition' => $template['definition'],
                'settings' => $template['settings'],
                'required_integrations' => $template['required_integrations'],
                'tags' => $template['tags'],
                'icon' => $template['icon'],
                'color' => $template['color'],
                'is_active' => true,
            ]);
        }
    }
}
