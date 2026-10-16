<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\WorkflowExecution;
use App\Services\WorkflowService;
use App\Workflow\Integrations\IntegrationManager;
use Database\Seeders\IntegrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class S3StorageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function uploads_go_to_s3_and_media_nodes_read_them_from_there(): void
    {
        config(['workflows.uploads.disk' => 's3', 'filesystems.disks.s3.bucket' => 'media-bucket']);
        Storage::fake('s3');
        Storage::fake('local');
        $admin = $this->userWithRole(Role::ADMIN);

        $workflow = $this->workflowFor($admin, [
            $this->node('upload', 'trigger.file_uploaded', ['mime_prefix' => 'video/']),
            $this->node('meta', 'media.video_metadata', ['asset_id' => '{{ trigger.asset_id }}']),
            $this->node('ret', 'utility.return', ['output' => ['found' => '{{ nodes.meta.output.file_found }}', 'size' => '{{ nodes.meta.output.size_bytes }}']]),
        ], [$this->edge('upload', 'meta'), $this->edge('meta', 'ret')]);
        app(WorkflowService::class)->activate($workflow, $admin);

        $this->actingAs($admin)->postJson('/api/v1/media-assets', [
            'file' => UploadedFile::fake()->create('clip.mp4', 120, 'video/mp4'),
            'title' => 'S3 clip',
        ])->assertCreated();

        $asset = MediaAsset::query()->where('title', 'S3 clip')->sole();
        Storage::disk('s3')->assertExists($asset->getAttribute('file_path'));
        Storage::disk('local')->assertMissing($asset->getAttribute('file_path'));

        $execution = WorkflowExecution::query()->sole();
        $this->assertSame(ExecutionStatus::Success, $execution->status);
        // The node read the object from S3 (the fake upload's stored body is empty, so compare to the real stored size).
        $this->assertSame(['found' => true, 'size' => Storage::disk('s3')->size($asset->getAttribute('file_path'))], $execution->result);
    }

    #[Test]
    public function a_failed_storage_write_is_an_error_not_a_ghost_record(): void
    {
        config(['workflows.uploads.disk' => 's3']);
        $admin = $this->userWithRole(Role::ADMIN);
        // A disk that refuses writes, like an unreachable or misconfigured bucket with throw => false.
        Storage::shouldReceive('disk')->andReturnSelf();
        Storage::shouldReceive('putFileAs')->andReturn(false);

        $this->actingAs($admin)->postJson('/api/v1/media-assets', [
            'file' => UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'),
        ])->assertStatus(503);

        $this->assertSame(0, MediaAsset::query()->count());
    }

    #[Test]
    public function the_s3_integration_reports_its_real_status(): void
    {
        $this->seed(IntegrationSeeder::class);
        $manager = app(IntegrationManager::class);

        $this->assertSame('not_configured', $manager->status('s3'));

        config(['workflows.uploads.disk' => 's3', 'filesystems.disks.s3.bucket' => 'media-bucket']);
        $this->assertSame('connected', $manager->status('s3'));
    }
}
