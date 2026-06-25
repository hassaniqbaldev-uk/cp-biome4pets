<?php

namespace App\Filament\Forms\Components;

use App\Models\Breed;
use Closure;
use Filament\Forms\Components\Field;

/**
 * A single type-or-pick breed combobox (no popup). Renders a native-looking text
 * input with an Alpine-controlled dropdown of the managed breeds:
 *   • focus/click → shows ALL breeds,
 *   • typing      → filters them (case-insensitive substring), AND the typed text
 *                   is itself the value, so a brand-new breed is accepted inline.
 *
 * The value is a plain breed STRING bound to the form state exactly like any field
 * (so it saves to pets.breed and pre-fills on edit). New breeds are folded into the
 * breeds table by the Pet "saved" hook — this component only handles input.
 */
class BreedAutocomplete extends Field
{
    protected string $view = 'filament.forms.components.breed-autocomplete';

    /** @var array<int,string>|Closure|null */
    protected array | Closure | null $breeds = null;

    /**
     * Override the suggestion list (defaults to every managed breed, A–Z). The list
     * is filtered client-side, which is fine for a few hundred breeds.
     *
     * @param  array<int,string>|Closure  $breeds
     */
    public function breeds(array | Closure $breeds): static
    {
        $this->breeds = $breeds;

        return $this;
    }

    /** @return array<int,string> */
    public function getBreeds(): array
    {
        if ($this->breeds !== null) {
            return array_values($this->evaluate($this->breeds));
        }

        return Breed::query()->orderBy('name')->pluck('name')->all();
    }
}
