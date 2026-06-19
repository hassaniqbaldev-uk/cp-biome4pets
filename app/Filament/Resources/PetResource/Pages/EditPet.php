<?php

namespace App\Filament\Resources\PetResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\PetResource;
use App\Models\Pet;
use App\Support\AdminFormatting;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\FontWeight;

/**
 * The Pet hub. The default view is a READ-ONLY header (petHeaderInfolist) that
 * shows the pet's key identity at a glance in a grid — name, owner, breed, sex,
 * DOB/age, diet, latest weight. Editing the rarely-changed details is behind an
 * explicit "Edit pet details" action that swaps the header for the form. Below
 * sit the Tests / Health Notes / History tabs (hosted in the Blade view).
 */
class EditPet extends EditRecord implements HasInfolists
{
    use InteractsWithInfolists;

    protected static string $resource = PetResource::class;

    protected static string $view = 'filament.resources.pet-resource.pages.edit-pet';

    /** When true, the header is swapped for the editable form. Default: read view. */
    public bool $editing = false;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Switch the header into the editable form. */
    public function editPetDetails(): void
    {
        $this->editing = true;
    }

    /** Leave edit mode without saving, discarding any unsaved field changes. */
    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->fillForm();
    }

    /** After a successful save, drop back to the read-only header. */
    protected function afterSave(): void
    {
        $this->editing = false;
    }

    /**
     * The at-a-glance pet header: a compact grid of the pet's key info, rendered
     * read-only (an infolist, not a form). Owner links up to the client hub.
     */
    public function petHeaderInfolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->record)
            ->schema([
                Section::make()
                    ->heading(fn (Pet $record): string => $record->name)
                    ->description(fn (Pet $record): ?string => $record->breed)
                    ->icon('heroicon-o-heart')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('client.name')
                                ->label('Owner')
                                ->weight(FontWeight::SemiBold)
                                ->icon('heroicon-m-user')
                                ->placeholder('—')
                                ->url(fn (Pet $record): ?string => $record->client
                                    ? ClientResource::getUrl('edit', ['record' => $record->client])
                                    : null),
                            TextEntry::make('breed')
                                ->placeholder('—'),
                            TextEntry::make('sex')
                                ->placeholder('—'),
                            TextEntry::make('date_of_birth')
                                ->label('Date of birth')
                                ->placeholder('—')
                                ->state(fn (Pet $record): ?string => $record->date_of_birth
                                    ? $record->date_of_birth->format(AdminFormatting::DATE)
                                        . ($record->ageLabel() ? ' · ' . $record->ageLabel() : '')
                                    : null),
                            TextEntry::make('diet')
                                ->placeholder('—'),
                            TextEntry::make('latest_weight')
                                ->label('Latest weight')
                                ->placeholder('—')
                                ->state(fn (Pet $record): ?string => $record->latestWeightKg() !== null
                                    ? number_format($record->latestWeightKg(), 2) . ' kg'
                                    : null),
                        ]),
                    ]),
            ]);
    }
}
