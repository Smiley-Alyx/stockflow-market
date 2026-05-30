<?php

namespace App\Filament\Resources\HomepageBlocks\Pages;

use App\Filament\Resources\HomepageBlocks\HomepageBlockResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHomepageBlock extends EditRecord
{
    protected static string $resource = HomepageBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
