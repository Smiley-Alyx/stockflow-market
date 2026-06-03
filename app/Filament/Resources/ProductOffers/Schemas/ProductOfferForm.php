<?php

namespace App\Filament\Resources\ProductOffers\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductOfferForm
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
                Select::make('image_file_id')
                    ->relationship('imageFile', 'original_name')
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->maxLength(255),
                TextInput::make('sku')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'archived' => 'Archived',
                    ])
                    ->default('active')
                    ->required(),
                KeyValue::make('attributes')
                    ->columnSpanFull(),
            ]);
    }
}
