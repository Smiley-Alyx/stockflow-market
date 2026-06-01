<?php

use App\Console\Commands\ArchiveStockMovementsCommand;
use App\Console\Commands\ExpireInventoryReservationsCommand;
use App\Console\Commands\PublishOutboxCommand;
use App\Console\Commands\RebuildCatalogProjectionsCommand;
use App\Console\Commands\RebuildSearchIndexCommand;
use App\Console\Commands\SearchDeadLetterCommand;
use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ArchiveStockMovementsCommand::class,
        ExpireInventoryReservationsCommand::class,
        PublishOutboxCommand::class,
        RebuildCatalogProjectionsCommand::class,
        RebuildSearchIndexCommand::class,
        SearchDeadLetterCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('inventory:reservations:expire')
            ->everyMinute()
            ->withoutOverlapping();
        $schedule->command('inventory:stock-movements:archive')
            ->dailyAt('02:15')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RecordHttpMetrics::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
