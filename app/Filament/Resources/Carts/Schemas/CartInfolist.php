<?php

namespace App\Filament\Resources\Carts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CartInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('Cart ID'),
                TextEntry::make('user.email')
                    ->label('User'),
                TextEntry::make('items_count')
                    ->state(fn ($record): int => $record->items()->count())
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
