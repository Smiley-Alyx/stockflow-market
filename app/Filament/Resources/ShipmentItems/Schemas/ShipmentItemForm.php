<?php

namespace App\Filament\Resources\ShipmentItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ShipmentItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shipment_id')
                    ->relationship('shipment', 'id')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('order_item_id')
                    ->relationship('orderItem', 'product_name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ]);
    }
}
