<?php

namespace App\Filament\Resources\CatalogProductResource\Pages;

use App\Filament\Resources\CatalogProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCatalogProducts extends ListRecords
{
    protected static string $resource = CatalogProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
