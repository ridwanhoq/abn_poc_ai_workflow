<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed, self::Cancelled], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Waiting], true);
    }

    /**
     * Allowed state machine transitions. Finished states are terminal.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Cancelled, self::Failed],
            self::Running => [self::Waiting, self::Success, self::Partial, self::Failed, self::Cancelled],
            self::Waiting => [self::Running, self::Cancelled, self::Failed],
            default => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
