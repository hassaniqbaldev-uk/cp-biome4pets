<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Well-known setting keys.
     */
    public const OPENAI_API_KEY = 'openai_api_key';

    public const OPENAI_PROMPT_DIRECTIVES = 'openai_prompt_directives';

    /**
     * Per-section AI directives — optional free-text steering injected INLINE
     * next to the relevant field instructions in the interpretations prompt
     * (see OpenAiService::generateReportInterpretations). Each is additive and
     * append-only: blank = no effect, identical behaviour to leaving it unset.
     * Stored plaintext like OPENAI_PROMPT_DIRECTIVES (the global directive,
     * which still appends after the whole prompt).
     */
    public const OPENAI_DIRECTIVE_SUMMARY = 'openai_directive_summary';

    public const OPENAI_DIRECTIVE_VET_SUMMARY = 'openai_directive_vet_summary';

    public const OPENAI_DIRECTIVE_PHYLA = 'openai_directive_phyla';

    public const OPENAI_DIRECTIVE_SCORES = 'openai_directive_scores';

    public const SIGNS_OF_STABILITY = 'signs_of_stability';

    /**
     * Plan-generation config — read by OpenAiService::generatePlanCopy().
     * Each consumer falls back to a sensible default when the value is blank.
     */
    public const PLAN_GENERATION_MODEL = 'plan_generation_model';

    public const PLAN_GENERATION_TEMPERATURE = 'plan_generation_temperature';

    public const PLAN_GENERATION_SYSTEM_PROMPT = 'plan_generation_system_prompt';

    /**
     * Default dose text used for a plan product when none is entered.
     * DEFAULT_DOSE is the setting key; DEFAULT_DOSE_FALLBACK is the literal
     * used when the setting itself is blank.
     */
    public const DEFAULT_DOSE = 'default_dose';

    public const DEFAULT_DOSE_FALLBACK = 'Follow recommended dose on label.';

    /**
     * Master switch for subscriptions. When false, the public render hides the
     * subscribe panel and the bottom reminder regardless of per-plan settings.
     */
    public const SUBSCRIPTIONS_ENABLED = 'subscriptions_enabled';

    /**
     * Display currency (stored for display use; no behavioural change today).
     */
    public const CURRENCY = 'currency';

    /**
     * Klaviyo integration (server-side Events API). The private API key is
     * stored encrypted (same mechanism as the OpenAI key — setEncrypted /
     * getDecrypted). Revision and base URL are config-driven so they can be
     * changed from the admin UI without a code edit; each falls back to its
     * *_DEFAULT constant when the stored value is blank.
     */
    public const KLAVIYO_API_KEY = 'klaviyo_api_key';

    public const KLAVIYO_ENABLED = 'klaviyo_enabled';

    public const KLAVIYO_REVISION = 'klaviyo_revision';

    public const KLAVIYO_REVISION_DEFAULT = '2026-04-15';

    public const KLAVIYO_BASE_URL = 'klaviyo_base_url';

    public const KLAVIYO_BASE_URL_DEFAULT = 'https://a.klaviyo.com';

    /**
     * Last Klaviyo diagnostic result (Test Connection / Send Test Event),
     * stored as JSON: {at, action, ok, message}. Surfaced on the settings
     * screen so the most recent outcome persists across reloads.
     */
    public const KLAVIYO_LAST_RESULT = 'klaviyo_last_result';

    /**
     * Read a raw setting value. Resilient: if the table does not yet exist
     * (e.g. before migration) it returns the default instead of throwing,
     * so callers like OpenAiService never break.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $row = static::query()->where('key', $key)->first();
        } catch (\Throwable) {
            return $default;
        }

        return $row?->value ?? $default;
    }

    /**
     * Create or update a setting (stores the value verbatim).
     */
    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Read and decrypt an encrypted setting. Returns the default if the value
     * is empty or cannot be decrypted.
     */
    public static function getDecrypted(string $key, mixed $default = null): mixed
    {
        $value = static::get($key);

        if (blank($value)) {
            return $default;
        }

        try {
            return decrypt($value);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Encrypt and store a setting, so it is never written to the DB in plaintext.
     */
    public static function setEncrypted(string $key, string $value): void
    {
        static::set($key, encrypt($value));
    }
}
