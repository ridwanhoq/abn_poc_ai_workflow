<?php

namespace App\Workflow\Integrations\Laravel;

use App\Workflow\Integrations\Contracts\EmailSender;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Real delivery through the configured Laravel mailer. Only selected when demo
 * mode is off, the run is a production run and the workflow opted in to
 * external side effects (see ExecutionContext::allowsExternalSideEffects()).
 */
class MailEmailSender implements EmailSender
{
    public function send(array $message): array
    {
        Mail::raw($message['body'], function (Message $mail) use ($message) {
            $mail->to($message['to'])->subject($message['subject']);
        });

        return [
            'delivered' => true,
            'simulated' => false,
            'adapter' => 'laravel-mail',
            'mailer' => config('mail.default'),
            'to' => $message['to'],
            'subject' => $message['subject'],
        ];
    }
}
