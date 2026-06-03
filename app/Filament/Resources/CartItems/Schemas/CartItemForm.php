<?php

namespace App\Filament\Resources\CartItems\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CartItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cart_id')
                    ->relationship('cart', 'id')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Toggle::make('is_selected')
                    ->default(true)
                    ->required(),
                DateTimePicker::make('removed_at'),
            ]);
    }
}
