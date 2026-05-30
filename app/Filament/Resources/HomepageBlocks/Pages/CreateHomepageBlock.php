<?php

namespace App\Filament\Resources\HomepageBlocks\Pages;

use App\Filament\Resources\HomepageBlocks\HomepageBlockResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHomepageBlock extends CreateRecord
{
    protected static string $resource = HomepageBlockResource::class;
}
