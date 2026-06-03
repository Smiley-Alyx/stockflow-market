<?php

namespace App\Filament\Resources\Favorites\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class FavoriteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }
}
