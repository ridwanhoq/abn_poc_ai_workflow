# progress.md — FlowForge AI (AI Workflow Automation Platform for OTT & ERP)

_Last updated: Phase 8 complete (testing & hardening). All phases implemented._

## 1. Project overview

A visual, backend-executed workflow automation platform: an n8n/Make-style builder with a work-management-style UI,
covering OTT media automation and ERP business automation. Workflows are graphs of typed nodes saved as JSON
definitions, validated on the server and executed by a Laravel queue-backed engine. All AI, media and email
integrations ship as **clearly labelled simulated adapters**, so every seeded template runs locally without paid credentials.

## 2. Existing application analysis

| Item               | Finding                                                                                                                                                                                                                        |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Base               | `laravel/vue-starter-kit` (Laravel 13.x, PHP 8.4 via Herd), Inertia v3 + Vue 3 + TypeScript, Tailwind v4, reka-ui (shadcn-vue), vue-sonner, `@lucide/vue`, Fortify, Wayfinder, PHPStan level 7, Pint, oxlint/oxfmt (vite-plus) |
| State on arrival   | Unconfigured: `chisel.php` feature selection had never run, no `APP_KEY`, SQLite default, no domain code                                                                                                                       |
| Action taken       | Ran `install:features` keeping **registration** only (2FA, passkeys, forced email verification and password confirmation removed). Generated the app key. Switched `.env` to MySQL (`abn_ai_workflow`).                        |
| Tests on arrival   | 23 passed / 3 skipped (starter-kit auth tests)                                                                                                                                                                                 |
| Dependencies added | `axios`, `@vue-flow/core` + background/controls/minimap, `chart.js`                                                                                                                                                            |

### Conflicts & decisions

1. **`users.verified_at` vs `email_verified_at`.** The starter kit only had Fortify's `email_verified_at`. The spec
   requires `users.verified_at` (profile verification), so a nullable, indexed `verified_at` column was added to the
   real `users` table. `email_verified_at` is untouched.
2. **Options API.** All new application code (layout, pages, components) uses the Vue 3 Options API in plain
   `<script>` blocks. The kept starter-kit auth/settings pages remain `<script setup lang="ts">`. The settings nav was
   minimally extended with _Users & roles_ and _Audit log_ links.
3. **Routing.** Inertia is the page shell (server-side route protection, auth, shared props). Pages load data from the
   versioned REST API (`/api/v1/*`) via Axios, served through the `web` middleware group (session + `XSRF-TOKEN`).
   Public webhooks live in `routes/api.php` (stateless, HMAC-signed, rate limited).
4. **Tests** run on in-memory SQLite. Queries are kept DB-portable. Aggregates use the base query builder to avoid enum casts.

## 3. Architecture

```
app/Workflow/
  Contracts/NodeType.php          node contract
  Nodes/{Triggers,Logic,Data,Notifications,Ai,Utility}/…   38 node types
  NodeRegistry.php                whitelist registry (config/workflows.php)
  Engine/WorkflowEngine.php       topological execution, branching, loops, retries, wait/resume, partial runs
  Engine/{ExecutionContext,Graph,RunFrame,NodeInput,NodeResult,ScopeSignal}.php
  Engine/ExpressionResolver.php   safe {{ path | filter }} resolver (no eval)
  Engine/ConditionEvaluator.php   IF / Filter / Switch rules
  Engine/GraphValidator.php       server-side definition validation
  Data/DataModelRegistry.php      whitelisted models/columns, bound filters, keyset cursor (chunkById-style)
  Integrations/                   contracts, Mock*, Laravel mail/HTTP (SSRF guard), IntegrationManager
  Triggers/TriggerDispatcher.php  schedules (claimed slots), DB events, uploads, verification-pending
  Templates/TemplateLibrary.php   six complete template definitions
  Support/{Redactor,ChurnScorer}.php
app/Services/{WorkflowService,ExecutionService,AuditLogger}.php
app/Jobs/RunWorkflowJob.php       queue job (run + resume)
app/Http/Controllers/Api/V1/*     REST API · app/Policies/* · app/Http/Resources/*
resources/js/layouts/FlowLayout.vue, components/flow/*, components/editor/*, pages/*
```

## 4. Database changes

