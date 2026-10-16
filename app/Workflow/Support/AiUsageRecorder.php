<?php

namespace App\Workflow\Support;

use App\Models\AiUsage;
use App\Workflow\Engine\ExecutionContext;
use Throwable;

/**
 * Records token usage and an estimated cost for every AI call made by a node.
 * Recording problems never fail the workflow.
 */
class AiUsageRecorder
{
    /**
     * @param  array<string, mixed>  $output  adapter output containing provider/model/usage/simulated
     */
    public function record(ExecutionContext $context, string $nodeKey, array $output): void
    {
        $usage = is_array($output['usage'] ?? null) ? $output['usage'] : [];
        $simulated = (bool) ($output['simulated'] ?? true);
        $model = (string) ($output['model'] ?? 'unknown');
        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        try {
            AiUsage::query()->create([
                'execution_id' => $context->execution->id,
                'workflow_id' => $context->workflow->id,
                'workspace_id' => $context->workflow->workspace_id,
                'node_key' => $nodeKey,
                'task' => isset($output['task']) ? mb_substr((string) $output['task'], 0, 40) : null,
                'provider' => mb_substr((string) ($output['provider'] ?? 'unknown'), 0, 40),
                'model' => mb_substr($model, 0, 80),
                'simulated' => $simulated,
                'input_tokens' => $input,
                'output_tokens' => $outputTokens,
                'estimated_cost_usd' => $simulated ? 0 : $this->estimate($model, $input, $outputTokens),
                'mode' => $context->execution->mode,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function estimate(string $model, int $inputTokens, int $outputTokens): float
    {
        $price = config("workflows.ai_pricing.{$model}");

        if (! is_array($price)) {
            return 0.0;
        }

        return round(($inputTokens * (float) $price['input'] + $outputTokens * (float) $price['output']) / 1_000_000, 6);
    }
}
