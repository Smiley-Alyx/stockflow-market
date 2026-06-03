<?php

namespace App\Filament\Pages;

use App\Infrastructure\Health\DependencyHealthChecker;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class Monitoring extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Monitoring';

    protected static ?string $title = 'Monitoring';

    protected static string|\UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.monitoring';

    /**
     * @return array<string, array{ok: bool, critical: bool, status: string, detail?: string}>
     */
    public function readiness(): array
    {
        return app(DependencyHealthChecker::class)->readiness();
    }

    /**
     * @return array<string, string>
     */
    public function overview(): array
    {
        return [
            'Products' => $this->count('catalog_products'),
            'Orders' => $this->count('orders_orders'),
            'Pending jobs' => $this->count('jobs'),
            'Failed jobs' => $this->count('failed_jobs'),
            'Outbox pending' => $this->count('messaging_outbox', ['status' => 'pending']),
            'Provider outbox pending' => $this->count('messaging_provider_outbox', ['status' => 'pending']),
            'Checkout sagas failed' => $this->count('orders_checkout_sagas', ['status' => 'failed']),
            'Active reservations' => $this->count('inventory_reservations', ['status' => 'active']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function links(): array
    {
        return [
            'Live probe' => url('/health/live'),
            'Ready probe' => url('/health/ready'),
            'Metrics endpoint' => url('/metrics'),
            'Prometheus' => (string) config('stockflow.observability.prometheus_url'),
            'Grafana' => (string) config('stockflow.observability.grafana_url'),
            'RabbitMQ UI' => (string) config('stockflow.observability.rabbitmq_management_url'),
        ];
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function count(string $table, array $where = []): string
    {
        if (! Schema::hasTable($table)) {
            return 'n/a';
        }

        try {
            $query = DB::table($table);

            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return number_format($query->count(), 0, '.', ' ');
        } catch (Throwable) {
            return 'n/a';
        }
    }
}