New tables: `roles`, `permissions`, `permission_role`, `workspaces`, `workspace_user`, `workflows`, `workflow_versions`,
`workflow_executions`, `workflow_execution_steps`, `workflow_execution_logs`, `workflow_templates`,
`workflow_schedules`, `workflow_integrations`, `workflow_variables`, `audit_logs`, `notifications`.
Demo domain tables: `media_assets`, `media_asset_insights`, `localization_tasks`, `contents`, `thumbnail_tasks`,
`trailer_requests`, `subscribers`, `retention_campaigns`, `employees`, `onboarding_tasks`, `leave_requests`,
`reviewer_notifications`. `users` gained `verified_at`, `role_id`, `current_workspace_id` and `is_demo`.
No separate `workflow_nodes` table: `workflows.definition` is the single source of truth.

## 5. Feature checklist

- [x] Phase 1: analysis, starter-kit finalization, MySQL, RBAC (roles/permissions/policies/Super Admin bypass), design system, app shell
- [x] Phase 2: migrations, models (typed), factories, policies, idempotent seeders
- [x] Phase 3: Vue Flow editor (library, connect, configure, validate, save, versions, undo/redo, minimap, execute-from-node)
- [x] Phase 4: execution engine (registry, context, graph, expressions, conditions, loops, retries, failure policies, timeouts, cancellation, wait/resume, subworkflows, idempotency)
- [x] Phase 5: six seeded templates, all executable without credentials
- [x] Phase 6: dashboard (KPIs, chart, filters), workflow list, templates gallery & preview, executions list & detail
- [x] Phase 7: scheduler, webhooks, DB-event, file-upload, user-created and verification-pending triggers
- [x] Phase 8: tests, PHPStan level 7 clean, Pint clean, oxlint/oxfmt clean, vue-tsc clean, headless-browser smoke tests, docs

## 6. Testing checklist (all passing: 97 passed, 3 skipped starter tests for disabled Fortify features)

- [x] Unit: node registry, config validation, expression resolver, IF/conditions, graph ordering, mock adapters, churn scoring, status transitions, redaction, SSRF guard
- [x] Feature: workflow CRUD, RBAC & workspace isolation, activation, manual execution via queue, template import, execution history, failed-node behaviour, scheduled dispatch, webhook validation, retry & cancellation
- [x] Critical business tests 1–10 (`tests/Feature/Workflow/UnverifiedUserReminderTest.php`, `TemplatesTest.php`, `EngineTest.php`, `Api/AuthorizationTest.php`)
- [x] Manual verification in headless Chrome as Super Admin, Admin and Viewer: every page renders with zero console
      errors; a workflow was built by dragging connections, configured, saved (⌘S), test-run and activated; template
      import & test run; light/dark mode; 390 px mobile layout

## 7. Known limitations

- Sequential execution only (the design allows parallel branches later). Delays over 5 s park and resume via the
  queue; inside loops they are capped at 5 s.
- Execute-from-node is unavailable for nodes inside a loop body.
- Exactly-once execution is not guaranteed. Idempotency keys and overlap locks prevent duplicate dispatch for webhooks,
  schedules, DB events and uploads.
- Node execution cannot be pre-empted in PHP, so timeouts are checked between nodes.
- Starter-kit auth/settings pages remain `<script setup>` TypeScript. 2FA, passkeys and forced email verification are disabled.
- Three starter-kit tests are skipped by the kit itself because their Fortify features (e.g. 2FA) are disabled.

## 8. TODO integrations (each marked with a `TODO:` comment at the boundary)

| Boundary                                                                 | File                                                   |
| ------------------------------------------------------------------------ | ------------------------------------------------------ |
| LLM text generation (Anthropic/OpenAI)                                   | `app/Workflow/Integrations/Mock/MockTextGenerator.php` |
| Image generation + object storage                                        | `Mock/MockImageGenerator.php`                          |
| Video processing: ffprobe/MediaConvert, vision models, rendering, WebVTT | `Mock/MockVideoProcessor.php`                          |
| Speech-to-text                                                           | `Mock/MockTranscriber.php`                             |
| Content moderation                                                       | `Mock/MockContentModerator.php`                        |
| Translation / dubbing / lip-sync                                         | `Mock/MockTranslator.php`                              |
| Production email (Laravel Mail provider)                                 | `Nodes/Notifications/SendEmailNode.php`                |
| Outbound webhook auth & delivery log                                     | `Nodes/Notifications/WebhookRequestNode.php`           |
| Cloud storage events (S3 → workflow)                                     | `Http/Controllers/Api/V1/MediaAssetController.php`     |
| Trained churn model                                                      | `Support/ChurnScorer.php`                              |

