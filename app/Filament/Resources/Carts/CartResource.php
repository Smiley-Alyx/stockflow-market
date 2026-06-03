<?php

namespace App\Filament\Resources\Carts;

use App\Domains\Orders\Models\Cart;
use App\Filament\Resources\Carts\Pages\CreateCart;
use App\Filament\Resources\Carts\Pages\EditCart;
use App\Filament\Resources\Carts\Pages\ListCarts;
use App\Filament\Resources\Carts\Pages\ViewCart;
use App\Filament\Resources\Carts\Schemas\CartForm;
use App\Filament\Resources\Carts\Schemas\CartInfolist;
use App\Filament\Resources\Carts\Tables\CartsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CartResource extends Resource
{
    protected static ?string $model = Cart::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Carts';

    protected static ?string $modelLabel = 'cart';

    protected static ?string $pluralModelLabel = 'carts';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    public static function form(Schema $schema): Schema
    {
        return CartForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CartInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CartsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarts::route('/'),
            'create' => CreateCart::route('/create'),
            'view' => ViewCart::route('/{record}'),
            'edit' => EditCart::route('/{record}/edit'),
        ];
    }
}
