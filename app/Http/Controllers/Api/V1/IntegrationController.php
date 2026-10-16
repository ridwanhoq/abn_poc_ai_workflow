<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkflowIntegration;
use App\Services\AuditLogger;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Integrations\Anthropic\ClaudeTextGenerator;
use App\Workflow\Integrations\IntegrationManager;
use App\Workflow\NodeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public const CLAUDE_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    public function index(IntegrationManager $manager, NodeRegistry $registry): JsonResponse
    {
        Gate::authorize('integrations.view');

        $nodesByIntegration = [];
        foreach ($registry->all() as $node) {
            if ($node->integration() !== null) {
                $nodesByIntegration[$node->integration()][] = $node->label();
            }
        }

        return response()->json([
            'data' => WorkflowIntegration::query()->orderBy('category')->orderBy('name')->get()->map(fn (WorkflowIntegration $i) => [
                'id' => $i->id,
                'key' => $i->key,
                'name' => $i->name,
                'provider' => $i->provider,
                'category' => $i->category,
                'description' => $i->description,
                'status' => $manager->status($i->key),
                'has_credentials' => $i->hasCredentials(),
                'configuration' => $i->configuration,
                'used_by_nodes' => $nodesByIntegration[$i->key] ?? [],
                'last_checked_at' => $i->last_checked_at?->toIso8601String(),
            ]),
            'demo_mode' => (bool) config('workflows.demo_mode'),
            'mailer' => config('mail.default'),
        ]);
    }

    /**
     * Store credentials (encrypted at rest, never returned by the API).
     */
    public function update(Request $request, WorkflowIntegration $integration, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('integrations.manage');

        $data = $request->validate([
            'credentials' => ['nullable', 'array', 'max:10'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'configuration' => ['nullable', 'array', 'max:20'],
            'clear_credentials' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('clear_credentials')) {
            $integration->credentials = null;
        } elseif (! empty($data['credentials'])) {
            $integration->credentials = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');
        }

        if (array_key_exists('configuration', $data)) {
            $integration->configuration = $integration->key === 'anthropic'
                ? $this->anthropicConfiguration($request, $integration)
                : $data['configuration'];
        }

        $integration->save();
        $audit->log('integration.updated', $integration, ['credential_keys' => array_keys($integration->credentials ?? []), 'cleared' => $request->boolean('clear_credentials')], $request->user());

        return response()->json(['message' => 'Integration saved. Credentials are stored encrypted.', 'has_credentials' => $integration->hasCredentials()]);
    }

    /**
     * @return array{enabled: bool, model: string, effort: string}
     */
    private function anthropicConfiguration(Request $request, WorkflowIntegration $integration): array
    {
        /** @var array{enabled?: bool, model?: string, effort?: string} $config */
        $config = $request->validate([
            'configuration.enabled' => ['required', 'boolean'],
            'configuration.model' => ['required', 'in:'.implode(',', self::CLAUDE_MODELS)],
            'configuration.effort' => ['required', 'in:low,medium,high,xhigh,max'],
        ])['configuration'];

        if (($config['enabled'] ?? false) && empty($integration->credentials['api_key'])) {
            throw ValidationException::withMessages(['configuration.enabled' => 'Enter and save an API key before enabling Claude.']);
        }

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'model' => (string) ($config['model'] ?? ClaudeTextGenerator::DEFAULT_MODEL),
            'effort' => (string) ($config['effort'] ?? 'low'),
        ];
    }

    public function test(WorkflowIntegration $integration, IntegrationManager $manager): JsonResponse
    {
        Gate::authorize('integrations.view');

        // Anthropic: a real, minimal request verifies the stored key (only on explicit click).
        if ($integration->key === 'anthropic' && ! empty($integration->credentials['api_key'])) {
            Gate::authorize('integrations.manage');
            $integration->forceFill(['last_checked_at' => now()])->save();

            try {
                $result = app()->make(ClaudeTextGenerator::class, [
                    'apiKey' => (string) $integration->credentials['api_key'],
                    'model' => (string) ($integration->configuration['model'] ?? ClaudeTextGenerator::DEFAULT_MODEL),
                    'effort' => 'low',
                ])->generate('generic', 'Reply with the single word OK.');
            } catch (NodeExecutionException $e) {
                return response()->json(['status' => 'error', 'ok' => false, 'message' => $e->getMessage()]);
            }

            return response()->json([
                'status' => $manager->status('anthropic'),
                'ok' => true,
                'message' => 'Claude responded ('.$result['model'].'). '.($manager->status('anthropic') === 'connected' ? 'AI Text nodes now use Claude.' : 'Enable the integration to use it in workflows.'),
            ]);
        }
        $status = $manager->status($integration->key);
        $integration->forceFill(['last_checked_at' => now()])->save();

        $message = match ($status) {
            'connected' => 'Integration is available.',
            'simulated' => 'Simulated adapter — works without credentials, produces sample output only.',
            'not_configured' => 'Not configured. See the integration description for required settings.',
            'unavailable' => 'No adapter is implemented for this provider yet.',
            default => 'Integration reported an error.',
        };

        return response()->json(['status' => $status, 'ok' => in_array($status, ['connected', 'simulated'], true), 'message' => $message]);
    }
}
