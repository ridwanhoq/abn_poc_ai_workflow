<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Support\ChurnScorer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChurnScorerTest extends TestCase
{
    #[Test]
    public function inactive_low_engagement_subscribers_score_high(): void
    {
        $now = CarbonImmutable::parse('2026-10-16');
        $result = (new ChurnScorer)->score([
            'last_active_at' => '2026-07-01',
            'watch_minutes_30d' => 10,
            'failed_payments' => 1,
            'subscription_renews_at' => '2026-10-20',
            'plan' => 'basic',
        ], $now);

        $this->assertSame(40 + 25 + 10 + 15 + 5, $result['score']);
        $this->assertSame('high', $result['level']);
        $this->assertCount(5, $result['factors']);
    }

    #[Test]
    public function engaged_subscribers_score_low_and_score_is_capped(): void
    {
        $now = CarbonImmutable::parse('2026-10-16');
        $scorer = new ChurnScorer;

        $engaged = $scorer->score(['last_active_at' => '2026-10-15', 'watch_minutes_30d' => 900, 'plan' => 'premium'], $now);
        $this->assertSame(0, $engaged['score']);
        $this->assertSame('low', $engaged['level']);

        $worst = $scorer->score(['last_active_at' => null, 'watch_minutes_30d' => 0, 'failed_payments' => 5, 'subscription_renews_at' => '2026-10-17', 'plan' => 'basic'], $now);
        $this->assertSame(100, $worst['score']);
    }
}
