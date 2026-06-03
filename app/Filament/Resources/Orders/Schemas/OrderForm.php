<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Domains\Orders\Models\Order;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cart_id')
                    ->relationship('cart', 'id')
                    ->searchable()
                    ->preload(),
                Select::make('status')
                    ->options([
                        Order::STATUS_DRAFT => 'Draft',
                        Order::STATUS_RESERVATION_PENDING => 'Reservation pending',
                        Order::STATUS_CONFIRMED => 'Confirmed',
                        Order::STATUS_RESERVATION_FAILED => 'Reservation failed',
                        Order::STATUS_PAID => 'Paid',
                        Order::STATUS_CANCELLED => 'Cancelled',
                        Order::STATUS_EXPIRED => 'Expired',
                    ])
                    ->required(),
                TextInput::make('city_code')
                    ->maxLength(255),
                TextInput::make('promo_code')
                    ->maxLength(255),
                TextInput::make('subtotal_amount_minor')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                TextInput::make('discount_amount_minor')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                TextInput::make('total_amount_minor')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                TextInput::make('currency')
                    ->maxLength(3),
                TextInput::make('payment_method')
                    ->maxLength(255),
                TextInput::make('recipient_name')
                    ->maxLength(255),
                TextInput::make('recipient_phone')
                    ->maxLength(255),
                TextInput::make('delivery_country_code')
                    ->maxLength(2),
                TextInput::make('delivery_city')
                    ->maxLength(255),
                TextInput::make('delivery_postal_code')
                    ->maxLength(255),
                TextInput::make('delivery_address_line_1')
                    ->maxLength(255),
                TextInput::make('delivery_address_line_2')
                    ->maxLength(255),
                DateTimePicker::make('checkout_at'),
                DateTimePicker::make('confirmed_at'),
                DateTimePicker::make('paid_at'),
                DateTimePicker::make('cancelled_at'),
                DateTimePicker::make('expired_at'),
            ]);
    }
}
