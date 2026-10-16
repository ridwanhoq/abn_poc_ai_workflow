<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Engine\ConditionEvaluator;
use App\Workflow\Engine\ExpressionResolver;
use App\Workflow\Integrations\Contracts;
use App\Workflow\Integrations\Mock;
use App\Workflow\NodeRegistry;
use App\Workflow\Support\ExecutionBroadcaster;
use App\Workflow\Triggers\TriggerDispatcher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    /**
     * Adapter bindings. Swap a mock for a production implementation here once it exists.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ExpressionResolver::class => ExpressionResolver::class,
        ConditionEvaluator::class => ConditionEvaluator::class,
        DataModelRegistry::class => DataModelRegistry::class,
        ExecutionBroadcaster::class => ExecutionBroadcaster::class,
        Contracts\TextGenerator::class => Mock\MockTextGenerator::class,
        Contracts\ImageGenerator::class => Mock\MockImageGenerator::class,
        Contracts\VideoProcessor::class => Mock\MockVideoProcessor::class,
        Contracts\Transcriber::class => Mock\MockTranscriber::class,
        Contracts\ContentModerator::class => Mock\MockContentModerator::class,
        Contracts\Translator::class => Mock\MockTranslator::class,
    ];

    public function register(): void
    {
        $this->app->singleton(NodeRegistry::class, fn ($app) => new NodeRegistry($app, config('workflows.nodes', [])));
    }

    public function boot(): void
    {
        // Super Admin has unrestricted access to every ability / policy.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        foreach (array_keys(Permission::CATALOGUE) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(60)->by($request->route('token').'|'.$request->ip()));
        RateLimiter::for('workflow-runs', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));

        $this->registerModelEventTriggers();
    }

    private function registerModelEventTriggers(): void
    {
        foreach (config('workflows.event_models', []) as $alias => $class) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $class::$event(fn ($model) => app(TriggerDispatcher::class)->modelEvent($alias, $event, $model));
            }
        }
    }
}
