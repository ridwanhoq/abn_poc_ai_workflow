<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Workflow\Integrations\Contracts\ContentModerator;
use App\Workflow\Integrations\Contracts\ImageGenerator;
use App\Workflow\Integrations\Contracts\Translator;
use App\Workflow\Integrations\IntegrationManager;
use App\Workflow\Integrations\Mock\MockTextGenerator;
use App\Workflow\Support\ChurnScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Lets users try the AI adapters directly. All adapters are the configured
 * (currently mock) implementations — results are labelled as simulated.
 */
class PlaygroundController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('playground.use');

        $data = $request->validate([
            'tool' => ['required', 'in:text,image,moderation,translation,churn'],
            'task' => ['nullable', Rule::in(array_keys(MockTextGenerator::TASKS))],
            'prompt' => ['nullable', 'string', 'max:4000'],
            'context' => ['nullable', 'array'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'subscriber' => ['nullable', 'array'],
        ]);

        $started = microtime(true);

        $result = match ((string) $data['tool']) {
            'text' => app(IntegrationManager::class)->textGenerator()->generate($data['task'] ?? 'generic', (string) ($data['prompt'] ?? ''), $data['context'] ?? []),
            'image' => app(ImageGenerator::class)->generate((string) ($data['prompt'] ?? ''), ['aspect_ratio' => $data['aspect_ratio'] ?? '16:9']),
            'moderation' => app(ContentModerator::class)->moderate((string) ($data['prompt'] ?? '')),
            'translation' => app(Translator::class)->translate((string) ($data['prompt'] ?? ''), $data['target_language'] ?? 'es'),
            'churn' => app(ChurnScorer::class)->score($data['subscriber'] ?? []) + ['method' => 'rule_based_v1', 'simulated' => false],
            default => abort(422, 'Unknown tool.'),
        };

        return response()->json(['result' => $result, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
    }
}
