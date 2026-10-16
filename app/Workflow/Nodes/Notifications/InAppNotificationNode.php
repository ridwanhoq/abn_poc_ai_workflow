<?php

namespace App\Workflow\Nodes\Notifications;

use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Nodes\AbstractNode;

class InAppNotificationNode extends AbstractNode
{
    protected string $icon = 'bell-ring';

    public function type(): string
    {
        return 'notify.in_app';
    }

    public function label(): string
    {
        return 'Send In-App Notification';
    }

    public function category(): string
    {
        return 'notification';
    }

    public function description(): string
    {
        return 'Creates a notification in a platform user\'s bell menu.';
    }

    public function integration(): ?string
    {
        return 'in_app';
    }

    public function configSchema(): array
    {
        return [
            $this->field('recipient', 'Recipient (user ID or email)', 'text', ['required' => true, 'placeholder' => '{{ execution.initiated_by }}']),
            $this->field('title', 'Title', 'text', ['required' => true]),
            $this->field('message', 'Message', 'textarea', ['required' => true]),
            $this->field('level', 'Level', 'select', ['default' => 'info', 'expression' => false, 'options' => $this->options(['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'error' => 'Error'])]),
        ];
    }

    public function outputSchema(): array
    {
        return ['notified_user_id' => 'Recipient user ID'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $recipient = $input->get('recipient');
        $user = is_numeric($recipient)
            ? User::query()->find((int) $recipient)
            : User::query()->where('email', (string) $recipient)->first();

        // Recipients must belong to this workflow's workspace. Unknown and out-of-workspace
        // recipients get the same message, so the node cannot be used to probe for accounts.
        if ($user === null || ! $user->belongsToWorkspace($input->context->workflow->workspace_id)) {
            throw new NodeExecutionException('Notification recipient is not a member of this workspace.');
        }

        $user->notify(new WorkflowNotification(
            (string) $input->get('title'),
            (string) $input->get('message'),
            (string) $input->get('level', 'info'),
            ['workflow_id' => $input->context->workflow->id, 'execution_id' => $input->context->execution->id],
        ));

        return NodeResult::ok(['notified_user_id' => $user->id, 'title' => $input->get('title')]);
    }
}
