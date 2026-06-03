<?php

namespace App\Filament\Resources\OrderItems\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('order_id')
                    ->relationship('order', 'id')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('pricing_product_price_id')
                    ->relationship('productPrice', 'id')
                    ->searchable()
                    ->preload(),
                TextInput::make('sku')
                    ->required()
                    ->maxLength(255),
                TextInput::make('product_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('price_type')
                    ->maxLength(255),
                TextInput::make('price_city_code')
                    ->maxLength(255),
                TextInput::make('price_version')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                DateTimePicker::make('price_active_from'),
                TextInput::make('unit_amount_minor')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('currency')
                    ->maxLength(3)
                    ->required(),
                TextInput::make('line_amount_minor')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }
}
