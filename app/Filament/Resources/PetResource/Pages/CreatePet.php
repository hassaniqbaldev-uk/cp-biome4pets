<?php

namespace App\Filament\Resources\PetResource\Pages;

use App\Filament\Resources\PetResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePet extends CreateRecord
{
    protected static string $resource = PetResource::class;

    // Transient first-entry values, lifted out of the form data before the Pet is
    // created (they are not Pet columns) and written to the health-notes log after.
    protected ?string $initialNote = null;

    protected mixed $initialWeight = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->initialNote = $data['initial_note'] ?? null;
        $this->initialWeight = $data['initial_weight_kg'] ?? null;

        unset($data['initial_note'], $data['initial_weight_kg']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if (blank($this->initialNote) && blank($this->initialWeight)) {
            return;
        }

        $this->record->healthNotes()->create([
            'date' => today(),
            'note' => filled($this->initialNote) ? $this->initialNote : null,
            'weight_kg' => filled($this->initialWeight) ? $this->initialWeight : null,
        ]);
    }
}
