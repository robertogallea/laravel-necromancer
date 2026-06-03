<?php

declare(strict_types=1);

namespace LaravelNecromancer;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;
use LaravelNecromancer\Commands\AskCommand;
use LaravelNecromancer\Commands\AuditCommand;
use LaravelNecromancer\Commands\DiffCommand;
use LaravelNecromancer\Commands\DoctorCommand;
use LaravelNecromancer\Commands\GenerateCommand;
use LaravelNecromancer\Commands\InferCommand;
use LaravelNecromancer\Commands\InspectPayloadCommand;
use LaravelNecromancer\Commands\MapCommand;
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
use LaravelNecromancer\Mcp\NecromancerServer;

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
    }

    public function boot(): void
    {
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
            ]);
        }

        if (class_exists(Server::class)) {
            Mcp::local('necromancer', NecromancerServer::class);
        }
    }
}
