<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Support\ChurnScorer;

class ChurnScoreNode extends AbstractAiNode
{
    protected string $icon = 'gauge';

    public function __construct(private readonly ChurnScorer $scorer) {}

    public function type(): string
    {
        return 'ai.churn_score';
    }

    public function label(): string
    {
        return 'Churn Risk Score';
    }

    public function description(): string
    {
        return 'Transparent rule-based churn score (0–100) with factor breakdown. Not a trained ML model.';
    }

    public function implementation(): string
    {
        return self::IMPLEMENTED;
    }

    public function configSchema(): array
    {
        return [
            $this->field('subscriber', 'Subscriber record', 'text', ['required' => true, 'placeholder' => '{{ item }}']),
        ];
    }

    public function outputSchema(): array
    {
        return ['score' => '0–100', 'level' => 'low | medium | high', 'factors' => 'Contributing rules', 'subscriber' => 'The scored record'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $subscriber = $input->get('subscriber');
        if (! is_array($subscriber)) {
            throw new NodeExecutionException('Subscriber must resolve to a record object.');
        }

        return NodeResult::ok($this->scorer->score($subscriber) + ['subscriber' => $subscriber, 'method' => 'rule_based_v1']);
    }
}
