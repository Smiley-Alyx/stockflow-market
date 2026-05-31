<?php

namespace App\Filament\Resources\StoredFiles\Pages;

use App\Filament\Resources\StoredFiles\StoredFileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStoredFiles extends ListRecords
{
    protected static string $resource = StoredFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
