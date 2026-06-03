<?php

namespace App\Filament\Resources\CheckoutSagas;

use App\Domains\Orders\Models\CheckoutSaga;
use App\Filament\Resources\CheckoutSagas\Pages\EditCheckoutSaga;
use App\Filament\Resources\CheckoutSagas\Pages\ListCheckoutSagas;
use App\Filament\Resources\CheckoutSagas\Schemas\CheckoutSagaForm;
use App\Filament\Resources\CheckoutSagas\Tables\CheckoutSagasTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CheckoutSagaResource extends Resource
{
    protected static ?string $model = CheckoutSaga::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Checkout sagas';

    protected static ?string $modelLabel = 'checkout saga';

    protected static ?string $pluralModelLabel = 'checkout sagas';

    protected static string|\UnitEnum|null $navigationGroup = 'Orders';

    public static function form(Schema $schema): Schema
    {
        return CheckoutSagaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CheckoutSagasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckoutSagas::route('/'),
            'edit' => EditCheckoutSaga::route('/{record}/edit'),
        ];
    }
}
