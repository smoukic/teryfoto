<?php

namespace App\Filament\Resources\CatalogResource\Pages;

use App\Filament\Resources\CatalogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCatalog extends ManageRecords
{
    protected static string $resource = CatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Přidat položku')];
    }
}
