<?php

namespace App\Filament\Resources\Promotions\Schemas;

use App\Domains\Pricing\Models\Promotion;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PromotionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('discount_type')
                    ->options([
                        Promotion::TYPE_PERCENT => 'Percent',
                        Promotion::TYPE_FIXED_AMOUNT => 'Fixed amount',
                    ])
                    ->required(),
                TextInput::make('discount_value')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                TextInput::make('currency')
                    ->maxLength(3),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                DateTimePicker::make('starts_at'),
                DateTimePicker::make('ends_at'),
            ]);
    }
}
