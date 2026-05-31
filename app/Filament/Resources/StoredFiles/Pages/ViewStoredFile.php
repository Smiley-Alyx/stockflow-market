<?php

namespace App\Filament\Resources\StoredFiles\Pages;

use App\Filament\Resources\StoredFiles\StoredFileResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStoredFile extends ViewRecord
{
    protected static string $resource = StoredFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
