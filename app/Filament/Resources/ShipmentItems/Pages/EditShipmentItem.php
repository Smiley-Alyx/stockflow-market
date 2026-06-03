<?php

namespace App\Filament\Resources\ShipmentItems\Pages;

use App\Filament\Resources\ShipmentItems\ShipmentItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShipmentItem extends EditRecord
{
    protected static string $resource = ShipmentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
