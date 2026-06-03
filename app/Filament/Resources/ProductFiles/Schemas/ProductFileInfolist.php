<?php

namespace App\Filament\Resources\ProductFiles\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductFileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('file.original_name')
                    ->label('File'),
                TextEntry::make('type')
                    ->badge(),
                TextEntry::make('title'),
                TextEntry::make('position')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
