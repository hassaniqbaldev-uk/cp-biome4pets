<?php

namespace App\Filament\Resources\PetResource\Pages;

use App\Filament\Resources\PetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPet extends EditRecord
{
    protected static string $resource = PetResource::class;

    /**
     * Custom view: a compact collapsible form, then the Tests / Health Notes
     * relation managers and the History (timeline) widget arranged as three tabs
     * (Filament stacks relation managers by default; the tabs are hosted in the
     * Blade view). Each surface keeps all its behaviour — only placement changes.
     */
    protected static string $view = 'filament.resources.pet-resource.pages.edit-pet';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
