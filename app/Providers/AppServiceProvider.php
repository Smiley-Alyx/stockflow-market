<?php

namespace App\Providers;

use App\Domains\Catalog\Events\ProductArchived;
use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Catalog\Events\ProductUpdated;
use App\Domains\Catalog\Listeners\InvalidateCatalogProductsOnStockChanged;
use App\Domains\Inventory\Events\StockChanged;
use App\Domains\Orders\Events\OrderConfirmationRequested;
use App\Domains\Orders\Listeners\ReserveInventoryForOrder;
use App\Domains\Search\Contracts\ProductSearch;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\DeadLetters\SearchIndexDeadLetterStore;
use App\Domains\Search\Events\SearchIndexDeletionRequested;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Listeners\DispatchSearchDeleteJob;
use App\Domains\Search\Listeners\DispatchSearchIndexJob;
use App\Domains\Search\Listeners\RequestProductIndexDeletion;
use App\Domains\Search\Listeners\RequestProductIndexing;
use App\Infrastructure\Search\DeadLetters\ArraySearchIndexDeadLetterStore;
use App\Infrastructure\Search\DeadLetters\RedisSearchIndexDeadLetterStore;
use App\Infrastructure\Search\ElasticsearchProductSearch;
use App\Infrastructure\Search\ElasticsearchSearchIndexer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductSearch::class, ElasticsearchProductSearch::class);
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
        $this->configureRateLimiters();

        Event::listen(ProductCreated::class, RequestProductIndexing::class);
        Event::listen(ProductUpdated::class, RequestProductIndexing::class);
        Event::listen(ProductArchived::class, RequestProductIndexDeletion::class);
        Event::listen(StockChanged::class, InvalidateCatalogProductsOnStockChanged::class);
        Event::listen(OrderConfirmationRequested::class, ReserveInventoryForOrder::class);
        Event::listen(SearchIndexRequested::class, DispatchSearchIndexJob::class);
        Event::listen(SearchIndexDeletionRequested::class, DispatchSearchDeleteJob::class);
    }

    private function configureRateLimiters(): void
    {
        foreach (['checkout', 'search', 'catalog'] as $bucket) {
            RateLimiter::for('stockflow-'.$bucket, function (Request $request) use ($bucket): Limit {
                return Limit::perSecond(
                    max(1, (int) config('stockflow.rate_limits.'.$bucket.'.max_attempts')),
                    max(1, (int) config('stockflow.rate_limits.'.$bucket.'.decay_seconds')),
                )
                    ->by($bucket.':'.$request->ip())
                    ->response(fn (Request $request, array $headers) => response()->json([
                        'message' => 'Too many requests. Please retry later.',
                    ], 429, $headers));
            });
        }
    }
}
