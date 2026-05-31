<?php

namespace App\Filament\Resources\HomepageBlocks\Schemas;

use App\Domains\Homepage\Models\HomepageBlock;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class HomepageBlockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(HomepageBlock::typeOptions())
                    ->required(),
                TextInput::make('title')
                    ->maxLength(255),
                Select::make('image_file_id')
                    ->relationship('imageFile', 'original_name')
                    ->searchable()
                    ->preload(),
                TextInput::make('position')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                Select::make('products')
                    ->relationship('products', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                KeyValue::make('settings')
                    ->keyLabel('Setting')
                    ->valueLabel('Value')
                    ->columnSpanFull(),
            ]);
    }
}
