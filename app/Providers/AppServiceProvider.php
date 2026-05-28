<?php

namespace App\Providers;

use App\Domains\Catalog\Events\ProductCreated;
use App\Domains\Search\Listeners\RequestProductIndexing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ProductCreated::class, RequestProductIndexing::class);
    }
}
