<?php

namespace App\Filament\Resources\Carts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class CartForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
