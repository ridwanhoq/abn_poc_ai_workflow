<?php

namespace Tests\Support;

use App\Workflow\Integrations\Anthropic\ClaudeTextGenerator;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\RequestInterface;

/**
 * Intercepts Claude API calls with SDK request middleware: no network, captured requests,
 * scripted responses.
 */
class FakeClaude
{
    /** @var list<array{headers: array<string, list<string>>, body: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<Response> */
    private array $responses = [];

    public static function json(array $payload, string $stopReason = 'end_turn', string $model = 'claude-opus-5-5'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'stop_details' => $stopReason === 'refusal' ? ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'declined'] : null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
        ]));
    }

    public static function error(int $status, string $type): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => 'test error'],
        ]));
    }

    public function push(Response $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOptions(): array
    {
        return [
            'maxRetries' => 0,
            'middleware' => [function (RequestInterface $request) {
                $this->requests[] = [
                    'headers' => $request->getHeaders(),
                    'body' => json_decode((string) $request->getBody(), true) ?? [],
                ];

                return array_shift($this->responses) ?? self::error(500, 'api_error');
            }],
        ];
    }

    public function generator(string $model = ClaudeTextGenerator::DEFAULT_MODEL, string $effort = 'low'): ClaudeTextGenerator
    {
        return new ClaudeTextGenerator('sk-ant-test', $model, $effort, $this->requestOptions());
    }

    /**
     * Make the container build every ClaudeTextGenerator against this fake.
     */
    public function bind(Application $app): self
    {
        $app->bind(ClaudeTextGenerator::class, fn ($app, array $params) => new ClaudeTextGenerator(
            $params['apiKey'] ?? 'sk-ant-test',
            $params['model'] ?? ClaudeTextGenerator::DEFAULT_MODEL,
            $params['effort'] ?? 'low',
            $this->requestOptions(),
        ));

        return $this;
    }
}
