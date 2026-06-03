<?php

namespace App\Filament\Resources\StockMovementArchives;

use App\Domains\Inventory\Models\StockMovementArchive;
use App\Filament\Resources\StockMovementArchives\Pages\ListStockMovementArchives;
use App\Filament\Resources\StockMovementArchives\Pages\ViewStockMovementArchive;
use App\Filament\Resources\StockMovementArchives\Schemas\StockMovementArchiveInfolist;
use App\Filament\Resources\StockMovementArchives\Tables\StockMovementArchivesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StockMovementArchiveResource extends Resource
{
    protected static ?string $model = StockMovementArchive::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Movement archive';

    protected static ?string $modelLabel = 'movement archive';

    protected static ?string $pluralModelLabel = 'movement archive';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    public static function infolist(Schema $schema): Schema
    {
        return StockMovementArchiveInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockMovementArchivesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovementArchives::route('/'),
            'view' => ViewStockMovementArchive::route('/{record}'),
        ];
    }
}
