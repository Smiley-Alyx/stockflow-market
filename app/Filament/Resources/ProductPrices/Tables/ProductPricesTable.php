<?php

namespace App\Filament\Resources\ProductPrices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('city_code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount_minor')
                    ->money(fn ($record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('price_version')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('active_from')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('active_until')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('price_type')
                    ->options([
                        'base' => 'Base',
                        'sale' => 'Sale',
                    ]),
                TernaryFilter::make('is_active'),
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
