<?php

namespace App\Workflow\Nodes\Notifications;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Integrations\Laravel\SafeHttpClient;
use App\Workflow\Nodes\AbstractNode;

class WebhookRequestNode extends AbstractNode
{
    protected string $icon = 'send';

    public function __construct(private readonly SafeHttpClient $http) {}

    public function type(): string
    {
        return 'notify.webhook';
    }

    public function label(): string
    {
        return 'Send Webhook Request';
    }

    public function category(): string
    {
        return 'notification';
    }

    public function description(): string
    {
        return 'Outbound HTTP request with SSRF protection. Simulated unless real side effects are allowed.';
    }

    public function implementation(): string
    {
        return self::SIMULATED;
    }

    public function integration(): ?string
    {
        return 'http';
    }

    public function configSchema(): array
    {
        return [
            $this->field('url', 'URL', 'text', ['required' => true, 'placeholder' => 'https://example.com/hooks/abc']),
            $this->field('method', 'Method', 'select', ['default' => 'POST', 'expression' => false, 'options' => $this->options(['POST', 'PUT', 'PATCH', 'GET'])]),
            $this->field('headers', 'Headers', 'keyvalue', ['default' => []]),
            $this->field('body', 'JSON body', 'json', ['default' => ['event' => 'workflow.completed']]),
        ];
    }

    public function outputSchema(): array
    {
        return ['status' => 'HTTP status (real mode)', 'simulated' => 'True when not sent'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $url = (string) $input->get('url');
        $method = (string) $input->get('method', 'POST');
        $headers = array_map('strval', (array) $input->get('headers', []));
        $body = $input->get('body');

        if (! preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) {
            throw new NodeExecutionException('URL must be an absolute http(s) URL.');
        }

        if (! $input->context->allowsExternalSideEffects()) {
            return NodeResult::ok([
                'simulated' => true,
                'sent' => false,
                'method' => $method,
                'url' => $url,
                'body' => $body,
                'notice' => 'Not sent: demo mode, test run, or the workflow does not allow external side effects.',
            ]);
        }

        // TODO: Add per-integration auth (HMAC signing / OAuth client credentials) and a
        // delivery log with replay support before using this for partner callbacks.
        return NodeResult::ok(['simulated' => false, 'sent' => true] + $this->http->send($method, $url, $headers, $body));
    }
}
