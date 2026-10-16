<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Models\Content;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\ReviewerNotification;
use App\Models\Role;
use App\Models\ThumbnailTask;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoEventTest extends TestCase
{
    use RefreshDatabase;

    private function activateTemplate(string $key, User $user): void
    {
        $workflow = app(WorkflowService::class)->importTemplate(WorkflowTemplate::query()->where('key', $key)->firstOrFail(), $user);
        app(WorkflowService::class)->activate($workflow, $user);
    }

    #[Test]
    public function publishing_demo_content_runs_the_active_thumbnail_and_trailer_workflow(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        $this->activateTemplate('ott_thumbnail_trailer', $admin);
        $content = Content::query()->where('title', 'Echoes of Tomorrow')->firstOrFail();

        $response = $this->actingAs($admin)->postJson('/api/v1/demo-events/publish-content', ['content_id' => $content->id])
            ->assertCreated()
            ->assertJsonCount(1, 'executions');

        $execution = WorkflowExecution::query()->findOrFail($response->json('executions.0.id'));
        $this->assertSame(ExecutionStatus::Success, $execution->status, (string) $execution->error_message);
        $this->assertSame('production', $execution->mode);
        $this->assertSame(1, ThumbnailTask::query()->where('content_id', $content->id)->count());

        // Publishing again later is a new event (new idempotency key), not a duplicate.
        $this->travel(2)->seconds();
        $this->actingAs($admin)->postJson('/api/v1/demo-events/publish-content', ['content_id' => $content->id])->assertJsonCount(1, 'executions');
    }

    #[Test]
    public function new_demo_employees_and_leave_requests_trigger_the_hr_workflows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        $this->activateTemplate('erp_employee_onboarding', $admin);
        $this->activateTemplate('erp_leave_request_notification', $admin);

        $employeeId = $this->actingAs($admin)->postJson('/api/v1/demo-events/employees', [
            'name' => 'Riley Demo', 'department' => 'Sales', 'job_title' => 'Account Executive',
        ])->assertCreated()->assertJsonPath('executions.0.status', 'success')->json('record_id');

        $this->assertGreaterThan(0, OnboardingTask::query()->where('employee_id', $employeeId)->count());
        $this->assertNotNull(Employee::query()->find($employeeId)?->getAttribute('manager_id'), 'Manager is derived from the department lead');

        $this->actingAs($admin)->postJson('/api/v1/demo-events/leave-requests', [
            'employee_id' => $employeeId, 'leave_type' => 'annual',
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(12)->toDateString(),
        ])->assertCreated()->assertJsonPath('executions.0.status', 'success');

        $this->assertSame(1, ReviewerNotification::query()->count());
    }

    #[Test]
    public function the_simulator_reports_when_no_workflow_is_listening(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);

        $this->actingAs($admin)->postJson('/api/v1/demo-events/employees', ['name' => 'Nobody Listens', 'department' => 'HR', 'job_title' => 'Analyst'])
            ->assertCreated()
            ->assertJsonCount(0, 'executions')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'no new execution started'));
    }

    #[Test]
    public function the_simulator_is_restricted_to_demo_mode_and_authorized_users(): void
    {
        $this->seed(DatabaseSeeder::class);
        $viewer = $this->userWithRole(Role::VIEWER);
        $admin = $this->userWithRole(Role::ADMIN);

        $this->actingAs($viewer)->getJson('/api/v1/demo-events')->assertForbidden();

        config(['workflows.demo_mode' => false]);
        $this->actingAs($admin)->postJson('/api/v1/demo-events/employees', ['name' => 'X', 'department' => 'HR', 'job_title' => 'Y'])->assertForbidden();

        config(['workflows.demo_mode' => true]);
        $real = Content::query()->create(['title' => 'Real content', 'status' => 'draft', 'is_demo' => false]);
        $this->actingAs($admin)->postJson('/api/v1/demo-events/publish-content', ['content_id' => $real->id])->assertStatus(422);
    }
}
