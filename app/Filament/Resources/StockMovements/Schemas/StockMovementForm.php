<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Domains\Inventory\Models\StockMovement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('stock_item_id')
                    ->relationship('stockItem', 'sku')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('reservation_id')
                    ->relationship('reservation', 'idempotency_key')
                    ->searchable()
                    ->preload(),
                Select::make('type')
                    ->options([
                        StockMovement::TYPE_RECEIVED => 'Received',
                        StockMovement::TYPE_RESERVED => 'Reserved',
                        StockMovement::TYPE_RELEASED => 'Released',
                        StockMovement::TYPE_EXPIRED => 'Expired',
                        StockMovement::TYPE_DEDUCTED => 'Deducted',
                        StockMovement::TYPE_RETURNED => 'Returned',
                    ])
                    ->required(),
                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('reference_type')
                    ->maxLength(255),
                TextInput::make('reference_id')
                    ->maxLength(255),
                DateTimePicker::make('occurred_at')
                    ->required(),
                KeyValue::make('metadata')
                    ->columnSpanFull(),
            ]);
    }
}
