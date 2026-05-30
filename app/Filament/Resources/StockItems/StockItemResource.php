<?php

namespace App\Filament\Resources\StockItems;

use App\Domains\Inventory\Models\StockItem;
use App\Filament\Resources\StockItems\Pages\CreateStockItem;
use App\Filament\Resources\StockItems\Pages\EditStockItem;
use App\Filament\Resources\StockItems\Pages\ListStockItems;
use App\Filament\Resources\StockItems\Pages\ViewStockItem;
use App\Filament\Resources\StockItems\Schemas\StockItemForm;
use App\Filament\Resources\StockItems\Schemas\StockItemInfolist;
use App\Filament\Resources\StockItems\Tables\StockItemsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StockItemResource extends Resource
{
    protected static ?string $model = StockItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $modelLabel = 'stock item';

    protected static ?string $pluralModelLabel = 'stock items';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    public static function form(Schema $schema): Schema
    {
        return StockItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockItemsTable::configure($table);
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
            'index' => ListStockItems::route('/'),
            'create' => CreateStockItem::route('/create'),
            'view' => ViewStockItem::route('/{record}'),
            'edit' => EditStockItem::route('/{record}/edit'),
        ];
    }
}
