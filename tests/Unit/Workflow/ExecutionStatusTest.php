<?php

namespace Tests\Unit\Workflow;

use App\Enums\ExecutionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExecutionStatusTest extends TestCase
{
    #[Test]
    public function transitions_follow_the_state_machine(): void
    {
        $this->assertTrue(ExecutionStatus::Queued->canTransitionTo(ExecutionStatus::Running));
        $this->assertTrue(ExecutionStatus::Queued->canTransitionTo(ExecutionStatus::Cancelled));
        $this->assertTrue(ExecutionStatus::Running->canTransitionTo(ExecutionStatus::Waiting));
        $this->assertTrue(ExecutionStatus::Waiting->canTransitionTo(ExecutionStatus::Running));
        $this->assertFalse(ExecutionStatus::Queued->canTransitionTo(ExecutionStatus::Success));
        $this->assertFalse(ExecutionStatus::Success->canTransitionTo(ExecutionStatus::Running));
        $this->assertFalse(ExecutionStatus::Cancelled->canTransitionTo(ExecutionStatus::Queued));
    }

    #[Test]
    public function finished_and_cancellable_states_are_disjoint(): void
    {
        foreach (ExecutionStatus::cases() as $status) {
            $this->assertFalse($status->isFinished() && $status->isCancellable(), $status->value);
        }
    }
}
