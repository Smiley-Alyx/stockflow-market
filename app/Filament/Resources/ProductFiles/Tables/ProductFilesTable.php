<?php

namespace App\Filament\Resources\ProductFiles\Tables;

use App\Domains\Catalog\Models\ProductFile;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('file.original_name')
                    ->label('File')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('position')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->options(ProductFile::typeOptions()),
            ])
            ->defaultSort('position')
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
