<?php

namespace App\Filament\Resources\StoredFiles;

use App\Domains\Storage\Models\StoredFile;
use App\Filament\Resources\StoredFiles\Pages\CreateStoredFile;
use App\Filament\Resources\StoredFiles\Pages\EditStoredFile;
use App\Filament\Resources\StoredFiles\Pages\ListStoredFiles;
use App\Filament\Resources\StoredFiles\Pages\ViewStoredFile;
use App\Filament\Resources\StoredFiles\Schemas\StoredFileForm;
use App\Filament\Resources\StoredFiles\Schemas\StoredFileInfolist;
use App\Filament\Resources\StoredFiles\Tables\StoredFilesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StoredFileResource extends Resource
{
    protected static ?string $model = StoredFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Files';

    protected static ?string $modelLabel = 'file';

    protected static ?string $pluralModelLabel = 'files';

    protected static string|\UnitEnum|null $navigationGroup = 'Storage';

    public static function form(Schema $schema): Schema
    {
        return StoredFileForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StoredFileInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoredFilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoredFiles::route('/'),
            'create' => CreateStoredFile::route('/create'),
            'view' => ViewStoredFile::route('/{record}'),
            'edit' => EditStoredFile::route('/{record}/edit'),
        ];
    }
}
