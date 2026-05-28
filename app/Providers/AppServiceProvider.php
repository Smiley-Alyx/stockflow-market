<?php

namespace App\Providers;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Search\Contracts\SearchIndexer;
use App\Domains\Search\Events\SearchIndexRequested;
use App\Domains\Search\Listeners\DispatchSearchIndexJob;
use App\Domains\Search\Listeners\RequestProductIndexing;
use App\Infrastructure\Search\DeferredSearchIndexer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SearchIndexer::class, DeferredSearchIndexer::class);
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
