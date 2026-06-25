@php
    $statePath = $getStatePath();
    $breeds = $getBreeds();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    {{-- ONE field, no popup: a text input + an Alpine dropdown of breeds.
         `state` is two-way bound to the form state via $entangle, so whatever is
         TYPED (a brand-new breed) or PICKED (an existing one) becomes the value and
         saves to pets.breed. Deferred entangle = no per-keystroke server calls. --}}
    <div
        x-data="{
            open: false,
            options: @js($breeds),
            state: $wire.$entangle('{{ $statePath }}'),
            get filtered() {
                const q = (this.state ?? '').toString().toLowerCase().trim();
                if (q === '') return this.options;
                return this.options.filter((o) => o.toLowerCase().includes(q));
            },
            pick(option) {
                this.state = option;
                this.open = false;
            },
        }"
        x-on:keydown.escape.stop="open = false"
        @class(['fi-fo-breed-autocomplete relative'])
    >
        <x-filament::input.wrapper :valid="! $errors->has($statePath)">
            <x-filament::input
                type="text"
                autocomplete="off"
                maxlength="255"
                placeholder="Type to search, or enter a new breed"
                x-model="state"
                x-on:focus="open = true"
                x-on:mousedown="open = true"
                x-on:input="open = true"
            />
        </x-filament::input.wrapper>

        {{-- Dropdown: ALL breeds on focus, filtered as you type. Scrollable + capped
             height so a long list stays graceful. Closes on outside click / pick. --}}
        <div
            x-show="open"
            x-cloak
            x-on:click.outside="open = false"
            x-transition.opacity
            class="fi-dropdown-panel absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <template x-for="option in filtered" :key="option">
                <button
                    type="button"
                    x-on:click="pick(option)"
                    x-text="option"
                    class="block w-full px-3 py-2 text-start text-sm text-gray-950 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:text-white dark:hover:bg-white/5 dark:focus:bg-white/5"
                ></button>
            </template>

            <p
                x-show="filtered.length === 0"
                class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400"
            >
                No match — your text will be saved as a new breed.
            </p>
        </div>
    </div>
</x-dynamic-component>
