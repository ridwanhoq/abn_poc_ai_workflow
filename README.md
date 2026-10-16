# FlowForge AI — Workflow Automation for OTT & ERP

A visual, backend-executed workflow automation platform built with **Laravel 13**, **Vue 3 (Options API)**,
**Vue Flow** and **Tailwind CSS v4**. Users drag nodes onto a canvas, connect and configure them, and the
Laravel queue executes the saved graph with branching, loops, retries, timeouts, cancellation and full
node-by-node execution history.

Six complete OTT and ERP workflow templates are seeded. **They all run locally without paid APIs.** Every AI,
media and email step that is not really implemented uses a deterministic _mock adapter_, and each result is labelled
`simulated`.

---

## Contents

1. [Features](#features)
2. [Quick start](#quick-start)
3. [Environment configuration](#environment-configuration)
4. [Database, migrations and seeding](#database-migrations-and-seeding)
5. [Queue worker and scheduler](#queue-worker-and-scheduler)
6. [Workflow builder guide](#workflow-builder-guide)
7. [The six default templates — import & test](#the-six-default-templates--import--test)
8. [Node catalogue](#node-catalogue)
9. [Node developer guide](#node-developer-guide)
10. [Integration developer guide](#integration-developer-guide)
11. [REST API](#rest-api)
12. [Security model](#security-model)
13. [Testing](#testing)
14. [Production deployment](#production-deployment)
15. [Known limitations](#known-limitations)

---

## Features

| Area             | What you get                                                                                                                                                                                                                                                                                                                                                                                    |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Visual builder   | Drag & drop / click-to-add node library, connections with cycle prevention, minimap, zoom/pan, snap grid, per-node config panel with schema-driven fields, expression helper, live server-side validation badges, undo/redo (⌘/Ctrl+Z, ⇧⌘Z), duplicate (⌘D), delete, unsaved-change guard, ⌘S save, version history with restore                                                                |
| Execution engine | Queue-backed (`RunWorkflowJob`), topological execution, IF/Switch branching, Loop with batched DB cursors, Merge, Delay with _waiting_ state + queued resume, retries with exponential backoff, failure policies (stop / continue → _partial_), timeouts, node-execution limit, cooperative cancellation, subworkflows with recursion guard, idempotency keys, execute-from-node, Return Output |
| Triggers         | Manual, Schedule (cron + timezone), signed Webhook (HMAC + rate limit + idempotency), Database event (created/updated/deleted + field match), File uploaded, User created, User verification pending                                                                                                                                                                                            |
| Observability    | Dashboard KPIs, activity chart, filters, execution list & detail (timeline, diagram overlay, inputs/outputs, errors, logs), top-bar run indicator, in-app notifications on failure, audit log                                                                                                                                                                                                   |
| Access control   | Super Admin / Admin / Workflow Manager / Viewer, database-backed permissions, policies on every endpoint, workspace scoping, ownership rules                                                                                                                                                                                                                                                    |
| UX               | Work-management-style UI, light & dark mode, responsive layout, skeleton loaders, empty states, toasts and confirmation dialogs (no `alert()`/`confirm()`)                                                                                                                                                                                                                                      |

---

## Quick start

Requirements: PHP 8.3+ (8.4 tested), Composer, Node 20+ (22 tested), MySQL 8+ (9.3 tested).

```bash
composer install
npm install
cp .env.example .env          # MySQL defaults: database abn_ai_workflow, user root
php artisan key:generate

mysql -uroot -e "CREATE DATABASE IF NOT EXISTS abn_ai_workflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate --seed

npm run build                  # or `npm run dev` for hot reload
composer dev                   # server + queue listener + log tail + vite
php artisan schedule:work      # in another terminal — runs scheduled triggers
```

Open the app (`php artisan serve` defaults to <http://127.0.0.1:8000>) and sign in.

### Demo accounts (password `password`)

| Email                       | Role             | Notes                                                     |
| --------------------------- | ---------------- | --------------------------------------------------------- |
| `superadmin@flowforge.test` | Super Admin      | Unrestricted                                              |
| `admin@flowforge.test`      | Admin            | Manages everything incl. users (cannot grant Super Admin) |
| `manager@flowforge.test`    | Workflow Manager | Creates, edits, activates and runs **own** workflows      |
| `viewer@flowforge.test`     | Viewer           | Read-only, OTT workspace only                             |

Nine more demo end-users (`*.unverified@example.test`, `*.verified@example.test`, and one with the deliberately
invalid email `invalid-email.demo`) exist for the _Unverified User Reminder_ template. They use the reserved
`.test` TLD and cannot log in.

Self-registered accounts become **Viewers without workspace membership**, so they see nothing until an admin grants access.

---

## Environment configuration

Standard Laravel variables apply. Platform-specific ones (see `config/workflows.php`):

| Variable                      | Default    | Purpose                                                                                                                                                                                                                            |
| ----------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WORKFLOW_DEMO_MODE`          | `true`     | **Safety switch.** While on, every email is logged (never sent) and every outbound HTTP request is simulated, for every workflow.                                                                                                  |
| `WORKFLOW_RETENTION_DAYS`     | `30`       | Finished executions older than this are removed by the daily `model:prune`.                                                                                                                                                        |
| `WORKFLOW_QUEUE`              | `default`  | Queue name for `RunWorkflowJob`.                                                                                                                                                                                                   |
| `WORKFLOW_MAX_UPLOAD_KB`      | `51200`    | Max video upload size. Allowed MIME types are in `config/workflows.php`.                                                                                                                                                           |
| `WORKFLOW_UPLOAD_DISK`        | `local`    | Disk for uploaded media: `local` or `s3` (set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`; add `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT=true` for S3-compatible services such as MinIO). |
| `WORKFLOW_WEBHOOK_MAX_KB`     | `256`      | Max webhook request body; larger payloads get 413 before anything is stored.                                                                                                                                                       |
| `WORKFLOW_HTTP_ALLOWED_HOSTS` | _(empty)_  | Optional comma-separated allow-list for the Send Webhook Request node.                                                                                                                                                             |
| `QUEUE_CONNECTION`            | `database` | Any Laravel queue driver works; `redis` is recommended in production.                                                                                                                                                              |
| `MAIL_MAILER`                 | `log`      | `log`/`array` count as "not configured" for the production mail integration.                                                                                                                                                       |

Real side effects require **all** of the following:

1. `WORKFLOW_DEMO_MODE=false`
2. A production (non-test) run
3. The workflow setting _Allow real external side effects_ (the editor asks you to confirm when you turn it on)
4. For email: a real `MAIL_MAILER`

---

## Database, migrations and seeding

```bash
php artisan migrate            # schema
php artisan db:seed            # roles, users, workspaces, integrations, demo data, templates
php artisan migrate:fresh --seed   # reset everything (local only)
```

Seeders are **idempotent**: they use `updateOrCreate` keyed on stable identifiers, so running them repeatedly never
duplicates templates or users. Model events are disabled while seeding, so **seeding never starts a workflow**.

Key tables: `workflows` (the JSON `definition` is the single source of truth for nodes and edges),
`workflow_versions`, `workflow_executions`, `workflow_execution_steps`, `workflow_execution_logs`,
`workflow_templates`, `workflow_schedules`, `workflow_integrations` (encrypted credentials), `workflow_variables`
(encrypted secrets), `roles`, `permissions`, `permission_role`, `workspaces`, `workspace_user`, `audit_logs`,
`notifications`, and the demo OTT/ERP tables (`media_assets`, `contents`, `subscribers`, `employees`,
`leave_requests`, and the task/result tables the templates write to).

`users.verified_at` (nullable, indexed) is the **profile verification** column used by the reminder template. It is
separate from Fortify's `email_verified_at`.

---

## Queue worker and scheduler

Workflows always execute on the queue, including manual test runs.

```bash
php artisan queue:work --tries=1          # production: run under Supervisor/systemd
php artisan schedule:work                 # local development
```

Production cron entry (one line, every minute):

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs:

- `workflows:run-schedules` every minute (`withoutOverlapping`). Each due `workflow_schedules` row is _claimed_ with a
  conditional update and dispatched with an idempotency key (`schedule:{id}:{slot}`), so a slot is never dispatched twice.
- `model:prune` daily for execution retention.

Useful CLI commands:

```bash
php artisan workflows:run {id|slug}                     # inline test run; prints the result
php artisan workflows:run 4 --payload='{"x":1}' --queue # queue it instead
php artisan workflows:run-schedules                     # dispatch due schedules now
```

If an execution stays **Queued**, no worker is running. The top-bar indicator shows _Worker idle?_ when queued jobs are
older than a minute.

---

## Workflow builder guide

**Canvas.** Drag nodes from the library, or click them to append. Connect an output handle (right) to an input
handle (left). Cycles, duplicate connections and inputs into triggers are rejected. Select a node to configure it in
the right-hand panel, which has Parameters, Settings (key, error policy, retries, backoff), Output (last run) and Info
(outputs, schema, integration).

**One trigger per workflow.** The trigger type is derived from the trigger node.

**Output handles.** Most nodes have one output (`main`). IF has `true` and `false`. Switch has `case_0…case_n` and
`default`. Loop has `each` (runs once per item) and `done` (runs once afterwards). Stop has none.

**Branching semantics.** A node runs when at least one incoming connection is _active_, meaning its source succeeded
and emitted that handle. Otherwise the node is recorded as **skipped**. Merge runs after all its branches are decided
and combines the active ones.

**Expressions.** Any text field accepts `{{ … }}`. Use the `{{ }}` button next to a field to insert a reference.

| Expression                                               | Meaning                                          |
| -------------------------------------------------------- | ------------------------------------------------ |
| `{{ trigger.user_id }}`                                  | Trigger payload                                  |
| `{{ nodes.find_user.output.email }}`                     | Output of node `find_user`                       |
| `{{ nodes.find_users.output.records[0].id }}`            | Index access (also `.0.`)                        |
| `{{ nodes.send_email.success_count }}`                   | Also `runs`, `error_count`, `status`             |
| `{{ variables.campaign_name }}`                          | Set Variables node or global/workspace variables |
| `{{ item.email }}` / `{{ index }}`                       | Current loop item / index                        |
| `{{ execution.id }}`, `{{ workflow.name }}`, `{{ now }}` | Context                                          |
| `{{ trigger.name \| upper \| default('n/a') }}`          | Filter pipeline                                  |

Filters: `upper lower title trim slug default json length count first last join round int float string bool date
add_days sub_days keys pluck sum slice replace truncate split not`. A field that contains exactly one expression
receives the typed value (an array, number or boolean). Otherwise values are interpolated as text. Nothing is ever
`eval`'d, and unknown roots or filters are rejected by validation.

**Validation.** It runs on the server while you edit. Errors appear as red badges on nodes and in the _issues_
popover. Drafts can be saved with errors, but **activation is refused** until the workflow is valid, and an active
workflow can't be saved in an invalid state.

**Running.**

- _Test run_ executes the **saved** version with a JSON payload, pre-filled from the trigger's sample payload. Unsaved
  changes are saved first.
- Test runs never produce real side effects.
- Node statuses, durations and outputs stream onto the canvas.
- _Execute from this node_ (Output tab) re-runs a node and its descendants, reusing upstream outputs from the last execution.

**Settings** (sliders icon): timeout, max node executions, failure policy, default retries and backoff, and the
real-side-effects opt-in. **Versions** (history icon): every definition or settings change stores a snapshot, and any
snapshot can be restored.

---

## The six default templates — import & test

Open **Templates**, then _Preview_, then _Import & test run_ (or _Import & configure_). Importing always creates a
**new draft workflow**; the template is never modified. Each template's trigger node has a sample payload that points
at seeded demo records, so a test run works immediately. CLI alternative: `php artisan workflows:run {slug}`.

| #   | Template                                          | Trigger                                                | What to verify after a test run                                                                                                                                                                                                                                                                                    |
| --- | ------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | **Automated Video Metadata & Localization** (OTT) | File uploaded                                          | `media_asset_insights` row with summary/genres/tags/moderation, 4 `localization_tasks`, asset status `metadata_ready`, result says the AI steps are SIMULATED. Upload a video under _Data & Variables_ to trigger it for real once it is active.                                                                   |
| 2   | **AI Thumbnail & Trailer Generation** (OTT)       | DB event `contents.updated` where `status = published` | A `thumbnail_tasks` row (`is_simulated`, no image URL) and a `trailer_requests` row with a 6-scene storyboard. Run with payload `{"id": <draft content id>}` to see the IF branch stop the run as failed.                                                                                                          |
| 3   | **Intelligent Churn Prevention & Win-Back** (OTT) | Schedule `0 8 * * *`                                   | Inactive subscribers stream through the loop in batches, each gets a **rule-based** score, and high-risk ones get a `retention_campaigns` row plus a log line.                                                                                                                                                     |
| 4   | **Unverified User Reminder** (ERP)                | Schedule `0 9 * * *`                                   | Only `users.verified_at IS NULL` users are processed, in `chunkById`-style batches of 100. Each valid address logs `[Workflow: Unverified User Reminder] Email reminder prepared for … (User ID: …).` to `storage/logs/laravel.log`. The invalid address is skipped and recorded. `verified_at` is never modified. |
| 5   | **AI-Driven Employee Onboarding** (ERP)           | DB event `employees.created`                           | Switch on department → department task list → merged → `onboarding_tasks` rows, role checklist, logged HR welcome email.                                                                                                                                                                                           |
| 6   | **Employee Leave Request Notification** (ERP)     | DB event `leave_requests.created`                      | Reviewer resolved from the request or the employee's manager, a `reviewer_notifications` row, a log line.                                                                                                                                                                                                          |

To run templates automatically, **activate** the imported workflow: schedules start ticking, DB events fire on new
records, and uploads trigger file workflows. In demo mode, **Data & Variables → Event simulator** fires the
Database Event triggers from the UI. It can publish demo content (template 2), create a demo employee (template 5) or
submit a leave request (template 6), then links straight to the executions it started. The simulator only touches
`is_demo` records and is unavailable when `WORKFLOW_DEMO_MODE=false`.

---

## Node catalogue

`implemented` means real behaviour. `simulated` means a mock adapter returns deterministic sample output that is
labelled `simulated: true`.

| Category      | Node (type)                                                                                                              | Status                                                                              |
| ------------- | ------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------- |
| Triggers      | Manual, Schedule, Webhook, Database Event, File Uploaded, User Created, User Verification Pending                        | implemented                                                                         |
| Logic         | IF/Else, Filter, Switch, Delay/Wait, Loop/Iterate, Merge, Set Variables                                                  | implemented                                                                         |
| Data          | Get / Find / Create / Update Database Record, Insert Execution Log, Transform Data, Map Fields                           | implemented (whitelisted models & columns)                                          |
| Notifications | Write Laravel Log, Send In-App Notification                                                                              | implemented                                                                         |
| Notifications | Send Email                                                                                                               | simulated (logging adapter); real delivery via Laravel Mail behind the safety gates |
| Notifications | Send Webhook Request                                                                                                     | simulated unless real side effects allowed; SSRF-protected                          |
| AI & media    | AI Text, AI Image, Video Metadata, Transcription, Frame Analysis, Thumbnail, Trailer, Subtitles, Moderation, Translation | simulated                                                                           |
| AI & media    | Churn Risk Score                                                                                                         | implemented (transparent rules, **not** ML)                                         |
| Utilities     | Execute Subworkflow, Stop Workflow, Return Output                                                                        | implemented                                                                         |

---

## Node developer guide

Nodes implement `App\Workflow\Contracts\NodeType` (usually by extending `App\Workflow\Nodes\AbstractNode`) and are
**whitelisted** in `config('workflows.nodes')`. The registry never instantiates classes named in a workflow definition.

```php
class SlackMessageNode extends AbstractNode
{
    protected string $icon = 'send';

    public function type(): string { return 'notify.slack'; }          // stable id stored in definitions
    public function label(): string { return 'Send Slack Message'; }
    public function category(): string { return 'notification'; }     // trigger|logic|data|notification|ai_media|utility
    public function description(): string { return 'Posts to a channel.'; }
    public function implementation(): string { return self::SIMULATED; }
    public function integration(): ?string { return 'slack'; }

    public function configSchema(): array
    {
        return [
            $this->field('channel', 'Channel', 'text', ['required' => true]),
            $this->field('text', 'Message', 'textarea', ['required' => true]),
        ];
    }

    protected function rules(array $config): array { return []; }      // extra validation → field => message

    public function execute(NodeInput $input): NodeResult
    {
        // $input->get('text') is already expression-resolved; $input->data is the upstream output.
        return NodeResult::ok(['sent' => false, 'simulated' => true]);
    }
}
```

- **Field types:** `text textarea number select boolean json keyvalue conditions filters list cases`. Options:
  `required`, `default`, `options`, `min`, `max`, `help`, `placeholder`. Set `expression => false` to forbid
  `{{ }}`, and `resolve => false` to resolve the field yourself, per item, via `$input->resolveRaw('key', ['item' => …])`.
- **Results:** `NodeResult::ok($output)`, `::branch($output, ['true'])`, `::stop($output, 'failed')`,
  `::wait($output, $seconds)`, `::iterate($output, $items)`.
- **Errors:** throw `NodeExecutionException` for user-facing failures. Retries and the failure policy are applied by the engine.
- **Output handles:** override `outputHandles(array $config)`.
- **Registration:** register the class in `config/workflows.php`. The editor picks up schema, icon (any Lucide name
  listed in `resources/js/lib/icons.js`), category colour and handles from `GET /api/v1/node-types`.

## Real-time execution updates (optional)

With Laravel Reverb configured, the editor, execution pages, executions list and top-bar indicator update
immediately over WebSockets. Polling remains as a fallback and slows down while the socket is connected.

1. In `.env`: `BROADCAST_CONNECTION=reverb` and random values for `REVERB_APP_ID/KEY/SECRET`. The `VITE_REVERB_*`
   values mirror them for the browser.
2. `npm run build` (Vite embeds the key), then run `php artisan reverb:start`. `composer dev` starts it for you.
3. Restart queue workers so they pick up the new configuration.

Design notes:

- **Payload:** events (`execution.updated`) carry only `id`, `workflow_id` and `status`; clients re-fetch through the REST API.
- **Channels:** `private-executions.{id}` and `private-workspaces.{id}.executions` are authorized with the same rules as the API.
- **Throttling:** status changes are always sent, step progress at most every 750 ms per execution.
- **Failures:** broadcasting uses 1–2 s timeouts, and any failure pauses broadcasts for 30 s (circuit breaker), so an
  unreachable Reverb server never slows down or fails a run. Without Reverb configured, nothing is sent and the UI polls as before.
- **Port conflicts:** if another service already uses the port, set `REVERB_PORT` and `REVERB_SERVER_PORT` to a free one.

## Real AI text generation with Claude (optional)

The **AI Text Generation** node (and the AI Playground's text tool) can call the Claude API instead of the mock:

1. **Integrations → Anthropic Claude → Configure**: paste an API key (stored with an `encrypted:array` cast, never
   returned by the API). Choose the model (default `claude-opus-5-5`) and effort (default `low`, which suits these short copy tasks).
2. Tick **Use Claude in workflows** and save. Enabling is refused until a key is stored. **Check** sends one tiny real
   request to verify the key.
3. From then on, every AI Text node calls Claude, in production and test runs alike, and its outputs carry
   `simulated: false, provider: "anthropic"` plus token usage. Untick it to return to the deterministic mock. Demo
   workflows never need a key.

Implementation: `App\Workflow\Integrations\Anthropic\ClaudeTextGenerator`, built on the official PHP SDK (`anthropic-ai/sdk`).

- **Structured outputs:** every task uses a strict JSON schema, so the fields templates reference (`summary`, `genres`,
  `tags`, `prompt`, `subject`, `checklist`, …) are identical to the mock's.
- **Prompt safety:** workflow data is wrapped in `<context>` and the system prompt treats it as data, not instructions.
- **Refusals:** server-side refusal fallback is enabled (`fallbacks: "default"`).
- **Timeouts and retries:** 60 s timeout and 2 SDK retries. API errors (bad key, rate limit, refusal, timeout) fail the node
  with a readable message, so the node's retry and failure policy apply.

### AI usage & cost reporting

Every AI Text call (mock or Claude) writes a row to `ai_usage`: provider, model, input/output tokens, whether it was
simulated, and an estimated cost from `config('workflows.ai_pricing')` (USD per 1M tokens; simulated calls cost $0).
The **Overview** shows calls, tokens and estimated cost for the selected range and workspace, broken down by model.
Each execution's _Trigger & result_ tab lists its AI calls. Usage rows keep their `workflow_id` when executions are
pruned, so cost history outlives execution retention. Estimates are based on list prices; check the Anthropic Console for billed amounts.

## Integration developer guide

External boundaries are interfaces in `app/Workflow/Integrations/Contracts` (`TextGenerator`, `ImageGenerator`,
`VideoProcessor`, `Transcriber`, `ContentModerator`, `Translator`, `EmailSender`). Mock implementations are bound in
`App\Providers\WorkflowServiceProvider::$singletons`. To add a real provider:

1. Implement the interface (e.g. `App\Workflow\Integrations\Anthropic\ClaudeTextGenerator`) with timeouts,
   retry/backoff for 429/5xx, and structured error mapping to `NodeExecutionException`.
2. Read credentials from the `workflow_integrations` row (`credentials` is an `encrypted:array` cast, set via the
   Integrations page) or from environment variables. Never store secrets in node config.
3. Swap the binding in `WorkflowServiceProvider`, or select it at runtime in `IntegrationManager`, like `emailSender()`.
4. Update the integration status logic in `IntegrationManager::status()` and remove the `simulated` label from the node.

Every mock adapter carries a `TODO:` comment describing the target provider, credentials, request/response and error handling.

---

## REST API

Session-authenticated, CSRF-protected (Axios sends `X-XSRF-TOKEN`), versioned under `/api/v1`. Errors are JSON
`{ message, errors }`, and stack traces are never returned.

```
GET    /dashboard                                   KPIs, activity, recent runs, suggestions (filters: date_from, date_to, workflow_id, status, trigger_type)
GET    /workflows                                   list (q, status, trigger_type, category, mine, sort, direction, per_page)
POST   /workflows                                   create
GET    /workflows/{id}                              show (definition, settings, webhook info for editors, abilities)
PUT    /workflows/{id}                              update (name, description, definition, settings) → new version
DELETE /workflows/{id}                              soft delete
POST   /workflows/{id}/validate                     validate saved or posted definition
POST   /workflows/{id}/activate | /deactivate
POST   /workflows/{id}/duplicate
POST   /workflows/{id}/execute                      { payload?, test?, start_node_key? } → 202
GET    /workflows/{id}/executions | /versions
POST   /workflows/{id}/versions/{version}/restore
POST   /workflows/{id}/webhook/rotate
GET    /executions                                  list (status, workflow_id, trigger_type, mode, date range)
GET    /executions/status                           queued/running/waiting counters
GET    /executions/{id}                             detail: steps, node summary, logs, definition
POST   /executions/{id}/cancel | /retry
GET    /templates | /templates/{id}
POST   /templates/{id}/import
GET    /node-types                                  catalogue + data models + operators
GET    /integrations ; PUT /integrations/{id} ; POST /integrations/{id}/test
GET|POST /variables ; PUT|DELETE /variables/{id}
GET    /data ; GET /data/{alias}
GET    /media-assets ; POST /media-assets (multipart upload → File Uploaded trigger)
GET    /demo-events                      demo records for the simulator (demo mode only)
POST   /demo-events/publish-content | /demo-events/employees | /demo-events/leave-requests
POST   /playground
GET    /search?q=
GET    /notifications ; POST /notifications/{id}/read ; POST /notifications/read-all
GET    /workspaces ; PUT /workspaces/current
GET    /workspaces/manage ; POST /workspaces ; PUT|DELETE /workspaces/{id} ; PUT /workspaces/{id}/members   (workspaces.manage)
GET    /users ; PUT /users/{id}          (users.manage)
GET    /audit-logs                       (audit.view)

POST   /api/v1/hooks/{token}             public webhook — header X-Workflow-Signature: sha256=<HMAC_SHA256(raw body, secret)>,
                                         optional Idempotency-Key; 60 req/min per token+IP
```

Example webhook call:

```bash
BODY='{"order_id": 42}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -X POST "$APP_URL/api/v1/hooks/$TOKEN" -H "Content-Type: application/json" \
     -H "X-Workflow-Signature: sha256=$SIG" -H "Idempotency-Key: order-42" -d "$BODY"
```

---

## Security model

- **Authorization on the server.** Policies (`WorkflowPolicy`, `WorkflowExecutionPolicy`) plus permission gates on
  every endpoint and page. Super Admin bypasses checks via `Gate::before`. Hidden buttons are only cosmetic.
- **Workspaces.** Super Admins and Admins (`workspaces.manage`) create, rename and delete workspaces and manage members
  under _Settings → Workspaces_. Admins only manage workspaces they belong to and cannot remove themselves. Workspaces
  that still contain workflows cannot be deleted, and removed members lose access immediately.
- **Workspace scoping.** Users only see workflows and executions of workspaces they belong to. Managers can only
  change workflows they created; Admins hold `workflows.manage_all`. Only a Super Admin can grant Super Admin.
- **A workflow can only do what its editor may do.** Saving (create, edit, import, duplicate, restore) is refused
  when a data node reads the cross-workspace `users` directory without `users.manage`, or a Subworkflow node calls a
  workflow the editor may not run. This is why Workflow Managers cannot import the _Unverified User Reminder_ template.
  In-app notifications only reach members of the workflow's workspace.
- **Every workflow belongs to a workspace.** Creation falls back from the requested workspace to the current one, then
  to the user's first membership, and is refused otherwise. Legacy workspace-less workflows are visible to Super Admins only.
- **Deleted workflows never run again:** retries, test runs and triggers are refused once a workflow is deleted.
- **No code execution.** Node classes come from a whitelist. Expressions use a parser with fixed roots and filters (no `eval`).
- **Data access.** The Data & Variables browser hides the cross-workspace `users` directory unless the user has
  `users.manage`. Data nodes reach only whitelisted models and columns (`config/workflows.php › data_models`). All
  filters are bound parameters. `users` is read-only, so workflows can never change `verified_at`.
- **SSRF.** Outbound HTTP is http(s) only, with no credentials in URLs, no redirects, every resolved IP checked against
  private/reserved ranges and the connection pinned to the validated IP (no DNS-rebinding window), an optional host allow-list and a 10 s timeout.
- **Secrets.** Integration credentials, secret variables and webhook secrets use encrypted casts. The engine's resume
  snapshot is encrypted. API responses never include credentials.
- **Secret variables** are masked as `[REDACTED]` wherever their _values_ appear: step inputs/outputs, results,
  execution logs, the Write Laravel Log node and the logging email adapter. Real deliveries still use the actual value.
- **Redaction.** Stored step inputs/outputs, trigger data and logs mask secret-looking keys and personal data such as
  email and phone, and are size-capped.
- **Uploads.** MIME allow-list, size limit, random file names, local private disk.
- **Webhooks.** Body size cap (`WORKFLOW_WEBHOOK_MAX_KB`), HMAC-SHA256 with constant-time comparison, a rate limiter, idempotency keys, and identical responses for unknown and inactive tokens.
- **Audit log.** Workflow lifecycle, template imports, integration, variable and access changes.
- **Loops and recursion.** Node-execution limit per run, loop max iterations, subworkflow depth limit and recursion
  detection. Records written by a running workflow do not fire DB-event triggers.

---

## Testing

```bash
php artisan test                 # 138 tests (PHPUnit, in-memory SQLite)
composer test                    # config:clear + Pint + PHPStan (level 7) + tests
npm run check                    # oxlint + oxfmt
npm run types:check              # vue-tsc
```

Coverage highlights:

- **Unit:** registry, config validation, expression resolver, conditions/IF, graph ordering & loop scopes, mock
  adapters, churn scoring, status transitions, redaction, SSRF.
- **Feature:** CRUD, RBAC & workspace isolation, activation rules, manual runs via queue, execution history, failure
  policy, retries, loops, switch/merge, stop, delay/resume, cancel/retry, idempotency, execute-from-node,
  subworkflows and recursion guard, schedules (single dispatch per slot), webhooks (signature, required fields,
  idempotency), DB-event and upload triggers, verification-pending trigger, template seeding/import/execution of all
  six templates.
- **Business rules for the unverified-user workflow:** only `verified_at IS NULL`, verified users excluded,
  invalid emails skipped and recorded, demo mode sends nothing, batching (3 keyset queries for 250 users),
  `verified_at` untouched.

---

## Production deployment

1. `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, MySQL credentials and `QUEUE_CONNECTION=redis`
   (recommended), plus `MAIL_*` if real email is intended.
3. `php artisan migrate --force`, then seed **only** roles/integrations/templates if you don't want demo data:
   `php artisan db:seed --class=RolesAndPermissionsSeeder --force`, then `IntegrationSeeder` and `WorkflowTemplateSeeder`.
   `WorkflowTemplateSeeder` references demo records via sample payloads; run `DemoDataSeeder` too, or adjust the
   sample payloads after import.
4. `php artisan config:cache route:cache view:cache`.
5. Run queue workers under Supervisor (`php artisan queue:work --queue=default --tries=1`). Each job's timeout is the workflow's own timeout setting + 60 s.
   Keep the queue connection's `retry_after` (`DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER`, default 3900) **above**
   the longest workflow timeout (max 3600 s); otherwise a second worker could pick up a job that is still running and add the
   scheduler cron entry.
6. Keep `WORKFLOW_DEMO_MODE=true` until real providers are configured and reviewed. Then opt in per workflow.
7. Create your own Super Admin and remove or disable the demo accounts (`is_demo = 1`).

---

## Known limitations

- Execution is sequential. The engine keeps per-scope state (`RunFrame`) so parallel branches can be added later.
- Delays longer than 5 s park the execution and resume via a delayed job. Inside loops, delays are limited to 5 s.
- Execute-from-node is not available for nodes inside a loop body.
- Subworkflows run synchronously inside their parent, so they cannot pause on a long Delay (the parent node fails clearly).
- Exactly-once execution is **not** guaranteed. Duplicate dispatch is prevented by idempotency keys (webhooks,
  schedules, DB events, uploads) and job overlap locks.
- PHP cannot pre-empt a running node, so the workflow timeout is checked between nodes, and adapters use their own HTTP timeouts.
- AI, media, transcription, moderation and translation steps are simulated; see the `TODO:` comments for the integration plans.
- The starter-kit auth and settings pages (`pages/auth/*`, `pages/settings/{Profile,Security,Appearance}.vue`) keep
  their original `<script setup>` TypeScript form. All application pages and components use the Options API.
- Two-factor auth, passkeys and forced email verification are disabled in this build.

See `progress.md` for the implementation log and remaining work.
