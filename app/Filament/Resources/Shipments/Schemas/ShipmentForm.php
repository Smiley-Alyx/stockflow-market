<?php

namespace App\Filament\Resources\Shipments\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ShipmentForm
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
                TextInput::make('delivery_service')
                    ->required()
                    ->maxLength(255),
                TextInput::make('provider_shipment_id')
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('provider_status')
                    ->maxLength(255),
                TextInput::make('tracking_number')
                    ->maxLength(255),
            ]);
    }
}
