<?php

namespace App\Providers;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Listeners\DispatchSearchIndexJob;
use App\Domains\Search\Listeners\RequestProductIndexing;
use App\Infrastructure\Search\DeadLetters\ArraySearchIndexDeadLetterStore;
use App\Infrastructure\Search\DeadLetters\RedisSearchIndexDeadLetterStore;
use App\Infrastructure\Search\ElasticsearchSearchIndexer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SearchIndexer::class, ElasticsearchSearchIndexer::class);
        $this->app->bind(SearchIndexDeadLetterStore::class, match (config('stockflow.search.indexing.dead_letter_backend')) {
            'array' => ArraySearchIndexDeadLetterStore::class,
            default => RedisSearchIndexDeadLetterStore::class,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ProductCreated::class, RequestProductIndexing::class);
        Event::listen(SearchIndexRequested::class, DispatchSearchIndexJob::class);
    }
}
