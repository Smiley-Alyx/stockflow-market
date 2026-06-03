<?php

namespace App\Filament\Resources\ProductFiles;

use App\Domains\Catalog\Models\ProductFile;
use App\Filament\Resources\ProductFiles\Pages\CreateProductFile;
use App\Filament\Resources\ProductFiles\Pages\EditProductFile;
use App\Filament\Resources\ProductFiles\Pages\ListProductFiles;
use App\Filament\Resources\ProductFiles\Pages\ViewProductFile;
use App\Filament\Resources\ProductFiles\Schemas\ProductFileForm;
use App\Filament\Resources\ProductFiles\Schemas\ProductFileInfolist;
use App\Filament\Resources\ProductFiles\Tables\ProductFilesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductFileResource extends Resource
{
    protected static ?string $model = ProductFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Product files';

    protected static ?string $modelLabel = 'product file';

    protected static ?string $pluralModelLabel = 'product files';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    public static function form(Schema $schema): Schema
    {
        return ProductFileForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductFileInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductFilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductFiles::route('/'),
            'create' => CreateProductFile::route('/create'),
            'view' => ViewProductFile::route('/{record}'),
            'edit' => EditProductFile::route('/{record}/edit'),
        ];
    }
}
