<?php

namespace Database\Seeders;

use App\Models\Content;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MediaAsset;
use App\Models\Subscriber;
use Illuminate\Database\Seeder;

/**
 * Minimal OTT / ERP sample records that stand in for production CMS,
 * subscriber and HR data. Every row is flagged is_demo.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ---- OTT media assets & contents --------------------------------
        MediaAsset::query()->updateOrCreate(['file_path' => 'media/demo/the-last-lighthouse.mp4'], [
            'title' => 'The Last Lighthouse',
            'original_filename' => 'the-last-lighthouse.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 734_003_200,
            'language' => 'en',
            'duration_seconds' => 5820,
            'status' => 'uploaded',
            'is_demo' => true,
        ]);
        MediaAsset::query()->updateOrCreate(['file_path' => 'media/demo/city-of-echoes-s01e01.mp4'], [
            'title' => 'City of Echoes — S01E01',
            'original_filename' => 'city-of-echoes-s01e01.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 412_090_368,
            'language' => 'en',
            'duration_seconds' => 2640,
            'status' => 'uploaded',
            'is_demo' => true,
        ]);

        Content::query()->updateOrCreate(['title' => 'Echoes of Tomorrow', 'is_demo' => true], [
            'content_type' => 'movie',
            'synopsis' => 'A sound engineer discovers recordings from a future that has not happened yet — and a warning only she can hear.',
            'genre' => 'Sci-Fi',
            'language' => 'en',
            'source_asset_path' => 'media/demo/echoes-of-tomorrow.mp4',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        Content::query()->updateOrCreate(['synopsis' => 'Draft episode without a title (validation demo).', 'is_demo' => true], [
            'title' => null,
            'content_type' => 'episode',
            'genre' => 'Drama',
            'source_asset_path' => null,
            'status' => 'draft',
        ]);

        // ---- Subscribers (churn template) -------------------------------
        $subscribers = [
            ['Hannah Wright', 'basic', 75, 10, 1, 4, 'Drama'],
            ['Diego Alvarez', 'standard', 45, 40, 0, 20, 'Comedy'],
            ['Fatima Khan', 'premium', 20, 150, 0, 3, 'Documentary'],
            ['George Miller', 'basic', 33, 5, 2, 15, 'Action'],
            ['Isabella Costa', 'standard', 2, 900, 0, 18, 'Romance'],
            ['Kenji Watanabe', 'premium', 90, 0, 1, 6, 'Thriller'],
            ['Layla Hassan', 'standard', 16, 260, 0, 25, 'Family'],
            ['Mateo Rossi', 'basic', 5, 600, 0, 9, 'Sci-Fi'],
            ['Nora Lindqvist', 'standard', 61, 15, 0, 2, 'Crime'],
            ['Omar Farouk', 'premium', 1, 1200, 0, 28, 'Adventure'],
            ['Priya Nair', 'basic', 28, 95, 1, 12, 'Comedy'],
            ['Ruben Silva', 'standard', 40, 30, 0, 5, 'Drama', 'cancelled'],
        ];

        foreach ($subscribers as $i => $row) {
            [$name, $plan, $inactiveDays, $minutes, $failed, $renewIn, $genre] = $row;
            Subscriber::query()->updateOrCreate(['email' => 'subscriber'.($i + 1).'@example.test'], [
                'name' => $name,
                'plan' => $plan,
                'status' => $row[7] ?? 'active',
                'last_active_at' => now()->subDays($inactiveDays),
                'subscription_renews_at' => now()->addDays($renewIn),
                'watch_minutes_30d' => $minutes,
                'failed_payments' => $failed,
                'favorite_genre' => $genre,
                'is_demo' => true,
            ]);
        }

        // ---- Employees & leave requests (HR templates) ------------------
        $lead = Employee::query()->updateOrCreate(['email' => 'priya.shah@example.test'], [
            'name' => 'Priya Shah', 'department' => 'Engineering', 'job_title' => 'Engineering Manager', 'start_date' => now()->subYears(3), 'is_demo' => true,
        ]);
        $hr = Employee::query()->updateOrCreate(['email' => 'marcus.lee@example.test'], [
            'name' => 'Marcus Lee', 'department' => 'HR', 'job_title' => 'HR Business Partner', 'start_date' => now()->subYears(2), 'is_demo' => true,
        ]);
        $salesLead = Employee::query()->updateOrCreate(['email' => 'elena.garcia@example.test'], [
            'name' => 'Elena Garcia', 'department' => 'Sales', 'job_title' => 'Head of Sales', 'start_date' => now()->subYears(4), 'is_demo' => true,
        ]);
        $newHire = Employee::query()->updateOrCreate(['email' => 'jordan.rivera@example.test'], [
            'name' => 'Jordan Rivera', 'department' => 'Engineering', 'job_title' => 'Backend Engineer', 'manager_id' => $lead->id, 'start_date' => now()->addDays(7), 'is_demo' => true,
        ]);
        Employee::query()->updateOrCreate(['email' => 'amelia.chen@example.test'], [
            'name' => 'Amelia Chen', 'department' => 'Sales', 'job_title' => 'Account Executive', 'manager_id' => $salesLead->id, 'start_date' => now()->addDays(14), 'is_demo' => true,
        ]);

        LeaveRequest::query()->updateOrCreate(['employee_id' => $newHire->id, 'reason' => 'Family wedding (demo)'], [
            'leave_type' => 'annual',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(34)->toDateString(),
            'status' => 'pending',
            'reviewer_id' => null, // reviewer derived from the employee's manager
            'is_demo' => true,
        ]);
        LeaveRequest::query()->updateOrCreate(['employee_id' => $hr->id, 'reason' => 'Medical appointment (demo)'], [
            'leave_type' => 'sick',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'pending',
            'reviewer_id' => $lead->id,
            'is_demo' => true,
        ]);
    }

    /**
     * IDs the template sample payloads point to.
     *
     * @return array<string, int|null>
     */
    public static function demoIds(): array
    {
        $newHire = Employee::query()->where('email', 'jordan.rivera@example.test')->value('id');

        return [
            'media_asset' => MediaAsset::query()->where('file_path', 'media/demo/the-last-lighthouse.mp4')->value('id'),
            'content' => Content::query()->where('title', 'Echoes of Tomorrow')->value('id'),
            'employee' => $newHire,
            'leave_request' => LeaveRequest::query()->where('employee_id', $newHire)->value('id'),
        ];
    }
}
