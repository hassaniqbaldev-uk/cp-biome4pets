<x-filament-panels::page>
    {{-- Pet header. The DEFAULT view is a read-only, scannable infolist so the
         pet's key info is visible at a glance. Editing the rarely-changed details
         is behind the explicit "Edit pet details" action, which swaps the header
         for the (2-column) form. --}}
    <div wire:key="pet-header-{{ $record->getKey() }}">
        @unless ($editing)
            <div class="flex justify-end mb-3">
                <x-filament::button
                    wire:click="editPetDetails"
                    icon="heroicon-m-pencil-square"
                    color="gray"
                    size="sm"
                >
                    Edit pet details
                </x-filament::button>
            </div>

            {{ $this->petHeaderInfolist }}
        @else
            <x-filament-panels::form id="form" wire:submit="save">
                {{ $this->form }}

                <div class="flex items-center gap-3">
                    <x-filament::button type="submit" icon="heroicon-m-check">
                        Save changes
                    </x-filament::button>

                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="cancelEdit"
                    >
                        Cancel
                    </x-filament::button>
                </div>
            </x-filament-panels::form>
        @endunless
    </div>

    {{-- Tabs: Tests | Health Notes | History. Only one surface shows at a time,
         so the page is scannable and the timeline (History) no longer stacks
         below — and duplicates — the Tests/Notes tables. Each component is mounted
         the same way Filament mounts relation managers/widgets, so all of their
         actions and filters keep working. --}}
    <div
        x-data="{ tab: 'tests' }"
        wire:key="pet-hub-tabs-{{ $record->getKey() }}"
    >
        <x-filament::tabs>
            <x-filament::tabs.item
                icon="heroicon-m-beaker"
                x-on:click="tab = 'tests'"
                alpine-active="tab === 'tests'"
            >
                Tests
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-m-clipboard-document-list"
                x-on:click="tab = 'notes'"
                alpine-active="tab === 'notes'"
            >
                Health Notes
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-m-clock"
                x-on:click="tab = 'history'"
                alpine-active="tab === 'history'"
            >
                History
            </x-filament::tabs.item>
        </x-filament::tabs>

        <div class="mt-6">
            <div x-show="tab === 'tests'">
                @livewire(
                    \App\Filament\Resources\PetResource\RelationManagers\TestsRelationManager::class,
                    ['ownerRecord' => $record, 'pageClass' => \App\Filament\Resources\PetResource\Pages\EditPet::class],
                    'pet-tests-' . $record->getKey()
                )
            </div>

            <div x-show="tab === 'notes'" x-cloak>
                @livewire(
                    \App\Filament\Resources\PetResource\RelationManagers\HealthNotesRelationManager::class,
                    ['ownerRecord' => $record, 'pageClass' => \App\Filament\Resources\PetResource\Pages\EditPet::class],
                    'pet-notes-' . $record->getKey()
                )
            </div>

            <div x-show="tab === 'history'" x-cloak>
                @livewire(
                    \App\Filament\Resources\PetResource\Widgets\PetTimelineWidget::class,
                    ['record' => $record],
                    'pet-history-' . $record->getKey()
                )
            </div>
        </div>
    </div>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
