<?php

namespace App\Workflow\Contracts;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

/**
 * Contract every workflow node implements. Nodes are resolved exclusively through
 * App\Workflow\NodeRegistry, which only knows the classes whitelisted in
 * config/workflows.php — a definition can never instantiate an arbitrary class.
 */
interface NodeType
{
    public const IMPLEMENTED = 'implemented';

    public const SIMULATED = 'simulated';

    public const INTEGRATION_REQUIRED = 'integration_required';

    /** Unique, stable identifier stored in workflow definitions (e.g. "logic.if"). */
    public function type(): string;

    public function label(): string;

    /** trigger | logic | data | notification | ai_media | utility */
    public function category(): string;

    public function description(): string;

    /** Lucide icon name used by the editor. */
    public function icon(): string;

    /** implemented | simulated | integration_required */
    public function implementation(): string;

    /** Integration key from config('workflows.integrations') this node relies on, if any. */
    public function integration(): ?string;

    /**
     * Configuration fields rendered by the editor and validated server side.
     *
     * @return list<array<string, mixed>>
     */
    public function configSchema(): array;

    /**
     * Output handles (edge "sourceHandle" values) available for the given config.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function outputHandles(array $config): array;

    /** Whether the node accepts incoming connections. */
    public function acceptsInput(): bool;

    /**
     * Documentation of the produced output structure: field => description.
     *
     * @return array<string, string>
     */
    public function outputSchema(): array;

    /**
     * @param  array<string, mixed>  $config  raw (unresolved) configuration
     * @return array<string, string> field => error message
     */
    public function validateConfig(array $config): array;

    public function execute(NodeInput $input): NodeResult;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
