<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Models\WorkflowSchedule;
use App\Services\WorkflowService;
use App\Workflow\Triggers\TriggerDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TriggerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function schedules_dispatch_once_per_slot_and_only_for_active_workflows(): void
    {
        Queue::fake();
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('cron', 'trigger.schedule', ['cron' => '*/5 * * * *', 'timezone' => 'UTC'])], []);

        // Saving / creating does not schedule anything.
        $this->assertSame(0, WorkflowSchedule::query()->count());

        app(WorkflowService::class)->activate($workflow, $admin);
        $schedule = WorkflowSchedule::query()->firstOrFail();
        $schedule->update(['next_run_at' => now()->subMinute()]);

        $dispatcher = app(TriggerDispatcher::class);
        $this->assertSame(1, $dispatcher->runDueSchedules());
        $this->assertSame(0, $dispatcher->runDueSchedules(), 'Same slot must not dispatch twice');
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
        $this->assertSame(1, WorkflowExecution::query()->where('trigger_type', 'schedule')->count());

        $this->artisan('workflows:run-schedules')->assertSuccessful();

        app(WorkflowService::class)->deactivate($workflow, $admin);
        $schedule->refresh()->forceFill(['next_run_at' => now()->subMinute()])->save();
        $this->assertSame(0, $dispatcher->runDueSchedules());
    }

    #[Test]
    public function the_scheduler_registers_the_dispatch_command(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('workflows:run-schedules')->assertSuccessful();
    }

    #[Test]
    public function webhooks_require_a_valid_signature_and_required_fields(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [
            $this->node('hook', 'trigger.webhook', ['required_fields' => ['order_id']]),
            $this->node('ret', 'utility.return', ['output' => ['order' => '{{ trigger.order_id }}']]),
        ], [$this->edge('hook', 'ret')]);
        app(WorkflowService::class)->activate($workflow, $admin);
        $workflow->refresh();

        $url = "/api/v1/hooks/{$workflow->webhook_token}";
        $sign = fn (string $body) => ['X-Workflow-Signature' => 'sha256='.hash_hmac('sha256', $body, $workflow->webhook_secret), 'Content-Type' => 'application/json'];

        $body = json_encode(['order_id' => 77]);
        $this->call('POST', $url, [], [], [], $this->server(['X-Workflow-Signature' => 'sha256=bad']), $body)->assertUnauthorized();

        $missing = json_encode(['foo' => 'bar']);
        $this->call('POST', $url, [], [], [], $this->server($sign($missing)), $missing)->assertStatus(422);

        $ok = $this->call('POST', $url, [], [], [], $this->server($sign($body) + ['Idempotency-Key' => 'abc']), $body)->assertStatus(202);
        $this->call('POST', $url, [], [], [], $this->server($sign($body) + ['Idempotency-Key' => 'abc']), $body)->assertOk();

        $execution = WorkflowExecution::query()->where('uuid', $ok->json('execution_id'))->firstOrFail();
        $this->assertSame(ExecutionStatus::Success, $execution->status);
        $this->assertSame(['order' => 77], $execution->result);
        $this->assertSame(1, WorkflowExecution::query()->count());

        $this->postJson('/api/v1/hooks/'.str_repeat('a', 40), [])->assertNotFound();
    }

    #[Test]
    public function database_events_start_matching_active_workflows_only(): void
    {
        Queue::fake();
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('evt', 'trigger.database_event', ['model' => 'employees', 'event' => 'created'])], []);

        Employee::query()->create(['name' => 'Draft-time hire', 'email' => 'a@example.test', 'department' => 'Sales', 'job_title' => 'AE']);
        $this->assertSame(0, WorkflowExecution::query()->count(), 'Draft workflows must not react to events');

        app(WorkflowService::class)->activate($workflow, $admin);
        $employee = Employee::query()->create(['name' => 'New hire', 'email' => 'b@example.test', 'department' => 'Sales', 'job_title' => 'AE']);

        $execution = WorkflowExecution::query()->sole();
        $this->assertSame('database_event', $execution->trigger_type);
        $this->assertSame($employee->id, $execution->trigger_data['id']);
    }

    #[Test]
    public function uploading_a_video_fires_file_uploaded_workflows(): void
    {
        Queue::fake();
        Storage::fake('local');
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('upload', 'trigger.file_uploaded', ['mime_prefix' => 'video/'])], []);
        app(WorkflowService::class)->activate($workflow, $admin);

        $this->actingAs($admin)->postJson('/api/v1/media-assets', ['file' => UploadedFile::fake()->create('clip.txt', 10, 'text/plain')])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/v1/media-assets', [
            'file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'),
            'title' => 'Clip',
        ])->assertCreated();

        $execution = WorkflowExecution::query()->sole();
        $this->assertSame('file_uploaded', $execution->trigger_type);
        $this->assertSame('Clip', $execution->trigger_data['title']);
    }

    #[Test]
    public function verification_pending_trigger_starts_one_execution_per_unverified_user(): void
    {
        Queue::fake();
        $admin = $this->userWithRole(Role::ADMIN);
        User::factory()->profileUnverified()->count(2)->create(['created_at' => now()->subDays(2)]);
        User::factory()->profileUnverified()->create(['created_at' => now()]); // too new

        $workflow = $this->workflowFor($admin, [$this->node('pending', 'trigger.user_verification_pending', ['cron' => '0 10 * * *', 'min_account_age_hours' => 24])], []);
        app(WorkflowService::class)->activate($workflow, $admin);
        WorkflowSchedule::query()->update(['next_run_at' => CarbonImmutable::now()->subMinute()]);

        $this->assertSame(2, app(TriggerDispatcher::class)->runDueSchedules());
        $this->assertSame(2, WorkflowExecution::query()->where('trigger_type', 'user_verification_pending')->count());
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE'], true) ? $key : "HTTP_{$key}"] = $value;
        }

        return $server;
    }
}
