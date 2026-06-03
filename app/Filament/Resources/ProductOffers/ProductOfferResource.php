<?php

namespace App\Filament\Resources\ProductOffers;

use App\Domains\Catalog\Models\ProductOffer;
use App\Filament\Resources\ProductOffers\Pages\CreateProductOffer;
use App\Filament\Resources\ProductOffers\Pages\EditProductOffer;
use App\Filament\Resources\ProductOffers\Pages\ListProductOffers;
use App\Filament\Resources\ProductOffers\Pages\ViewProductOffer;
use App\Filament\Resources\ProductOffers\Schemas\ProductOfferForm;
use App\Filament\Resources\ProductOffers\Schemas\ProductOfferInfolist;
use App\Filament\Resources\ProductOffers\Tables\ProductOffersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductOfferResource extends Resource
{
    protected static ?string $model = ProductOffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Product offers';

    protected static ?string $modelLabel = 'product offer';

    protected static ?string $pluralModelLabel = 'product offers';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return ProductOfferForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductOfferInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductOffersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductOffers::route('/'),
            'create' => CreateProductOffer::route('/create'),
            'view' => ViewProductOffer::route('/{record}'),
            'edit' => EditProductOffer::route('/{record}/edit'),
        ];
    }
}
