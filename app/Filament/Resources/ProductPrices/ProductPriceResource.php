<?php

namespace App\Filament\Resources\ProductPrices;

use App\Domains\Pricing\Models\ProductPrice;
use App\Filament\Resources\ProductPrices\Pages\CreateProductPrice;
use App\Filament\Resources\ProductPrices\Pages\EditProductPrice;
use App\Filament\Resources\ProductPrices\Pages\ListProductPrices;
use App\Filament\Resources\ProductPrices\Pages\ViewProductPrice;
use App\Filament\Resources\ProductPrices\Schemas\ProductPriceForm;
use App\Filament\Resources\ProductPrices\Schemas\ProductPriceInfolist;
use App\Filament\Resources\ProductPrices\Tables\ProductPricesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductPriceResource extends Resource
{
    protected static ?string $model = ProductPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Prices';

    protected static ?string $modelLabel = 'price';

    protected static ?string $pluralModelLabel = 'prices';

    protected static string|\UnitEnum|null $navigationGroup = 'Pricing';

    public static function form(Schema $schema): Schema
    {
        return ProductPriceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductPriceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductPricesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductPrices::route('/'),
            'create' => CreateProductPrice::route('/create'),
            'view' => ViewProductPrice::route('/{record}'),
            'edit' => EditProductPrice::route('/{record}/edit'),
        ];
    }
}
