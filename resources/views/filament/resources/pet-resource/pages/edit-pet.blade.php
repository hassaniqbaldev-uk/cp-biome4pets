<x-filament-panels::page>
    {{-- Standard edit form. The fields sit in a collapsible "Pet details" section
         (collapsed on edit, expanded on create) defined in PetResource::form(). --}}
    <x-filament-panels::form id="form" wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

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
