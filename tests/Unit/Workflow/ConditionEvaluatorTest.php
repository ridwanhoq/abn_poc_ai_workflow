<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Engine\ConditionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    #[Test]
    public function it_evaluates_operators(): void
    {
        $e = new ConditionEvaluator;

        $this->assertTrue($e->evaluate('5', 'equals', 5));
        $this->assertTrue($e->evaluate(70, 'gte', '60'));
        $this->assertFalse($e->evaluate(10, 'gt', 60));
        $this->assertTrue($e->evaluate('hello world', 'contains', 'world'));
        $this->assertTrue($e->evaluate('b', 'in', 'a, b, c'));
        $this->assertTrue($e->evaluate(null, 'is_empty'));
        $this->assertTrue($e->evaluate('user@example.test', 'is_email'));
        $this->assertFalse($e->evaluate('invalid-email.demo', 'is_email'));
        $this->assertTrue($e->evaluate('invalid-email.demo', 'is_not_email'));
    }

    #[Test]
    public function it_combines_conditions_with_all_and_any(): void
    {
        $e = new ConditionEvaluator;
        $conditions = [
            ['left' => 1, 'operator' => 'equals', 'right' => 1],
            ['left' => 1, 'operator' => 'equals', 'right' => 2],
        ];

        $this->assertFalse($e->evaluateAll($conditions, 'all'));
        $this->assertTrue($e->evaluateAll($conditions, 'any'));
        $this->assertTrue($e->evaluateAll([], 'all'));
    }
}
