<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\EmailSender;
use Illuminate\Support\Facades\Log;

/**
 * Demo-mode email adapter: writes the intended email to the Laravel log.
 * Nothing is delivered.
 */
class LogEmailSender implements EmailSender
{
    public function send(array $message): array
    {
        $line = $message['log_message'] ?? null
            ?: "Email \"{$message['subject']}\" prepared for {$message['to']}.";

        Log::info("[Workflow: {$message['workflow']}] {$line}", [
            'to' => $message['to'],
            'subject' => $message['subject'],
            'adapter' => 'log',
        ]);

        return [
            'delivered' => false,
            'simulated' => true,
            'adapter' => 'log',
            'to' => $message['to'],
            'subject' => $message['subject'],
            'log_line' => "[Workflow: {$message['workflow']}] {$line}",
            'notice' => 'Logged only — no email was sent (demo mode / logging adapter).',
        ];
    }
}
