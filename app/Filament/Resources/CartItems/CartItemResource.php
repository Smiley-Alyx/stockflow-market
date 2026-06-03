<?php

namespace App\Filament\Resources\CartItems;

use App\Domains\Orders\Models\CartItem;
use App\Filament\Resources\CartItems\Pages\CreateCartItem;
use App\Filament\Resources\CartItems\Pages\EditCartItem;
use App\Filament\Resources\CartItems\Pages\ListCartItems;
use App\Filament\Resources\CartItems\Pages\ViewCartItem;
use App\Filament\Resources\CartItems\Schemas\CartItemForm;
use App\Filament\Resources\CartItems\Schemas\CartItemInfolist;
use App\Filament\Resources\CartItems\Tables\CartItemsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CartItemResource extends Resource
{
    protected static ?string $model = CartItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Cart items';

    protected static ?string $modelLabel = 'cart item';

    protected static ?string $pluralModelLabel = 'cart items';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return CartItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CartItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CartItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCartItems::route('/'),
            'create' => CreateCartItem::route('/create'),
            'view' => ViewCartItem::route('/{record}'),
            'edit' => EditCartItem::route('/{record}/edit'),
        ];
    }
}
