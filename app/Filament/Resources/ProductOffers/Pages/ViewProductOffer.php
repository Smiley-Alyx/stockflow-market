<?php

namespace App\Filament\Resources\ProductOffers\Pages;

use App\Filament\Resources\ProductOffers\ProductOfferResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductOffer extends ViewRecord
{
    protected static string $resource = ProductOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
