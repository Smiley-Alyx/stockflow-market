<?php

namespace App\Filament\Resources\ShipmentItems\Pages;

use App\Filament\Resources\ShipmentItems\ShipmentItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShipmentItems extends ListRecords
{
    protected static string $resource = ShipmentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
