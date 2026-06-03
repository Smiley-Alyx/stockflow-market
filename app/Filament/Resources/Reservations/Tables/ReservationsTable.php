<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Domains\Inventory\Models\Reservation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stockItem.sku')
                    ->label('Stock item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('idempotency_key')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reservation_expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('stockItem')
                    ->relationship('stockItem', 'sku')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        Reservation::STATUS_ACTIVE => 'Active',
                        Reservation::STATUS_CANCELED => 'Canceled',
                        Reservation::STATUS_EXPIRED => 'Expired',
                        Reservation::STATUS_CONSUMED => 'Consumed',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
