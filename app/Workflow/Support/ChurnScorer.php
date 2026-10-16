<?php

namespace App\Workflow\Support;

use Carbon\CarbonImmutable;

/**
 * Transparent, rule-based churn-risk score (0–100). This is deliberately NOT a
 * machine-learning model: there is no training data in this project.
 *
 * TODO: Replace with a trained churn-prediction model once behavioural analytics
 * (sessions, completion rates, device mix, support tickets) are available. Keep the
 * factor breakdown so retention teams can explain every score.
 */
class ChurnScorer
{
    /**
     * @param  array<string, mixed>  $subscriber
     * @return array{score: int, level: string, factors: list<array{factor: string, points: int}>}
     */
    public function score(array $subscriber, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $factors = [];

        $lastActive = isset($subscriber['last_active_at']) && $subscriber['last_active_at']
            ? CarbonImmutable::parse($subscriber['last_active_at'])
            : null;
        $daysInactive = $lastActive ? (int) $lastActive->diffInDays($now) : 365;

        $factors[] = ['factor' => "Inactive for {$daysInactive} days", 'points' => match (true) {
            $daysInactive >= 60 => 40,
            $daysInactive >= 30 => 30,
            $daysInactive >= 14 => 15,
            default => 0,
        }];

        $minutes = (int) ($subscriber['watch_minutes_30d'] ?? 0);
        $factors[] = ['factor' => "{$minutes} watch minutes in last 30 days", 'points' => match (true) {
            $minutes < 30 => 25,
            $minutes < 120 => 15,
            $minutes < 300 => 5,
            default => 0,
        }];

        $failed = (int) ($subscriber['failed_payments'] ?? 0);
        if ($failed > 0) {
            $factors[] = ['factor' => "{$failed} failed payment(s)", 'points' => min(20, $failed * 10)];
        }

        if (! empty($subscriber['subscription_renews_at'])) {
            $daysToRenewal = $now->diffInDays(CarbonImmutable::parse($subscriber['subscription_renews_at']), false);
            if ($daysToRenewal >= 0 && $daysToRenewal <= 7) {
                $factors[] = ['factor' => 'Renewal due within 7 days', 'points' => 15];
            }
        }

        if (($subscriber['plan'] ?? null) === 'basic') {
            $factors[] = ['factor' => 'Basic plan', 'points' => 5];
        }

        $factors = array_values(array_filter($factors, fn ($f) => $f['points'] > 0));
        $score = min(100, array_sum(array_column($factors, 'points')));

        return [
            'score' => $score,
            'level' => $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low'),
            'factors' => $factors,
        ];
    }
}
