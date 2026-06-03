<?php

namespace App\Filament\Resources\Favorites\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FavoriteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.email')
                    ->label('User'),
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
