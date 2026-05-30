<?php

use App\Console\Commands\ExpireInventoryReservationsCommand;
use App\Console\Commands\PublishOutboxCommand;
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
        ExpireInventoryReservationsCommand::class,
        PublishOutboxCommand::class,
        SearchDeadLetterCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('inventory:reservations:expire')
            ->everyMinute()
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
