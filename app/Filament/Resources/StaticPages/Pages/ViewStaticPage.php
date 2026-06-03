<?php

namespace App\Filament\Resources\StaticPages\Pages;

use App\Filament\Resources\StaticPages\StaticPageResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStaticPage extends ViewRecord
{
    protected static string $resource = StaticPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