Still to integrate (not started): CMS publishing, push-notification providers, recommendation services, identity
provisioning (email, Slack, Jira, GitHub), training systems, an HR knowledge-base assistant, and real approval routing.
Each is named in the template result notices and in this file.

## 9. Completed tasks / remaining work

**Phase 16 (follow-up): S3 media storage.** `WORKFLOW_UPLOAD_DISK` (`local` | `s3`) with the official Flysystem S3
driver (`league/flysystem-aws-s3-v3`). Uploads and the media nodes use the configured disk. A failed cloud write now
returns 503 instead of saving a record for a file that does not exist (the S3 disk is configured with `throw => false`). The S3
integration status is computed (connected when the disk is s3 and a bucket is set). The Integrations page shows
".env" hints instead of a misleading Credentials button for the env-configured S3 and Laravel Mail integrations.
Covered by `tests/Feature/Api/S3StorageTest.php` (Storage fake). **Not verified against a live bucket:** no AWS
credentials or S3-compatible server were available. Remaining TODO: start workflows from bucket events (S3 ObjectCreated).

**Phase 15 (follow-up): browser regression pass over previously unexercised UI flows**, with Reverb, the queue worker
and the server running. Checked: test run with the prefilled payload, execute-from-node (only the selected node re-ran),
workflow settings save (version bump), version restore (definition _and_ settings restored, v3), cancelling a paused
run (waiting → cancelled, pushed live), webhook credential rotation (new URL), duplicate and delete from the list,
variable create/edit/delete, and a role change. All behaved correctly, with live `execution.updated` frames throughout
and no console errors. No new defects (two apparent failures were script artifacts, re-checked via the API).

**Phase 14 (follow-up): security hardening review.** Four issues found by targeted review, each reproduced by a
failing test first (`tests/Feature/Api/HardeningTest.php`) and then fixed:

1. _Cross-workspace leak:_ a user without a current workspace created workflows with `workspace_id = NULL`, which
   `visibleTo()` showed to everyone. Now creation resolves requested → current → first membership (422 if none), and
   workspace-less records are Super Admin only (`visibleTo`, `belongsToWorkspace(null)`).
2. _Deleted workflows could run:_ retrying an execution of a soft-deleted workflow started it. `ExecutionService::start`
   refuses trashed workflows, and `can.retry` is false for them.
3. _Secret variable values stored in plain text_ when used in expressions. `Redactor::maskSecrets` now masks them in
   step inputs/outputs, results, execution logs, the Write Laravel Log node and the logging email adapter.
4. _SSRF DNS rebinding:_ the webhook node validated one DNS answer but let cURL resolve again. Connections are now pinned
   to the validated IP (`CURLOPT_RESOLVE`).
5. _Unbounded webhook payloads_ were stored as trigger data: requests over `WORKFLOW_WEBHOOK_MAX_KB` (256) now get 413
   before any lookup or storage.
6. _User directory visible to Viewers:_ the data browser listed all platform users (names) across workspaces to anyone
   with `variables.view`. The `users` model now requires `users.manage`.
7. _Subworkflow that paused:_ a child that parked on a long Delay let the parent continue with an empty result
   (reported as success) and resumed detached later. The child is now cancelled and the parent node fails clearly.
8. _Queue duplicate delivery:_ `retry_after` was 90 s while workflows may run 3600 s; with several workers a long run
   was re-delivered and the duplicate's failure handler marked the still-running execution failed. Now
   `retry_after` defaults to 3900 s, job timeouts follow each workflow's timeout setting, and `failed()` ignores
   duplicate-delivery failures for runs in progress (`tests/Feature/Workflow/QueueRobustnessTest.php`).
