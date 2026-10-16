<?php

namespace Database\Seeders;

use App\Models\WorkflowIntegration;
use Illuminate\Database\Seeder;

class IntegrationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('workflows.integrations') as $integration) {
            $existingStatus = WorkflowIntegration::query()->where('key', $integration['key'])->value('status');

            WorkflowIntegration::query()->updateOrCreate(['key' => $integration['key']], [
                'name' => $integration['name'],
                'provider' => $integration['provider'],
                'category' => $integration['category'],
                'description' => $integration['description'],
                // Never overwrite a status an admin changed (e.g. after adding credentials).
                'status' => $existingStatus ?? $integration['status'],
            ]);
        }
    }
}
