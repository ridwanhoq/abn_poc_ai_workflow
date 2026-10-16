<?php

namespace Tests\Feature\Workflow;

use App\Enums\ExecutionStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowTemplate;
use App\Services\ExecutionService;
use App\Services\WorkflowService;
use Database\Seeders\IntegrationSeeder;
use Database\Seeders\WorkflowTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Critical business rules for template 4 (users.verified_at IS NULL reminders).
 */
class UnverifiedUserReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(); // verified (factory default)
        $this->seed([IntegrationSeeder::class, WorkflowTemplateSeeder::class]);

        $template = WorkflowTemplate::query()->where('key', 'erp_unverified_user_reminder')->firstOrFail();
        $this->workflow = app(WorkflowService::class)->importTemplate($template, $this->admin);
    }

    private function runWorkflow(): WorkflowExecution
    {
        return app(ExecutionService::class)->start($this->workflow, 'manual', [], $this->admin, 'test', null, [], true);
    }

    #[Test]
    public function it_selects_only_users_whose_verified_at_is_null_and_excludes_verified_users(): void
    {
        $unverified = User::factory()->profileUnverified()->count(3)->create();
        $verified = User::factory()->count(2)->create();

        Log::spy();
        $execution = $this->runWorkflow();

        $this->assertSame(ExecutionStatus::Success, $execution->status);
        $this->assertSame(3, $execution->result['users_found']);
        $this->assertSame(3, $execution->result['reminders_logged']);

        foreach ($unverified as $user) {
            Log::shouldHaveReceived('info')->withArgs(fn ($message) => $message === "[Workflow: Unverified User Reminder] Email reminder prepared for {$user->email} (User ID: {$user->id}).")->once();
        }
        foreach ([...$verified, $this->admin] as $user) {
            Log::shouldNotHaveReceived('info', [fn ($message) => str_contains($message, "(User ID: {$user->id})")]);
        }

        $processed = $execution->steps()->where('node_key', 'send_reminder')->count();
        $this->assertSame(3, $processed);
    }

    #[Test]
    public function invalid_email_addresses_are_skipped_and_recorded(): void
    {
        User::factory()->profileUnverified()->create(['email' => 'valid.one@example.test']);
        $invalid = User::factory()->profileUnverified()->create(['email' => 'invalid-email.demo']);

        $execution = $this->runWorkflow();

        $this->assertSame(1, $execution->result['reminders_logged']);
        $this->assertSame(1, $execution->result['skipped_invalid_email']);
        $this->assertDatabaseHas('workflow_execution_logs', [
            'execution_id' => $execution->id,
            'level' => 'warning',
            'message' => "Skipped user #{$invalid->id}: invalid email address.",
        ]);
    }

    #[Test]
    public function demo_mode_logs_email_intentions_but_sends_nothing(): void
    {
        config(['workflows.demo_mode' => true]);
        Mail::fake();
        User::factory()->profileUnverified()->count(2)->create();

        $execution = $this->runWorkflow();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $output = $execution->steps()->where('node_key', 'send_reminder')->first()->output_data;
        $this->assertFalse($output['delivered']);
        $this->assertTrue($output['simulated']);
        $this->assertSame('log', $output['adapter']);
    }

    #[Test]
    public function production_runs_without_explicit_opt_in_still_only_log(): void
    {
        config(['workflows.demo_mode' => false, 'mail.default' => 'smtp']);
        Mail::fake();
        User::factory()->profileUnverified()->create();

        app(WorkflowService::class)->activate($this->workflow, $this->admin);
        app(ExecutionService::class)->start($this->workflow->refresh(), 'schedule', [], null, 'production', null, [], true);

        Mail::assertNothingSent(); // allow_external_side_effects is false by default
    }

    #[Test]
    public function large_datasets_are_processed_in_batches(): void
    {
        User::factory()->profileUnverified()->count(250)->create();

        DB::enableQueryLog();
        $execution = $this->runWorkflow();
        $userSelects = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select * from "users"') && str_contains($q['query'], 'limit 100'));

        $this->assertSame(250, $execution->result['users_found']);
        $this->assertSame(250, $execution->result['users_processed']);
        $this->assertSame(3, $execution->result['batches'], '250 users with batch size 100 => 3 batches');
        $this->assertCount(3, $userSelects, 'Users must be fetched with chunked keyset queries, never all at once');
    }

    #[Test]
    public function the_workflow_never_modifies_verified_at(): void
    {
        $users = User::factory()->profileUnverified()->count(3)->create();

        $this->runWorkflow();

        foreach ($users as $user) {
            $this->assertNull($user->fresh()->verified_at);
        }
        $this->assertSame(3, User::query()->whereNull('verified_at')->count());
    }
}
