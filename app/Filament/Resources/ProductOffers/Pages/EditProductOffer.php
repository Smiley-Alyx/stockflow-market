<?php

namespace App\Filament\Resources\ProductOffers\Pages;

use App\Filament\Resources\ProductOffers\ProductOfferResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductOffer extends EditRecord
{
    protected static string $resource = ProductOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
