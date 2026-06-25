<?php

namespace App\Filament\Forms;

use App\Filament\Forms\Components\BreedAutocomplete;
use App\Models\Pet;
use Closure;
use Filament\Forms;
use Filament\Forms\Set;
use Illuminate\Support\Carbon;

/**
 * The shared "is_sensitive" / "is_large_breed" pet-profile fields, so every pet
 * form (PetResource, the report-wizard create-option form, the client hub's pets
 * relation manager) renders identical labels, helper text and behaviour.
 *
 * is_large_breed is auto-driven by the pet's WEIGHT (kg): attach
 * largeBreedFromWeight() as the weight field's ->live()->afterStateUpdated() so it
 * re-evaluates on every weight change. The checkbox stays visible/editable.
 */
class PetProfileFields
{
    /** The large-breed threshold, in KILOGRAMS (weight is stored as weight_kg). */
    public const LARGE_BREED_THRESHOLD_KG = 35.0;

    /** How far back the year-of-birth dropdown reaches from the current year. */
    public const BIRTH_YEAR_RANGE = 30;

    /**
     * Breed field — shared across every pet form. A custom type-or-pick combobox
     * (App\Filament\Forms\Components\BreedAutocomplete): focus shows ALL managed
     * breeds, typing filters them, and the user can either pick an existing breed or
     * keep typing a brand-new one in the SAME field — no popup, no "+", no separate
     * create step. Full control over focus/filter, unlike the browser datalist.
     *
     * The value is a plain breed STRING bound to the form like any field, so it
     * saves to pets.breed and pre-fills on edit. New breeds are folded into the
     * lookup table case-insensitively by the Pet "saved" hook (findOrCreateByName).
     */
    public static function breed(): BreedAutocomplete
    {
        return BreedAutocomplete::make('breed')
            ->label('Breed')
            ->helperText('Start typing to pick an existing breed, or just type a new one.');
    }

    /**
     * Year-of-birth dropdown — shared across every pet form. Owners often don't
     * know the exact date, so we collect the YEAR only. It writes to the EXISTING
     * date_of_birth column (no schema change) as the 1st of January of that year
     * (YYYY-01-01), and on edit it shows the year of whatever full date is stored,
     * so existing dated pets display + round-trip cleanly.
     *
     * The option list always includes the record's stored year even if it predates
     * the normal range, so editing+saving an old pet can never wipe its DOB.
     */
    public static function yearOfBirth(): Forms\Components\Select
    {
        return Forms\Components\Select::make('date_of_birth')
            ->label('Year of birth')
            ->placeholder('Select year')
            ->native(false)
            ->options(function (?Pet $record): array {
                $current = (int) Carbon::now()->year;
                $min = $current - self::BIRTH_YEAR_RANGE;

                // Never drop an existing (possibly older) stored year from the list.
                if ($record?->date_of_birth) {
                    $min = min($min, (int) $record->date_of_birth->year);
                }

                $years = [];
                for ($y = $current; $y >= $min; $y--) {
                    $years[(string) $y] = (string) $y;
                }

                return $years;
            })
            // Stored date (or null) -> the year, so the select reflects existing data.
            ->formatStateUsing(fn ($state): ?string => filled($state)
                ? (string) Carbon::parse($state)->year
                : null)
            // Selected year -> the 1st of January of that year, so the column stays
            // a real date and the type is unchanged.
            ->dehydrateStateUsing(fn ($state): ?string => filled($state)
                ? $state.'-01-01'
                : null);
    }

    /**
     * The two profile flag fields — identical across every pet form.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function flags(): array
    {
        return [
            Forms\Components\Checkbox::make('is_sensitive')
                ->label('Sensitive animal')
                ->helperText('Select this if the animal has a known sensitivity or is on medication prescribed by their vet.'),
            Forms\Components\Checkbox::make('is_large_breed')
                ->label('Large breed')
                ->helperText('Select if the animal is over 35kg. Auto-ticked from the entered weight (35kg+); you can still adjust it.'),
        ];
    }

    /**
     * Weight-field afterStateUpdated handler: re-evaluate is_large_breed from the
     * latest weight on EVERY change. >= 35 kg ticks it, anything below (or blank)
     * unticks it — so the weight is the source of truth each time it changes.
     */
    public static function largeBreedFromWeight(): Closure
    {
        return function ($state, Set $set): void {
            $isLarge = $state !== null && $state !== ''
                && (float) $state >= self::LARGE_BREED_THRESHOLD_KG;

            $set('is_large_breed', $isLarge);
        };
    }
}
