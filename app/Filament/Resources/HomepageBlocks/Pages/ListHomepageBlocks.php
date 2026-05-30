<?php

namespace App\Filament\Resources\HomepageBlocks\Pages;

use App\Filament\Resources\HomepageBlocks\HomepageBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHomepageBlocks extends ListRecords
{
    protected static string $resource = HomepageBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
