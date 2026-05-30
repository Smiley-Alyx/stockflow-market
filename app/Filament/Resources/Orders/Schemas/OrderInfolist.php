<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('Order ID'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('city_code'),
                TextEntry::make('promo_code'),
                TextEntry::make('subtotal_amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100),
                TextEntry::make('discount_amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100),
                TextEntry::make('total_amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100),
                TextEntry::make('currency'),
                TextEntry::make('confirmed_at')
                    ->dateTime(),
                TextEntry::make('paid_at')
                    ->dateTime(),
                TextEntry::make('cancelled_at')
                    ->dateTime(),
                TextEntry::make('expired_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
