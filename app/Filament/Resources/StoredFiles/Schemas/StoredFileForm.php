<?php

namespace App\Filament\Resources\StoredFiles\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StoredFileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('original_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('disk')
                    ->requiredWith('path')
                    ->maxLength(255),
                TextInput::make('path')
                    ->requiredWith('disk')
                    ->maxLength(255),
                TextInput::make('source_url')
                    ->url()
                    ->maxLength(255),
                TextInput::make('mime_type')
                    ->maxLength(255),
                TextInput::make('size')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('checksum')
                    ->maxLength(64),
                KeyValue::make('metadata')
                    ->columnSpanFull(),
            ]);
    }
}
