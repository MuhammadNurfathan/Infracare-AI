<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AI\AIService;
use App\Services\AI\AIServiceInterface;

use App\Services\Chat\ChatService;
use App\Services\Chat\ChatServiceInterface;

use App\Services\Document\DocumentService;
use App\Services\Document\DocumentServiceInterface;

use App\Services\Knowledge\KnowledgeService;
use App\Services\Knowledge\KnowledgeServiceInterface;

use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;

use App\Repositories\DocumentRepository;
use App\Repositories\DocumentRepositoryInterface;

use App\Services\Search\SearchService;
use App\Services\Search\SearchServiceInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
{
    $this->app->bind(
        AIServiceInterface::class,
        AIService::class
    );

    $this->app->bind(
        ChatServiceInterface::class,
        ChatService::class
    );

    $this->app->bind(
        DocumentServiceInterface::class,
        DocumentService::class
    );

    $this->app->bind(
        KnowledgeServiceInterface::class,
        KnowledgeService::class
    );

    $this->app->bind(
        WhatsAppServiceInterface::class,
        WhatsAppService::class
    );

    $this->app->bind(
    DocumentRepositoryInterface::class,
    DocumentRepository::class
);

$this->app->bind(
    SearchServiceInterface::class,
    SearchService::class
);


}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
