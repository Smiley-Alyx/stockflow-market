<?php

namespace App\Filament\Resources\CheckoutSagas\Tables;

use App\Domains\Orders\Models\CheckoutSaga;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CheckoutSagasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_id')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('payment_id')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('refund_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        CheckoutSaga::STATUS_RESERVING_STOCK => 'Reserving stock',
                        CheckoutSaga::STATUS_AUTHORIZING_PAYMENT => 'Authorizing payment',
                        CheckoutSaga::STATUS_CAPTURING_PAYMENT => 'Capturing payment',
                        CheckoutSaga::STATUS_CREATING_SHIPMENTS => 'Creating shipments',
                        CheckoutSaga::STATUS_COMPLETED => 'Completed',
                        CheckoutSaga::STATUS_FAILED => 'Failed',
                    ]),
                SelectFilter::make('refund_status')
                    ->options([
                        CheckoutSaga::REFUND_STATUS_PENDING => 'Pending',
                        CheckoutSaga::REFUND_STATUS_COMPLETED => 'Completed',
                        CheckoutSaga::REFUND_STATUS_FAILED => 'Failed',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
