<?php

namespace App\Filament\Resources\StaticPages\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StaticPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Textarea::make('excerpt')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('content')
                    ->rows(12)
                    ->columnSpanFull(),
                TextInput::make('meta_title')
                    ->maxLength(255),
                Textarea::make('meta_description')
                    ->columnSpanFull(),
                Toggle::make('is_published')
                    ->default(false)
                    ->required(),
                DateTimePicker::make('published_at'),
            ]);
    }
}
