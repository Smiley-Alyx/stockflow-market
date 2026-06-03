<?php

namespace App\Filament\Resources\ProductOffers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductOfferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('name'),
                TextEntry::make('sku'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('imageFile.original_name')
                    ->label('Image file'),
                TextEntry::make('attributes')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
