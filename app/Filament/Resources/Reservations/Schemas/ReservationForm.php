<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Domains\Inventory\Models\Reservation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ReservationForm
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
                TextInput::make('idempotency_key')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Select::make('status')
                    ->options([
                        Reservation::STATUS_ACTIVE => 'Active',
                        Reservation::STATUS_CANCELED => 'Canceled',
                        Reservation::STATUS_EXPIRED => 'Expired',
                        Reservation::STATUS_CONSUMED => 'Consumed',
                    ])
                    ->required(),
                DateTimePicker::make('reservation_expires_at')
                    ->required(),
                DateTimePicker::make('canceled_at'),
                KeyValue::make('metadata')
                    ->columnSpanFull(),
            ]);
    }
}
