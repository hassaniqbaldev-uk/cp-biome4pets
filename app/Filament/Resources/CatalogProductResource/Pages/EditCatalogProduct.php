<?php

namespace App\Filament\Resources\CatalogProductResource\Pages;

use App\Filament\Resources\CatalogProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCatalogProduct extends EditRecord
{
    protected static string $resource = CatalogProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['triggers'] = $this->record->trigger_codes;
        return $data;
    }

    protected function afterSave(): void
    {
        $triggers = $this->data['triggers'] ?? [];
        $this->record->trigger_codes = $triggers;
    }
}
