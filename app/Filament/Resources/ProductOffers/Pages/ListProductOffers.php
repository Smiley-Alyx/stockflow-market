<?php

namespace App\Filament\Resources\ProductOffers\Pages;

use App\Filament\Resources\ProductOffers\ProductOfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductOffers extends ListRecords
{
    protected static string $resource = ProductOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
