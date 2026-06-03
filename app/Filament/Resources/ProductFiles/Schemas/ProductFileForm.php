<?php

namespace App\Filament\Resources\ProductFiles\Schemas;

use App\Domains\Catalog\Models\ProductFile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductFileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('file_id')
                    ->relationship('file', 'original_name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('type')
                    ->options(ProductFile::typeOptions())
                    ->required(),
                TextInput::make('title')
                    ->maxLength(255),
                TextInput::make('position')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
            ]);
    }
}
