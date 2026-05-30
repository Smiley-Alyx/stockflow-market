<?php

namespace App\Console\Commands;

use App\Domains\Inventory\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireInventoryReservationsCommand extends Command
{
    protected $signature = 'inventory:reservations:expire';

    protected $description = 'Expire active inventory reservations whose hold time has elapsed.';

    public function handle(InventoryService $inventory): int
    {
        $expired = $inventory->expireReservations();

        Log::info('inventory.reservations.expired', [
            'expired_count' => $expired,
        ]);

        $this->info("Expired {$expired} inventory reservation(s).");

        return self::SUCCESS;
    }
}
