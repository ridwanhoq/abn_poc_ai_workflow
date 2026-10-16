<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkflowStatus;
use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Services\ExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, signed webhook endpoint.
 *
 * Clients send the raw JSON body with header
 *   X-Workflow-Signature: sha256=<hex HMAC-SHA256(body, webhook secret)>
 * and may send Idempotency-Key to de-duplicate deliveries.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $token, ExecutionService $executions): JsonResponse
    {
        // Payloads become stored trigger data: cap their size before any other work.
        $maxBytes = (int) config('workflows.webhook_max_kb', 256) * 1024;
        if (strlen($request->getContent()) > $maxBytes) {
            return response()->json(['message' => 'Payload too large (max '.($maxBytes / 1024).' KB).'], 413);
        }

        $workflow = Workflow::query()->where('webhook_token', $token)->first();

        // Same response for unknown and inactive hooks to avoid token probing.
        if ($workflow === null || $workflow->status !== WorkflowStatus::Active || $workflow->trigger_type !== 'webhook') {
            return response()->json(['message' => 'Webhook not found.'], 404);
        }

        $signature = (string) $request->header('X-Workflow-Signature', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $workflow->webhook_secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        if ($request->getContent() !== '' && ! is_array(json_decode($request->getContent(), true))) {
            return response()->json(['message' => 'Body must be a JSON object.'], 422);
        }

        $required = (array) ($workflow->triggerNode()['config']['required_fields'] ?? []);
        $missing = array_values(array_filter($required, fn ($field) => ! array_key_exists($field, $payload)));
        if ($missing !== []) {
            return response()->json(['message' => 'Missing required fields.', 'errors' => ['payload' => array_map(fn ($f) => "{$f} is required.", $missing)]], 422);
        }

        $key = $request->header('Idempotency-Key');
        $execution = $executions->start($workflow, 'webhook', $payload, null, 'production', $key ? 'webhook:'.mb_substr($key, 0, 120) : null);

        return response()->json([
            'message' => $execution->wasRecentlyCreated ? 'Accepted.' : 'Duplicate delivery — existing execution returned.',
            'execution_id' => $execution->uuid,
            'status' => $execution->status->value,
        ], $execution->wasRecentlyCreated ? 202 : 200);
    }
}
