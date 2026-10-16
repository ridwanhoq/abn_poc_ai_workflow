<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Integrations\Laravel\SafeHttpClient;
use App\Workflow\Support\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHelpersTest extends TestCase
{
    #[Test]
    public function redactor_masks_secrets_and_personal_data(): void
    {
        $out = (new Redactor)->redact([
            'api_key' => 'sk-123',
            'nested' => ['password' => 'hunter2', 'email' => 'jane@example.test'],
            'note' => 'Authorization: Bearer abc.def',
            'count' => 3,
        ]);

        $this->assertSame('[REDACTED]', $out['api_key']);
        $this->assertSame('[REDACTED]', $out['nested']['password']);
        $this->assertSame('j***@example.test', $out['nested']['email']);
        $this->assertStringNotContainsString('abc.def', $out['note']);
        $this->assertSame(3, $out['count']);
    }

    #[Test]
    #[DataProvider('blockedUrls')]
    public function ssrf_guard_blocks_internal_targets(string $url): void
    {
        $this->expectException(NodeExecutionException::class);
        (new SafeHttpClient)->assertSafeUrl($url);
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin'],
            'private' => ['http://10.0.0.5/'],
            'metadata' => ['http://169.254.169.254/latest/meta-data'],
            'localhost' => ['http://localhost:8080'],
            'scheme' => ['file:///etc/passwd'],
            'credentials' => ['http://user:pass@93.184.216.34/'],
        ];
    }
}
