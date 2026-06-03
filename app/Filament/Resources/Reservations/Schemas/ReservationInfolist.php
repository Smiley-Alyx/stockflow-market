<?php

namespace App\Filament\Resources\Reservations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('stockItem.sku')
                    ->label('Stock item'),
                TextEntry::make('idempotency_key'),
                TextEntry::make('quantity')
                    ->numeric(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('reservation_expires_at')
                    ->dateTime(),
                TextEntry::make('canceled_at')
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
