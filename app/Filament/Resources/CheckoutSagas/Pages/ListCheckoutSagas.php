<?php

namespace App\Filament\Resources\CheckoutSagas\Pages;

use App\Filament\Resources\CheckoutSagas\CheckoutSagaResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckoutSagas extends ListRecords
{
    protected static string $resource = CheckoutSagaResource::class;
}
