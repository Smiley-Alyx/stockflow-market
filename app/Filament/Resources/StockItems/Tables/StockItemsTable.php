<?php

namespace App\Filament\Resources\StockItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('on_hand_quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reserved_quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available')
                    ->state(fn ($record): int => $record->availableQuantity())
                    ->numeric()
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw('(on_hand_quantity - reserved_quantity) '.$direction)),
            ])
            ->filters([
                SelectFilter::make('warehouse')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
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
