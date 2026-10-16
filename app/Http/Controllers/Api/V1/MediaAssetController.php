<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MediaAssetUploaded;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MediaAssetController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('workflows.view');

        return response()->json(['data' => MediaAsset::query()->latest('id')->limit(50)->get()]);
    }

    /**
     * Stores an upload on the configured disk (local or S3, see WORKFLOW_UPLOAD_DISK) and
     * fires the File Uploaded trigger.
     *
     * TODO: Start workflows from storage events for files that arrive in the bucket without
     * this endpoint (S3 ObjectCreated → SQS/EventBridge → signed webhook). Validate object
     * metadata and de-duplicate by ETag.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('workflows.execute');

        $config = config('workflows.uploads');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$config['max_kb'], 'mimetypes:'.implode(',', $config['mimetypes'])],
            'title' => ['nullable', 'string', 'max:160'],
            'language' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
        ]);

        $file = $data['file'];
        $path = $file->storeAs('media/uploads', Str::uuid().'.'.$file->extension(), $config['disk']);

        // Cloud disks are configured not to throw; a failed write returns false.
        if ($path === false) {
            return response()->json(['message' => 'The upload could not be stored. Check the storage configuration.'], 503);
        }

        $asset = MediaAsset::query()->create([
            'title' => $data['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'file_path' => $path,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'language' => $data['language'] ?? 'en',
            'status' => 'uploaded',
            'uploaded_by' => $request->user()->id,
        ]);

        MediaAssetUploaded::dispatch($asset);

        return response()->json(['data' => $asset, 'message' => 'Upload stored. Active "File Uploaded" workflows were triggered.'], 201);
    }
}
