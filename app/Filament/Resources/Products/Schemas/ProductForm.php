<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Domains\Catalog\Models\ProductFile;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('sku')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ])
                    ->default('draft')
                    ->required(),
                DateTimePicker::make('published_at'),
                Select::make('image_file_id')
                    ->relationship('imageFile', 'original_name')
                    ->searchable()
                    ->preload(),
                TextInput::make('rating')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(5)
                    ->default(0)
                    ->required(),
                TextInput::make('rating_count')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Textarea::make('short_description')
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Repeater::make('files')
                    ->relationship()
                    ->schema([
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
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
