<?php

namespace App\Filament\Resources\StoredFiles\Pages;

use App\Filament\Resources\StoredFiles\StoredFileResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStoredFile extends EditRecord
{
    protected static string $resource = StoredFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
