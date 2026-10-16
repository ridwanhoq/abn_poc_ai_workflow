<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Engine\ExpressionResolver;
use App\Workflow\Exceptions\ExpressionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpressionResolverTest extends TestCase
{
    private ExpressionResolver $resolver;

    private array $scope = [
        'trigger' => ['user_id' => 42, 'name' => 'ada lovelace', 'tags' => ['a', 'b']],
        'nodes' => ['find_user' => ['output' => ['email' => 'ada@example.test', 'records' => [['id' => 7, 'score' => 3], ['id' => 9, 'score' => 4]]], 'success_count' => 2]],
        'variables' => ['campaign_name' => 'Spring'],
        'item' => ['email' => 'item@example.test'],
        'index' => 3,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ExpressionResolver;
    }

    #[Test]
    public function it_resolves_documented_paths_with_types(): void
    {
        $this->assertSame(42, $this->resolver->resolve('{{ trigger.user_id }}', $this->scope));
        $this->assertSame('ada@example.test', $this->resolver->resolve('{{ nodes.find_user.output.email }}', $this->scope));
        $this->assertSame('Spring', $this->resolver->resolve('{{ variables.campaign_name }}', $this->scope));
        $this->assertSame(9, $this->resolver->resolve('{{ nodes.find_user.output.records[1].id }}', $this->scope));
        $this->assertSame(7, $this->resolver->resolve('{{ nodes.find_user.output.records.0.id }}', $this->scope));
        $this->assertSame(['a', 'b'], $this->resolver->resolve('{{ trigger.tags }}', $this->scope));
        $this->assertSame(3, $this->resolver->resolve('{{ index }}', $this->scope));
    }

    #[Test]
    public function it_interpolates_strings_and_resolves_nested_arrays(): void
    {
        $this->assertSame('Hi ada lovelace (#42)', $this->resolver->resolve('Hi {{ trigger.name }} (#{{ trigger.user_id }})', $this->scope));
        $this->assertSame(
            ['to' => 'item@example.test', 'meta' => ['n' => 42]],
            $this->resolver->resolve(['to' => '{{ item.email }}', 'meta' => ['n' => '{{ trigger.user_id }}']], $this->scope),
        );
    }

    #[Test]
    public function missing_paths_resolve_to_null_and_default_filter_applies(): void
    {
        $this->assertNull($this->resolver->resolve('{{ trigger.unknown.deep }}', $this->scope));
        $this->assertSame('n/a', $this->resolver->resolve("{{ trigger.unknown | default('n/a') }}", $this->scope));
        $this->assertSame(0, $this->resolver->resolve('{{ nodes.never_ran.success_count | default(0) }}', $this->scope));
    }

    #[Test]
    public function filters_are_applied_in_order(): void
    {
        $this->assertSame('ADA LOVELACE', $this->resolver->resolve('{{ trigger.name | upper }}', $this->scope));
        $this->assertSame(2, $this->resolver->resolve('{{ nodes.find_user.output.records | length }}', $this->scope));
        $this->assertSame([7, 9], $this->resolver->resolve("{{ nodes.find_user.output.records | pluck('id') }}", $this->scope));
        $this->assertSame('a|b', $this->resolver->resolve("{{ trigger.tags | join('|') }}", $this->scope));
        $this->assertSame('2026-01-03', $this->resolver->resolve("{{ '2026-01-01' | add_days(2) | date('Y-m-d') }}", $this->scope));
    }

    #[Test]
    public function it_rejects_unknown_roots_filters_and_code(): void
    {
        $this->assertNotNull($this->resolver->syntaxError('{{ app.config }}'));
        $this->assertNotNull($this->resolver->syntaxError('{{ trigger.name | system }}'));
        $this->assertNotNull($this->resolver->syntaxError('{{ phpinfo() }}'));
        $this->assertNotNull($this->resolver->syntaxError('{{ trigger.name'));
        $this->assertNull($this->resolver->syntaxError('{{ trigger.name | upper }}'));

        $this->expectException(ExpressionException::class);
        $this->resolver->resolve('{{ exec("ls") }}', $this->scope);
    }
}
