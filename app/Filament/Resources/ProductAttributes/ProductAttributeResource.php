<?php

namespace App\Filament\Resources\ProductAttributes;

use App\Domains\Catalog\Models\ProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\CreateProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\EditProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\ListProductAttributes;
use App\Filament\Resources\ProductAttributes\Pages\ViewProductAttribute;
use App\Filament\Resources\ProductAttributes\Schemas\ProductAttributeForm;
use App\Filament\Resources\ProductAttributes\Schemas\ProductAttributeInfolist;
use App\Filament\Resources\ProductAttributes\Tables\ProductAttributesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductAttributeResource extends Resource
{
    protected static ?string $model = ProductAttribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Product attributes';

    protected static ?string $modelLabel = 'product attribute';

    protected static ?string $pluralModelLabel = 'product attributes';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return ProductAttributeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductAttributeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductAttributesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductAttributes::route('/'),
            'create' => CreateProductAttribute::route('/create'),
            'view' => ViewProductAttribute::route('/{record}'),
            'edit' => EditProductAttribute::route('/{record}/edit'),
        ];
    }
}
