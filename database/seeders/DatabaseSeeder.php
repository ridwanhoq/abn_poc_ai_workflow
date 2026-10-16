<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Model events are disabled while seeding so demo records never fire
    // database-event triggers or start workflows.
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UserSeeder::class,
            IntegrationSeeder::class,
            DemoDataSeeder::class,
            WorkflowTemplateSeeder::class,
        ]);
    }
}
