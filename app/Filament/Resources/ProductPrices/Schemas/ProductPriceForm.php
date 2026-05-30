<?php

namespace App\Filament\Resources\ProductPrices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('price_type')
                    ->options([
                        'base' => 'Base',
                        'sale' => 'Sale',
                    ])
                    ->default('base')
                    ->required(),
                TextInput::make('city_code')
                    ->required()
                    ->maxLength(255),
                TextInput::make('price_version')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                TextInput::make('amount_minor')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('currency')
                    ->default('RUB')
                    ->maxLength(3)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                DateTimePicker::make('active_from')
                    ->required(),
                DateTimePicker::make('active_until'),
            ]);
    }
}
