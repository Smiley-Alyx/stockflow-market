<?php

namespace App\Filament\Resources\StockItems\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StockItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sku'),
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('warehouse.name')
                    ->label('Warehouse'),
                TextEntry::make('on_hand_quantity')
                    ->numeric(),
                TextEntry::make('reserved_quantity')
                    ->numeric(),
                TextEntry::make('available')
                    ->state(fn ($record): int => $record->availableQuantity())
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
