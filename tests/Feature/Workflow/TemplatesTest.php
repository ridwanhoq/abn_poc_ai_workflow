<?php

namespace Tests\Feature\Workflow;

use App\Enums\ExecutionStatus;
use App\Models\Content;
use App\Models\LocalizationTask;
use App\Models\MediaAssetInsight;
use App\Models\OnboardingTask;
use App\Models\RetentionCampaign;
use App\Models\ReviewerNotification;
use App\Models\ThumbnailTask;
use App\Models\TrailerRequest;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeding_is_idempotent_and_does_not_execute_anything(): void
    {
        Queue::fake();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, WorkflowTemplate::query()->count());
        $this->assertSame(0, WorkflowExecution::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function all_six_templates_can_be_imported_and_executed_without_credentials(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole();

        foreach (WorkflowTemplate::all() as $template) {
            $import = $this->actingAs($admin)->postJson("/api/v1/templates/{$template->id}/import")->assertCreated();
            $workflowId = $import->json('data.id');

            $this->assertSame($template->id, Workflow::query()->find($workflowId)->template_id);
            $this->assertEquals($template->definition, $template->fresh()->definition, 'Importing must not modify the template');
            $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflowId}/validate")->assertOk()->assertJson(['valid' => true]);

            $run = $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflowId}/execute", ['test' => true])->assertStatus(202);
            $execution = WorkflowExecution::query()->findOrFail($run->json('data.id'));

            $this->assertSame(ExecutionStatus::Success, $execution->status, "{$template->key}: {$execution->error_message}");
            $this->assertSame('test', $execution->mode);
        }

        // Real, local side effects were produced…
        $this->assertGreaterThan(0, MediaAssetInsight::query()->count());
        $this->assertSame(4, LocalizationTask::query()->count());
        $this->assertSame(1, ThumbnailTask::query()->where('is_simulated', true)->count());
        $this->assertSame(1, TrailerRequest::query()->count());
        $this->assertGreaterThan(0, RetentionCampaign::query()->count());
        $this->assertGreaterThan(0, OnboardingTask::query()->count());
        $this->assertSame(1, ReviewerNotification::query()->count());
        // …and nothing was emailed.
        Mail::assertNothingSent();

        // Importing never changes the template itself.
        $this->assertSame(6, WorkflowTemplate::query()->count());
    }

    #[Test]
    public function the_thumbnail_template_stops_on_invalid_content(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole();
        $template = WorkflowTemplate::query()->where('key', 'ott_thumbnail_trailer')->firstOrFail();
        $workflowId = $this->actingAs($admin)->postJson("/api/v1/templates/{$template->id}/import")->json('data.id');
        $invalidContentId = Content::query()->whereNull('title')->value('id');

        $run = $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflowId}/execute", ['payload' => ['id' => $invalidContentId]]);
        $execution = WorkflowExecution::query()->findOrFail($run->json('data.id'));

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Content is missing a title or source asset.', $execution->error_message);
        $this->assertSame(0, ThumbnailTask::query()->count());
    }
}
