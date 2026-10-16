<?php

namespace App\Workflow\Integrations\Laravel;

use App\Workflow\Exceptions\NodeExecutionException;
use Illuminate\Support\Facades\Http;

/**
 * Outbound HTTP with SSRF protection: only http(s), only public IPs (every
 * resolved address is checked), optional host allow-list, no redirects,
 * hard timeout.
 */
class SafeHttpClient
{
    /**
     * @return array{host: string, ips: list<string>}
     */
    public function assertSafeUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new NodeExecutionException('Only absolute http(s) URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new NodeExecutionException('URLs with embedded credentials are not allowed.');
        }

        $allowed = config('workflows.http.allowed_hosts', []);
        if ($allowed !== [] && ! in_array($host, $allowed, true)) {
            throw new NodeExecutionException("Host {$host} is not in the outbound allow-list.");
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw new NodeExecutionException('Requests to local hosts are blocked.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($ips === []) {
            throw new NodeExecutionException("Host {$host} could not be resolved.");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new NodeExecutionException('Requests to private, loopback or reserved addresses are blocked.');
            }
        }

        return ['host' => $host, 'ips' => $ips];
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    /**
     * @param  list<string>  $ips  validated public addresses for the URL's host
     * @return array<int, list<string>>
     */
    public function pinnedCurlOptions(string $url, array $ips): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? 'http')) === 'https' ? 443 : 80));
        $ip = (string) ($ips[0] ?? '');
        $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function send(string $method, string $url, array $headers = [], mixed $body = null): array
    {
        ['ips' => $ips] = $this->assertSafeUrl($url);

        // Pin the connection to the address that was validated, so a second DNS lookup
        // (DNS rebinding) cannot redirect the request to an internal host.
        $response = Http::withHeaders($headers)
            ->withOptions(['curl' => $this->pinnedCurlOptions($url, $ips)])
            ->timeout((int) config('workflows.http.timeout_seconds', 10))
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->send(strtoupper($method), $url, is_array($body) ? ['json' => $body] : ['body' => (string) $body]);

        return [
            'status' => $response->status(),
            'ok' => $response->successful(),
            'body' => mb_substr($response->body(), 0, 5000),
            'json' => $response->json(),
        ];
    }
}
