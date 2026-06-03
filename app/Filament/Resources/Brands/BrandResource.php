<?php

namespace App\Filament\Resources\Brands;

use App\Domains\Catalog\Models\Brand;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Brands\Pages\ViewBrand;
use App\Filament\Resources\Brands\Schemas\BrandForm;
use App\Filament\Resources\Brands\Schemas\BrandInfolist;
use App\Filament\Resources\Brands\Tables\BrandsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Brands';

    protected static ?string $modelLabel = 'brand';

    protected static ?string $pluralModelLabel = 'brands';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return BrandForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BrandInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'view' => ViewBrand::route('/{record}'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
