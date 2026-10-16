<?php

namespace App\Workflow\Support;

use App\Events\ExecutionUpdated;
use App\Models\WorkflowExecution;
use Throwable;

/**
 * Pushes ExecutionUpdated over WebSockets (Laravel Reverb) when broadcasting is
 * configured. Progress updates are throttled per execution, status changes are
 * always sent, and any broadcasting failure opens a short circuit breaker, so
 * a missing or slow Reverb server never slows down or fails a workflow.
 */
class ExecutionBroadcaster
{
    public const THROTTLE_MS = 750;

    public const BREAKER_SECONDS = 30;

    /** @var array<int, float> execution id => last broadcast (microtime) */
    private array $lastSent = [];

    private float $disabledUntil = 0.0;

    public function enabled(): bool
    {
        return in_array(config('broadcasting.default'), ['reverb', 'pusher', 'ably'], true)
            && microtime(true) >= $this->disabledUntil;
    }

    /**
     * @param  bool  $force  bypass throttling (status changes)
     */
    public function notify(WorkflowExecution $execution, bool $force = false): void
    {
        if (! $this->enabled()) {
            return;
        }

        $now = microtime(true);
        if (! $force && ($now - ($this->lastSent[$execution->id] ?? 0.0)) * 1000 < self::THROTTLE_MS) {
            return;
        }
        $this->lastSent[$execution->id] = $now;

        try {
            // Dispatch through the event dispatcher (not the broadcast() helper, which sends from a
            // destructor outside this try/catch) so a failing broadcast is always contained here.
            event(new ExecutionUpdated($execution));
        } catch (Throwable $e) {
            $this->disabledUntil = $now + self::BREAKER_SECONDS;
            logger()->warning('Execution broadcast failed; real-time updates paused for '.self::BREAKER_SECONDS.'s.', ['error' => $e->getMessage()]);
        }
    }
}
