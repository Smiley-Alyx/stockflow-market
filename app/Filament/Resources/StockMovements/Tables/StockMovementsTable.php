<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Domains\Inventory\Models\StockMovement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
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
                TextColumn::make('reference_type')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('reference_id')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
