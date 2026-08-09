<?php

declare(strict_types=1);

namespace LaravelNecromancer;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;
use LaravelNecromancer\Commands\AskCommand;
use LaravelNecromancer\Commands\AuditCommand;
use LaravelNecromancer\Commands\BenchmarkCommand;
use LaravelNecromancer\Commands\DiffCommand;
use LaravelNecromancer\Commands\DoctorCommand;
use LaravelNecromancer\Commands\GenerateCommand;
use LaravelNecromancer\Commands\GraphCommand;
use LaravelNecromancer\Commands\InferCommand;
use LaravelNecromancer\Commands\InspectPayloadCommand;
use LaravelNecromancer\Commands\MapCommand;
use LaravelNecromancer\Commands\OkfCommand;
use LaravelNecromancer\Commands\OkfEnrichCommand;
use LaravelNecromancer\Commands\PromptCommand;
use LaravelNecromancer\Commands\ScanCommand;
use LaravelNecromancer\Inference\AdrCriticAgent;
use LaravelNecromancer\Inference\AdrInferenceAgent;
use LaravelNecromancer\Inference\AdrTranslationAgent;
use LaravelNecromancer\Inference\Contracts\AdrCritic;
use LaravelNecromancer\Inference\Contracts\AdrInferrer;
use LaravelNecromancer\Inference\Contracts\AdrTranslator;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Integrations\BoostDetector;
use LaravelNecromancer\Integrations\McpInstaller;
use LaravelNecromancer\Mcp\NecromancerServer;
use LaravelNecromancer\Metadata\RouteMetadataFactory;
use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;
use LaravelNecromancer\Okf\Enrichment\EnrichmentAgent;
use LaravelNecromancer\Routing\RouteMetadataMacros;

final class NecromancerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/necromancer.php', 'necromancer');

        $this->app->singleton(BoostDetector::class, fn () => new BoostDetector);
        $this->app->singleton(AiDetector::class, fn () => new AiDetector);
        $this->app->bind(AdrInferrer::class, AdrInferenceAgent::class);
        $this->app->bind(AdrTranslator::class, AdrTranslationAgent::class);
        $this->app->bind(AdrCritic::class, AdrCriticAgent::class);
        $this->app->bind(ConceptEnricher::class, EnrichmentAgent::class);
        $this->app->singleton(RouteMetadataFactory::class);
    }

    public function boot(): void
    {
        (new RouteMetadataMacros)->register();

        $this->publishes([
            __DIR__.'/../config/necromancer.php' => config_path('necromancer.php'),
        ], 'necromancer-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                MapCommand::class,
                AuditCommand::class,
                DoctorCommand::class,
                GenerateCommand::class,
                InferCommand::class,
                AskCommand::class,
                DiffCommand::class,
                PromptCommand::class,
                InspectPayloadCommand::class,
                BenchmarkCommand::class,
                OkfCommand::class,
                OkfEnrichCommand::class,
                GraphCommand::class,
            ]);
        }

        if (class_exists(Server::class)) {
            Mcp::local('necromancer', NecromancerServer::class);

            (new McpInstaller(base_path('.mcp.json')))->ensureRegistered();
        }
    }
}
