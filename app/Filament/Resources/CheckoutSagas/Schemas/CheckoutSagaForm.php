<?php

namespace App\Filament\Resources\CheckoutSagas\Schemas;

use App\Domains\Orders\Models\CheckoutSaga;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CheckoutSagaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('order_id')
                    ->relationship('order', 'id')
                    ->searchable()
                    ->preload()
                    ->disabled(),
                TextInput::make('correlation_id')
                    ->disabled(),
                TextInput::make('payment_id')
                    ->disabled(),
                TextInput::make('authorization_id')
                    ->maxLength(255),
                TextInput::make('capture_id')
                    ->maxLength(255),
                TextInput::make('refund_id')
                    ->maxLength(255),
                Select::make('refund_status')
                    ->options([
                        CheckoutSaga::REFUND_STATUS_PENDING => 'Pending',
                        CheckoutSaga::REFUND_STATUS_COMPLETED => 'Completed',
                        CheckoutSaga::REFUND_STATUS_FAILED => 'Failed',
                    ]),
                Select::make('status')
                    ->options([
                        CheckoutSaga::STATUS_RESERVING_STOCK => 'Reserving stock',
                        CheckoutSaga::STATUS_AUTHORIZING_PAYMENT => 'Authorizing payment',
                        CheckoutSaga::STATUS_CAPTURING_PAYMENT => 'Capturing payment',
                        CheckoutSaga::STATUS_CREATING_SHIPMENTS => 'Creating shipments',
                        CheckoutSaga::STATUS_COMPLETED => 'Completed',
                        CheckoutSaga::STATUS_FAILED => 'Failed',
                    ])
                    ->required(),
                Textarea::make('failure_reason')
                    ->columnSpanFull(),
                Textarea::make('compensation_failure_reason')
                    ->columnSpanFull(),
            ]);
    }
}
