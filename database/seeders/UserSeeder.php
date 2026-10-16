<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts (password: "password") and clearly labelled demo users on the
 * reserved .test TLD for the unverified-user reminder template. Seeding never
 * sends any email.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $ott = Workspace::query()->updateOrCreate(['slug' => 'ott-media'], ['name' => 'OTT Media', 'color' => '#6161ff']);
        $erp = Workspace::query()->updateOrCreate(['slug' => 'erp-operations'], ['name' => 'ERP Operations', 'color' => '#00c875']);
        $roles = Role::query()->pluck('id', 'slug');

        $staff = [
            ['superadmin@flowforge.test', 'Sam Super', Role::SUPER_ADMIN, [$ott, $erp]],
            ['admin@flowforge.test', 'Avery Admin', Role::ADMIN, [$ott, $erp]],
            ['manager@flowforge.test', 'Morgan Manager', Role::WORKFLOW_MANAGER, [$ott, $erp]],
            ['viewer@flowforge.test', 'Vic Viewer', Role::VIEWER, [$ott]],
        ];

        foreach ($staff as [$email, $name, $role, $workspaces]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'password' => $user->exists ? $user->password : Hash::make('password'),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'verified_at' => $user->verified_at ?? now(),
                'role_id' => $roles[$role],
                'current_workspace_id' => $user->current_workspace_id ?? $ott->id,
                'is_demo' => true,
            ])->save();
            $user->workspaces()->syncWithoutDetaching(collect($workspaces)->pluck('id'));
        }

        // Demo end-users for the "Unverified User Reminder" template.
        $demo = [
            ['olivia.unverified@example.test', 'Olivia Park', null],
            ['liam.unverified@example.test', 'Liam Okafor', null],
            ['emma.unverified@example.test', 'Emma Rossi', null],
            ['noah.unverified@example.test', 'Noah Fischer', null],
            ['ava.unverified@example.test', 'Ava Martins', null],
            ['invalid-email.demo', 'Invalid Email Demo', null], // deliberately invalid → skipped by the workflow
            ['mia.verified@example.test', 'Mia Svensson', now()->subDays(20)],
            ['lucas.verified@example.test', 'Lucas Moreau', now()->subDays(12)],
            ['zoe.verified@example.test', 'Zoe Nakamura', now()->subDays(5)],
        ];

        foreach ($demo as [$email, $name, $verifiedAt]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'password' => $user->exists ? $user->password : Hash::make(str()->random(32)),
                'email_verified_at' => now()->subDays(30),
                'verified_at' => $verifiedAt,
                'role_id' => $roles[Role::VIEWER],
                'is_demo' => true,
                'created_at' => $user->created_at ?? now()->subDays(3),
            ])->save();
        }
    }
}
