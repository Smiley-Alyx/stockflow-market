<?php

namespace App\Filament\Resources\StockMovementArchives\Tables;

use App\Domains\Inventory\Models\StockMovement;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementArchivesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('stockItem.sku')
                    ->label('Stock item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('archived_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('archived_at', 'desc')
            ->filters([
                SelectFilter::make('stockItem')
                    ->relationship('stockItem', 'sku')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->options([
                        StockMovement::TYPE_RECEIVED => 'Received',
                        StockMovement::TYPE_RESERVED => 'Reserved',
                        StockMovement::TYPE_RELEASED => 'Released',
                        StockMovement::TYPE_EXPIRED => 'Expired',
                        StockMovement::TYPE_DEDUCTED => 'Deducted',
                        StockMovement::TYPE_RETURNED => 'Returned',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
