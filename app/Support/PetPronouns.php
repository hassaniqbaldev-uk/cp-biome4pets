<?php

namespace App\Support;

/**
 * Single source of truth for the pronoun instruction handed to the AI, so EVERY
 * generation prompt (the per-section interpretations AND the separate plan-copy
 * call) gives the model the same fixed pronoun guidance and the prose can't drift
 * (a female dog described as "she" up top but "he" in the recommendation tips).
 *
 * Sex is stored on Pet as 'Male' / 'Female' (nullable). Anything else — blank,
 * null, "unknown" — is treated as UNKNOWN: the model must use the pet's name or
 * neutral they/their and must never guess a gender.
 */
class PetPronouns
{
    /** Normalise the stored sex to 'female' | 'male' | null (unknown). */
    public static function normalise(?string $sex): ?string
    {
        return match (strtolower(trim((string) $sex))) {
            'female', 'f' => 'female',
            'male', 'm' => 'male',
            default => null,
        };
    }

    /**
     * The fixed pronoun instruction for this pet's sex. Worded to apply across
     * ALL prose fields and to forbid drifting to the wrong gender.
     */
    public static function instruction(?string $sex): string
    {
        return match (self::normalise($sex)) {
            'female' => 'This pet is female. Use she/her pronouns for the pet CONSISTENTLY in every field, including the recommendations and any tips; never refer to her as "he" or "him".',
            'male' => 'This pet is male. Use he/him pronouns for the pet CONSISTENTLY in every field, including the recommendations and any tips; never refer to him as "she" or "her".',
            default => 'This pet\'s sex is not recorded. Do NOT guess or assume a gender: refer to the pet by name or use neutral they/their pronouns CONSISTENTLY in every field, and never use he/him or she/her.',
        };
    }
}