9. _Definitions could exceed the editor's permissions:_ a Workflow Manager could save a workflow that reads the
   `users` directory, or that calls a subworkflow they may not run. `WorkflowService::authorizeDefinition` now checks
   every saved definition (403). In-app notifications are limited to workspace members, with one error message for
   unknown and outside recipients (`tests/Feature/Api/DefinitionAuthorizationTest.php`).
   Upgrade note: existing workflows with a NULL workspace become visible to Super Admins only; assign them a workspace.

**Phase 13 (follow-up): real-time execution updates (Laravel Reverb, optional).** `ExecutionUpdated`
(`ShouldBroadcastNow`, ids and status only) on private `executions.{id}` and `workspaces.{id}.executions` channels,
authorized with the API's rules. `ExecutionBroadcaster` throttles progress to 750 ms, always sends status changes, and opens
a 30 s circuit breaker on failure; the Reverb client uses 1–2 s timeouts. The frontend `lib/realtime.js` is enabled only
when `VITE_REVERB_APP_KEY` is set; the editor, execution detail, executions list and top-bar indicator subscribe and keep
slower polling as a fallback. Verified end to end with Reverb running: real `execution.updated` frames arrived and the
editor showed success about 3.6 s after starting a 3 s run, while polling was every 5 s. Covered by `tests/Feature/Api/RealtimeTest.php`.
Also fixed a pre-existing bug found during this check: new workflows' empty sample payload (`[]`) made the test-run
dialog reject the run ("Payload must be a JSON object"); the dialog now treats it as `{}`.
Local note: Reverb runs on port 8090 in this machine's `.env` because another service occupies 127.0.0.1:8080.

**Phase 12 (follow-up): AI usage & cost reporting.** New `ai_usage` table and `AiUsage` model, recorded by
`AiUsageRecorder` from the AI Text node for every call (mock or Claude). Costs are estimated from the
`workflows.ai_pricing` price table. Usage rows survive execution pruning (`execution_id` → NULL). Exposed in the dashboard API
(`ai_usage` totals and per-model breakdown, workspace- and range-scoped) and the execution detail API, with UI on the
Overview and the execution _Trigger & result_ tab. Covered by `tests/Feature/Api/AiUsageTest.php` and verified in the browser.

**Phase 11 (follow-up): real AI text generation (Claude).** `ClaudeTextGenerator` on the official Anthropic PHP SDK
(`anthropic-ai/sdk` 0.59): `claude-opus-5-5` by default, low effort, strict JSON-schema structured outputs per task
(same field contract as the mock), `fallbacks: "default"`, 60 s timeout with 2 retries, and typed SDK errors mapped to node errors.
It is selected by `IntegrationManager::textGenerator()` only when the new **Anthropic Claude** integration has an encrypted
API key **and** is explicitly enabled; otherwise the mock is used. The AI Text node's implementation label follows this switch.
Admin UI: Integrations → Configure (key, model, effort, enable). _Check_ makes one real request.
Tests: `ClaudeTextGeneratorTest` and `ClaudeIntegrationTest` intercept HTTP with SDK request middleware (no network).
**Not verified against the live API:** no API key was available in this environment.

**Phase 10 (follow-up): workspace management.** New permission `workspaces.manage` (Admin; Super Admin implicitly),
`WorkspaceController` create/rename/delete/members endpoints with audit logging, _Settings → Workspaces_ page and a
"Manage workspaces" entry in the switcher (which now refreshes on open). Deletion is refused while workflows remain,
so they can never become workspace-less (visible to all). Covered by `tests/Feature/Api/WorkspaceTest.php` and
verified in the browser.

**Phase 9 (follow-up): demo event simulator.** `DemoEventController` (`/api/v1/demo-events/*`, demo mode +
`workflows.execute` only, `is_demo` records only) and `DemoEventsPanel` on Data & Variables. They publish content, create
employees and submit leave requests so that active Database Event workflows (templates 2, 5 and 6) can be run end to end
from the UI. Covered by `tests/Feature/Api/DemoEventTest.php` and verified in the browser (activate onboarding →
create employee → execution succeeds through the queue worker).

**Completed:** every phase above. Commands verified: `php artisan migrate:fresh --seed`, `npm run build`,
`php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`, `npm run check`, `npm run types:check`,
`php artisan workflows:run {slug}` for all six templates, queue worker + scheduler.

**Possible next steps:** parallel branch execution; per-node
timeouts via isolated workers; richer expression autocomplete.
