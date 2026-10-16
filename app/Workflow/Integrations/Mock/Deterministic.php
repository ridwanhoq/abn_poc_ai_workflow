<?php

namespace App\Workflow\Integrations\Mock;

/**
 * Helpers that make mock output deterministic: the same input always yields
 * the same "AI" result, which keeps demo runs and tests reproducible.
 */
trait Deterministic
{
    protected function seed(mixed ...$parts): int
    {
        return crc32(json_encode($parts) ?: '');
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    protected function pick(array $items, int $seed, int $offset = 0): mixed
    {
        return $items[($seed + $offset * 7919) % count($items)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    protected function pickMany(array $items, int $seed, int $count): array
    {
        $picked = [];
        for ($i = 0; count($picked) < min($count, count($items)); $i++) {
            $candidate = $this->pick($items, $seed, $i);
            if (! in_array($candidate, $picked, true)) {
                $picked[] = $candidate;
            }
            if ($i > 100) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @return array{simulated: true, provider: string, notice: string}
     */
    protected function simulatedMeta(string $what): array
    {
        return [
            'simulated' => true,
            'provider' => 'mock',
            'notice' => "Simulated {$what}: deterministic sample output, no external service was called.",
        ];
    }
}
