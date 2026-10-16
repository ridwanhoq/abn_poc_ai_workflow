<?php

namespace App\Workflow\Nodes\Notifications;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Integrations\IntegrationManager;
use App\Workflow\Integrations\Mock\LogEmailSender;
use App\Workflow\Nodes\AbstractNode;
use App\Workflow\Support\Redactor;

class SendEmailNode extends AbstractNode
{
    protected string $icon = 'mail';

    public function __construct(private readonly IntegrationManager $integrations) {}

    public function type(): string
    {
        return 'notify.email';
    }

    public function label(): string
    {
        return 'Send Email';
    }

    public function category(): string
    {
        return 'notification';
    }

    public function description(): string
    {
        return 'Demo mode: writes the intended email to the Laravel log. Real delivery needs demo mode off + workflow opt-in.';
    }

    public function implementation(): string
    {
        return self::SIMULATED;
    }

    public function integration(): ?string
    {
        return 'mock_email';
    }

    public function configSchema(): array
    {
        return [
            $this->field('to', 'To', 'text', ['required' => true, 'placeholder' => '{{ item.email }}']),
            $this->field('subject', 'Subject', 'text', ['required' => true]),
            $this->field('body', 'Body', 'textarea', ['required' => true]),
            $this->field('log_message', 'Log line (logging adapter)', 'text', ['help' => 'Optional custom text for the log entry. Prefixed with [Workflow: name].']),
        ];
    }

    public function outputSchema(): array
    {
        return ['delivered' => 'True only for real delivery', 'simulated' => 'True when only logged', 'to' => 'Recipient', 'adapter' => 'Adapter used'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $to = trim((string) $input->get('to', ''));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new NodeExecutionException("Invalid recipient email address: \"{$to}\".");
        }

        // TODO: Replace the logging adapter with Laravel Mail and the configured production mail provider.
        // IntegrationManager::emailSender() already switches to MailEmailSender when demo mode is off,
        // the run is a production run, the workflow allows external side effects and a real mailer is
        // configured; production rollout still needs a branded Mailable, queueing and bounce handling.
        $sender = $this->integrations->emailSender($input->context);

        $message = [
            'to' => $to,
            'subject' => (string) $input->get('subject'),
            'body' => (string) $input->get('body'),
            'workflow' => $input->context->workflow->name,
            'log_message' => $input->get('log_message'),
        ];

        // The logging adapter writes to the application log: never let secret variable values reach it.
        if ($sender instanceof LogEmailSender) {
            /** @var array{to: string, subject: string, body: string, workflow: string, log_message?: string|null} $message */
            $message = app(Redactor::class)->maskSecrets($message, $input->context->secrets);
        }

        $report = $sender->send($message);

        return NodeResult::ok($report);
    }
}
