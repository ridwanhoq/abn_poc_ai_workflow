<?php

namespace App\Console\Commands;

use App\Models\Workflow;
use App\Services\ExecutionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:run {workflow : Workflow ID or slug} {--payload= : JSON trigger payload (defaults to the trigger sample payload)} {--queue : Dispatch to the queue instead of running inline}')]
#[Description('Run a workflow from the CLI as a test run and print the result.')]
class RunWorkflowCommand extends Command
{
    public function handle(ExecutionService $executions): int
    {
        $argument = $this->argument('workflow');
        $ref = is_string($argument) ? $argument : '';
        $workflow = Workflow::query()->where('id', $ref)->orWhere('slug', $ref)->first();

        if ($workflow === null) {
            $this->error("Workflow {$ref} not found.");

            return self::FAILURE;
        }

        $option = $this->option('payload');
        $payload = is_string($option) && $option !== '' ? json_decode($option, true, 512, JSON_THROW_ON_ERROR) : null;
        $payload ??= (array) ($workflow->triggerNode()['config']['sample_payload'] ?? []);

        $execution = $executions->start($workflow, 'manual', $payload, null, 'test', null, [], ! $this->option('queue'));

        $this->line("Execution #{$execution->id} — status: <info>{$execution->status->value}</info>");
        if ($execution->error_message) {
            $this->warn($execution->error_message);
        }
        if ($execution->result) {
            $this->line((string) json_encode($execution->result, JSON_PRETTY_PRINT));
        }

        return $execution->status->value === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
