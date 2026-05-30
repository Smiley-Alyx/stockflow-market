<?php

namespace App\Filament\Resources\HomepageBlocks;

use App\Domains\Homepage\Models\HomepageBlock;
use App\Filament\Resources\HomepageBlocks\Pages\CreateHomepageBlock;
use App\Filament\Resources\HomepageBlocks\Pages\EditHomepageBlock;
use App\Filament\Resources\HomepageBlocks\Pages\ListHomepageBlocks;
use App\Filament\Resources\HomepageBlocks\Schemas\HomepageBlockForm;
use App\Filament\Resources\HomepageBlocks\Tables\HomepageBlocksTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class HomepageBlockResource extends Resource
{
    protected static ?string $model = HomepageBlock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Homepage blocks';

    protected static ?string $modelLabel = 'homepage block';

    protected static ?string $pluralModelLabel = 'homepage blocks';

    protected static string|\UnitEnum|null $navigationGroup = 'Homepage';

    public static function form(Schema $schema): Schema
    {
        return HomepageBlockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomepageBlocksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageBlocks::route('/'),
            'create' => CreateHomepageBlock::route('/create'),
            'edit' => EditHomepageBlock::route('/{record}/edit'),
        ];
    }
}
