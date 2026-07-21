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
     * The single OpenAI model used by BOTH generation calls (report interpretation
     * AND plan copy), resolved through OpenAiService::resolveModel(). Empty / unknown
     * / missing always falls back to config('services.openai.model') (gpt-4o), so it
     * can never break or change today's behaviour when unset. Replaces the retired
     * per-call PLAN_GENERATION_MODEL.
     */
    public const OPENAI_MODEL = 'openai_model';

    /**
     * Plan-generation config — read by OpenAiService::generatePlanCopy().
     * Each consumer falls back to a sensible default when the value is blank.
     *
     * NOTE: PLAN_GENERATION_MODEL is RETIRED — the model is now the single
     * Setting::OPENAI_MODEL above. The constant is kept only so the consolidation
     * migration can read/carry over any existing live value; nothing reads it at
     * generation time any more.
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
     * Editable per-model OpenAI token rates, stored as a JSON map
     * model => {input_per_1k, output_per_1k} in the display currency
     * (Setting::CURRENCY, default GBP). Admin-maintained — they drive the
     * COST ESTIMATE on the OpenAI settings tab and are deliberately editable
     * (not hardcoded) because OpenAI prices change and differ per model and per
     * direction (prompt/input vs completion/output).
     *
     * Seeded with the *_DEFAULT below: approximate current rates for the
     * whitelist models, to be verified against OpenAI's published pricing. These
     * produce an ESTIMATE only — never the amount OpenAI actually bills.
     */
    public const OPENAI_TOKEN_RATES = 'openai_token_rates';

    /**
     * Seeded per-1,000-token rates for the whitelist models, in the display
     * currency (GBP by default). Approximate GBP conversions of OpenAI's USD
     * list prices — admins should verify and adjust against current pricing.
     *
     * @var array<string, array{input_per_1k: float, output_per_1k: float}>
     */
    public const OPENAI_TOKEN_RATES_DEFAULT = [
        'gpt-4o' => ['input_per_1k' => 0.002, 'output_per_1k' => 0.008],
        'gpt-4o-mini' => ['input_per_1k' => 0.00012, 'output_per_1k' => 0.00048],
        'gpt-4-turbo' => ['input_per_1k' => 0.008, 'output_per_1k' => 0.024],
        'gpt-4' => ['input_per_1k' => 0.024, 'output_per_1k' => 0.048],
    ];

    /**
     * Review figures shown on the subscribe interstitial. Admin-editable; each
     * falls back to its *_DEFAULT when blank so the page never shows an empty
     * rating/count.
     */
    public const REVIEW_RATING = 'review_rating';

    public const REVIEW_RATING_DEFAULT = '4.9';

    public const REVIEW_COUNT = 'review_count';

    public const REVIEW_COUNT_DEFAULT = '1,000+';

    /**
     * Static, every-report text blocks (the "Help and Contacts" section that is
     * identical on every report). Admin-editable so the same edit updates BOTH the
     * web report and the PDF (they read from these keys), and so compliance copy
     * — notably the "not intended to diagnose" disclaimer fused into the About
     * text — can be changed without a code deploy. Each falls back to its
     * *_DEFAULT (the original hardcoded copy) when blank, so a fresh install is
     * visually unchanged.
     *
     * APPROACH/SUPPORT are newline-separated: APPROACH renders one bullet per
     * non-blank line; SUPPORT renders with line breaks preserved. All are escaped
     * on render (admin-entered, never raw HTML).
     */
    public const REPORT_ABOUT_TEXT = 'report_about_text';

    public const REPORT_ABOUT_TEXT_DEFAULT = "This report is based on advanced analysis of your dog's gut microbiome using 16S rRNA sequencing, one of the most accurate methods available for identifying bacterial populations. Biome4Pets maintains one of the largest canine microbiome data libraries, built from thousands of samples across a wide range of breeds, diets, and health conditions. Using population-based data analysis and artificial intelligence, we are able to identify patterns associated with both health and disease. This report provides a detailed snapshot of your dog's microbiome and highlights key areas of imbalance. While this information is a powerful tool for understanding gut health, it is not intended to diagnose disease. If your dog is unwell, please consult your veterinarian.";

    public const REPORT_APPROACH_TEXT = 'report_approach_text';

    public const REPORT_APPROACH_TEXT_DEFAULT = "Large-scale canine microbiome database\nAI-driven analysis and pattern recognition\nLinked to real-world health conditions\nFocused on practical, evidence-based support";

    public const REPORT_SUPPORT_TEXT = 'report_support_text';

    public const REPORT_SUPPORT_TEXT_DEFAULT = "If you would like help interpreting your dog's results or guidance on next steps, we are here to support you.\nEmail: info@biome4pets.com\nWebsite: www.biome4pets.com\nConsultations are available if you would like to discuss your dog's results in more detail, please book through the website.";

    /**
     * The nutritionist DIET-REVIEW statement body — shown on a kibble-fed + imbalanced
     * report. Editable so the wording can change without a deploy; blank falls back to
     * the default below. ONLY the prose is editable: the product link, its button label
     * and the 10% loyalty note stay templated (see ReportContent) so they can't be
     * broken here.
     */
    public const DIET_REVIEW_TEXT = 'diet_review_text';

    public const DIET_REVIEW_TEXT_DEFAULT = "We recommend speaking with one of our nutritionists, as your dog's diet may be contributing to their microbiome imbalance. Gut health and nutrition go hand in hand, and by reviewing your dog's microbiome results alongside their current diet, our nutritionists can identify foods and feeding strategies that better support a healthy, balanced microbiome and help optimise long-term gut health.";

    /**
     * DISPLAY-ONLY scientific band boundaries (Tier A of the classification-rules
     * audit). These change only what BAND LABEL a report prints — they do NOT feed
     * classify(), plan routing or the nutritionist trigger, so editing them cannot
     * break anything downstream. Each holds ONLY the setting key; the default is the
     * domain constant (ReportContent / HealthInsightRules), read with a sane-range
     * fallback so an unset/blank/out-of-range value is IDENTICAL to today's behaviour.
     * (This deliberately excludes the CLASSIFICATION thresholds — DIVERSITY_LOW_MAX,
     * DIVERSITY_STABLE_MIN, RICHNESS_LOW_MAX, DYSBIOSIS_HEALTHY_MIN/MAX — which stay
     * in code because a non-expert changing them would mislabel real samples.)
     *
     *  - DISPLAY_DIVERSITY_HIGH_MIN   → ReportContent::diversityHighMin()  (default 2.5)
     *  - DISPLAY_RICHNESS_HEALTHY_MIN → ReportContent::richnessHealthyMin() (default 650)
     *  - HEALTH_INSIGHT_TARGET_TOLERANCE → HealthInsightRules::targetTolerance() (default 0.25)
     */
    public const DISPLAY_DIVERSITY_HIGH_MIN = 'display_diversity_high_min';

    public const DISPLAY_RICHNESS_HEALTHY_MIN = 'display_richness_healthy_min';

    public const HEALTH_INSIGHT_TARGET_TOLERANCE = 'health_insight_target_tolerance';

    /**
     * Plan-routing policy: when NO product trigger fires and the pet is classified
     * unwell (Imbalanced / Imbalanced & Depleted), assign the fallback plan
     * (Maintain & Protect) instead of leaving the report with no plan for manual
     * selection. The default is TRUE (the client's desired behaviour). Blank/unset
     * → the default. Read via unwellNoTriggerUsesFallback(); consumed by
     * ReportResource::recommendPlanWithReason(). Does NOT affect classification or
     * the product-trigger evaluation — only the plan SELECTION policy.
     */
    public const UNWELL_NO_TRIGGER_USES_FALLBACK = 'unwell_no_trigger_uses_fallback';

    public const UNWELL_NO_TRIGGER_USES_FALLBACK_DEFAULT = true;

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
     * Outbound SMTP (Amazon SES SMTP, 587 + STARTTLS). The password is stored
     * encrypted (same setEncrypted/getDecrypted mechanism as the Klaviyo key);
     * everything else is plaintext. Each value falls back to its *_DEFAULT when
     * blank. Read at runtime by App\Support\SmtpConfig to drive Laravel's mailer.
     */
    public const SMTP_ENABLED = 'smtp_enabled';

    public const SMTP_HOST = 'smtp_host';

    public const SMTP_HOST_DEFAULT = 'email-smtp.eu-west-2.amazonaws.com';

    public const SMTP_PORT = 'smtp_port';

    public const SMTP_PORT_DEFAULT = '587';

    public const SMTP_ENCRYPTION = 'smtp_encryption';

    public const SMTP_ENCRYPTION_DEFAULT = 'tls';

    public const SMTP_USERNAME = 'smtp_username';

    public const SMTP_PASSWORD = 'smtp_password';

    public const SMTP_FROM_ADDRESS = 'smtp_from_address';

    public const SMTP_FROM_ADDRESS_DEFAULT = 'portal@biome4pets.com';

    public const SMTP_FROM_NAME = 'smtp_from_name';

    public const SMTP_FROM_NAME_DEFAULT = 'Biome4Pets';

    /**
     * Last SMTP diagnostic result (Send test email), stored as JSON like the
     * Klaviyo one: {at, action, ok, message}.
     */
    public const SMTP_LAST_RESULT = 'smtp_last_result';

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

    /**
     * Whether an unwell pet that fires no product trigger is auto-assigned the
     * fallback (maintenance) plan. Blank/unset falls back to the default (TRUE).
     */
    public static function unwellNoTriggerUsesFallback(): bool
    {
        $raw = static::get(self::UNWELL_NO_TRIGGER_USES_FALLBACK);

        return blank($raw)
            ? self::UNWELL_NO_TRIGGER_USES_FALLBACK_DEFAULT
            : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /** The configured display currency code (blank falls back to GBP). */
    public static function currencyCode(): string
    {
        $code = static::get(self::CURRENCY);

        return filled($code) ? strtoupper(trim((string) $code)) : 'GBP';
    }

    /**
     * A short display symbol for the configured currency. Falls back to the
     * currency code plus a space for anything unmapped, so it is never blank.
     */
    public static function currencySymbol(): string
    {
        $code = static::currencyCode();

        return [
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            'AUD' => 'A$',
            'CAD' => 'C$',
        ][$code] ?? $code.' ';
    }
}
