<?php

namespace App\Filament\Resources\TestResource\Pages;

use App\Filament\Resources\TestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The all-tests list. No create header action — tests are created under a pet
 * (PetResource → Tests), so this page is for finding, viewing and acting on
 * existing tests only.
 */
class ListTests extends ListRecords
{
    protected static string $resource = TestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
