<?php

namespace App\Filament\Resources\CartItems\Pages;

use App\Filament\Resources\CartItems\CartItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCartItem extends ViewRecord
{
    protected static string $resource = CartItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
