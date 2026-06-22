<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Forms;
use Filament\Forms\Set;

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
