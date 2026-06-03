<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('stockItem.sku')
                    ->label('Stock item'),
                TextEntry::make('reservation.idempotency_key')
                    ->label('Reservation'),
                TextEntry::make('type')
                    ->badge(),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('reference_type'),
                TextEntry::make('reference_id'),
                TextEntry::make('occurred_at')
                    ->dateTime(),
                TextEntry::make('metadata')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
