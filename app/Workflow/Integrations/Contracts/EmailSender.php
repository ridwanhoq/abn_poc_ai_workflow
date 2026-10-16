<?php

namespace App\Workflow\Integrations\Contracts;

interface EmailSender
{
    /**
     * @param  array{to: string, subject: string, body: string, workflow: string, log_message?: string|null}  $message
     * @return array<string, mixed> delivery report (must contain "delivered" and "simulated")
     */
    public function send(array $message): array;
}
