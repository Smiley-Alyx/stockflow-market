<?php

namespace App\Filament\Resources\StoredFiles\Pages;

use App\Filament\Resources\StoredFiles\StoredFileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStoredFile extends CreateRecord
{
    protected static string $resource = StoredFileResource::class;
}
