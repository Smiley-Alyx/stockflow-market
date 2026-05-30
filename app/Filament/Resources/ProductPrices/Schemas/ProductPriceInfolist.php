<?php

namespace App\Filament\Resources\ProductPrices\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductPriceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('price_type')
                    ->badge(),
                TextEntry::make('city_code'),
                TextEntry::make('amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100),
                TextEntry::make('price_version')
                    ->numeric(),
                TextEntry::make('currency'),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('active_from')
                    ->dateTime(),
                TextEntry::make('active_until')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
