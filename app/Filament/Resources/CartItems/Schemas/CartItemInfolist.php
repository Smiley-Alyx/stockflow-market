<?php

namespace App\Filament\Resources\CartItems\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CartItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('cart_id')
                    ->label('Cart'),
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('quantity')
                    ->numeric(),
                IconEntry::make('is_selected')
                    ->boolean(),
                TextEntry::make('removed_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
