<?php

namespace App\Filament\Resources\OrderItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_id')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('product_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('line_amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('order')
                    ->relationship('order', 'id')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
