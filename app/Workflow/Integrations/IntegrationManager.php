<?php

namespace App\Workflow\Integrations;

use App\Models\WorkflowIntegration;
use App\Workflow\Engine\ExecutionContext;
use App\Workflow\Integrations\Anthropic\ClaudeTextGenerator;
use App\Workflow\Integrations\Contracts\EmailSender;
use App\Workflow\Integrations\Contracts\TextGenerator;
use App\Workflow\Integrations\Laravel\MailEmailSender;
use App\Workflow\Integrations\Mock\LogEmailSender;
use Illuminate\Database\QueryException;

/**
 * Picks adapters at runtime and reports integration status.
 */
class IntegrationManager
{
    /**
     * Email delivery: the logging adapter unless real side effects are explicitly allowed
     * AND the production mail integration is connected.
     */
    public function emailSender(ExecutionContext $context): EmailSender
    {
        if ($context->allowsExternalSideEffects() && $this->status('smtp_mail') === 'connected') {
            return app(MailEmailSender::class);
        }

        return app(LogEmailSender::class);
    }

    /**
     * Text generation: Claude when the "anthropic" integration has a key and is enabled,
     * otherwise the deterministic mock (so demo workflows never need credentials).
     */
    public function textGenerator(): TextGenerator
    {
        $integration = $this->anthropic();

        if ($integration === null || $this->status('anthropic') !== 'connected') {
            return app(TextGenerator::class);
        }

        $config = $integration->configuration ?? [];

        return app()->make(ClaudeTextGenerator::class, [
            'apiKey' => (string) ($integration->credentials['api_key'] ?? ''),
            'model' => (string) ($config['model'] ?? ClaudeTextGenerator::DEFAULT_MODEL),
            'effort' => (string) ($config['effort'] ?? 'low'),
        ]);
    }

    public function usesRealTextGeneration(): bool
    {
        return $this->status('anthropic') === 'connected';
    }

    public function status(string $key): string
    {
        $integration = $key === 'anthropic' ? $this->anthropic() : WorkflowIntegration::query()->where('key', $key)->first();

        if ($integration === null) {
            return 'unavailable';
        }

        if ($key === 'smtp_mail') {
            return ! in_array(config('mail.default'), ['log', 'array', null], true) ? 'connected' : 'not_configured';
        }

        if ($key === 'anthropic') {
            return ! empty($integration->credentials['api_key']) && ($integration->configuration['enabled'] ?? false) === true
                ? 'connected'
                : 'not_configured';
        }

        if ($key === 's3') {
            return config('workflows.uploads.disk') === 's3' && filled(config('filesystems.disks.s3.bucket'))
                ? 'connected'
                : 'not_configured';
        }

        if ($key === 'http') {
            return config('workflows.demo_mode') ? 'simulated' : 'connected';
        }

        return $integration->status;
    }

    private function anthropic(): ?WorkflowIntegration
    {
        try {
            return WorkflowIntegration::query()->where('key', 'anthropic')->first();
        } catch (QueryException) {
            // No database yet (fresh install / migrations pending): fall back to the mock adapter.
            return null;
        }
    }
}
