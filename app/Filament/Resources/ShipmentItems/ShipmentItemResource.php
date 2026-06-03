<?php

namespace App\Filament\Resources\ShipmentItems;

use App\Domains\Orders\Models\ShipmentItem;
use App\Filament\Resources\ShipmentItems\Pages\CreateShipmentItem;
use App\Filament\Resources\ShipmentItems\Pages\EditShipmentItem;
use App\Filament\Resources\ShipmentItems\Pages\ListShipmentItems;
use App\Filament\Resources\ShipmentItems\Schemas\ShipmentItemForm;
use App\Filament\Resources\ShipmentItems\Tables\ShipmentItemsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShipmentItemResource extends Resource
{
    protected static ?string $model = ShipmentItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Shipment items';

    protected static ?string $modelLabel = 'shipment item';

    protected static ?string $pluralModelLabel = 'shipment items';

    protected static string|\UnitEnum|null $navigationGroup = 'Orders';

    public static function form(Schema $schema): Schema
    {
        return ShipmentItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipmentItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipmentItems::route('/'),
            'create' => CreateShipmentItem::route('/create'),
            'edit' => EditShipmentItem::route('/{record}/edit'),
        ];
    }
}
