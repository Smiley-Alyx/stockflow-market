<?php

namespace App\Filament\Resources\StoredFiles\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StoredFileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('original_name'),
                TextEntry::make('disk'),
                TextEntry::make('path'),
                TextEntry::make('source_url'),
                TextEntry::make('mime_type'),
                TextEntry::make('size'),
                TextEntry::make('checksum'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
