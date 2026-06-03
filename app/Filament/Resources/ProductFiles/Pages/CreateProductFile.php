<?php

namespace App\Filament\Resources\ProductFiles\Pages;

use App\Filament\Resources\ProductFiles\ProductFileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductFile extends CreateRecord
{
    protected static string $resource = ProductFileResource::class;
}
