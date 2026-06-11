<?php

namespace App\Filament\Resources\CatalogProductResource\Pages;

use App\Filament\Resources\CatalogProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCatalogProduct extends CreateRecord
{
    protected static string $resource = CatalogProductResource::class;

    protected function afterCreate(): void
    {
        $triggers = $this->data['triggers'] ?? [];
        $this->record->trigger_codes = $triggers;
    }
}
