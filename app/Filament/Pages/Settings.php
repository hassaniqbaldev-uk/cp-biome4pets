<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReportResource;
use App\Mail\TestSmtpEmail;
use App\Models\ProductRule;
use App\Models\Setting;
use App\Support\HealthInsightRules;
use App\Support\ReportContent;
use App\Services\KlaviyoService;
use App\Services\OpenAiService;
use App\Support\PaidActionLimiter;
use App\Support\SmtpConfig;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

/**
 * The single Settings hub. Everything platform-level lives here as tabs:
 * OpenAI, Plans / Generation, Trigger Rules, plus the integrations folded in
 * from the former standalone "Email & Integrations" page — Klaviyo and Email
 * (SMTP). One tabbed form, one mount()/save(), statePath 'data'.
 *
 * Diagnostic test actions (Klaviyo connection / test event, SMTP test email)
 * SAVE the current form first, then run against the just-saved values — so there
 * is never a "set/save the key before you can test" dead-end.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    /**
     * Grouped under "System", listed before Report an Issue.
     */
    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.settings';

    /**
     * Sensitive page (API keys / integrations): Super Admins only. canAccess()
     * is the security gate — Filament aborts 403 on direct URL access when it
     * returns false. shouldRegisterNavigation() additionally hides the nav item.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public ?array $data = [];

    /**
     * Live Klaviyo connection-test outcome for this page session: ['ok' => bool,
     * 'account' => ?string]. Null until Test connection is clicked.
     */
    public ?array $connectionState = null;

    public function mount(): void
    {
        // Never prefill secrets (OpenAI / Klaviyo keys, SMTP password) — they stay
        // masked/blank.
        $this->form->fill([
            // The single model setting shows the resolved model (the stored value
            // when valid, else the default) so the dropdown is never blank.
            Setting::OPENAI_MODEL => OpenAiService::resolveModel(),
            Setting::OPENAI_PROMPT_DIRECTIVES => Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES, ''),
            Setting::OPENAI_DIRECTIVE_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_PHYLA => Setting::get(Setting::OPENAI_DIRECTIVE_PHYLA, ''),
            Setting::OPENAI_DIRECTIVE_SCORES => Setting::get(Setting::OPENAI_DIRECTIVE_SCORES, ''),
            Setting::SIGNS_OF_STABILITY => Setting::get(Setting::SIGNS_OF_STABILITY, ''),
            ...$this->loadPlansGeneration(),
            ...$this->loadReportText(),
            ...$this->loadKlaviyo(),
            ...$this->loadSmtp(),
            'openai_token_rates' => $this->loadTokenRates(),
            'product_rules' => $this->loadRules(),
        ]);
    }

    /**
     * The Plans / Generation tab values, loaded from settings with each field
     * pre-filled to its sensible default so a fresh install shows the defaults.
     */
    protected function loadPlansGeneration(): array
    {
        $subsRaw = Setting::get(Setting::SUBSCRIPTIONS_ENABLED);

        return [
            // PLAN_GENERATION_MODEL retired — the model is the single OPENAI_MODEL
            // (OpenAI tab). Not loaded here any more.
            Setting::PLAN_GENERATION_TEMPERATURE => Setting::get(Setting::PLAN_GENERATION_TEMPERATURE, '0.4'),
            Setting::PLAN_GENERATION_SYSTEM_PROMPT => Setting::get(Setting::PLAN_GENERATION_SYSTEM_PROMPT) ?: OpenAiService::PLAN_SYSTEM_PROMPT,
            Setting::DEFAULT_DOSE => Setting::get(Setting::DEFAULT_DOSE) ?: Setting::DEFAULT_DOSE_FALLBACK,
            // Blank ⇒ default ON (sensible default for the master switch).
            Setting::SUBSCRIPTIONS_ENABLED => blank($subsRaw) ? true : filter_var($subsRaw, FILTER_VALIDATE_BOOLEAN),
            Setting::CURRENCY => Setting::get(Setting::CURRENCY) ?: 'GBP',
            Setting::REVIEW_RATING => Setting::get(Setting::REVIEW_RATING) ?: Setting::REVIEW_RATING_DEFAULT,
            Setting::REVIEW_COUNT => Setting::get(Setting::REVIEW_COUNT) ?: Setting::REVIEW_COUNT_DEFAULT,
        ];
    }

    /**
     * The Klaviyo tab values, loaded from settings with revision / base URL
     * pre-filled to their *_DEFAULT. The API key is deliberately omitted.
     */
    /**
     * The Report Text tab values — the static every-report blocks, each pre-filled
     * to its current value or the seeded default so the editor never shows blank.
     */
    protected function loadReportText(): array
    {
        $values = [
            Setting::REPORT_ABOUT_TEXT => Setting::get(Setting::REPORT_ABOUT_TEXT) ?: Setting::REPORT_ABOUT_TEXT_DEFAULT,
            Setting::REPORT_APPROACH_TEXT => Setting::get(Setting::REPORT_APPROACH_TEXT) ?: Setting::REPORT_APPROACH_TEXT_DEFAULT,
            Setting::REPORT_SUPPORT_TEXT => Setting::get(Setting::REPORT_SUPPORT_TEXT) ?: Setting::REPORT_SUPPORT_TEXT_DEFAULT,
            Setting::DIET_REVIEW_TEXT => Setting::get(Setting::DIET_REVIEW_TEXT) ?: Setting::DIET_REVIEW_TEXT_DEFAULT,
        ];

        // The six health-insight descriptions — each pre-filled to its stored value or
        // the config default, so the editor never shows a blank box. Same resolution
        // the report itself uses (ReportContent::insightDescription).
        foreach (HealthInsightRules::scoreFields() as $field) {
            $values[HealthInsightRules::descriptionSettingKey($field)] = ReportContent::insightDescription($field);
        }

        return $values;
    }

    protected function loadKlaviyo(): array
    {
        $enabledRaw = Setting::get(Setting::KLAVIYO_ENABLED);

        return [
            // Master toggle defaults OFF until explicitly enabled.
            Setting::KLAVIYO_ENABLED => blank($enabledRaw) ? false : filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN),
            Setting::KLAVIYO_REVISION => Setting::get(Setting::KLAVIYO_REVISION) ?: Setting::KLAVIYO_REVISION_DEFAULT,
            Setting::KLAVIYO_BASE_URL => Setting::get(Setting::KLAVIYO_BASE_URL) ?: Setting::KLAVIYO_BASE_URL_DEFAULT,
        ];
    }

    /**
     * The SMTP tab values, loaded from settings with the verified SES defaults
     * pre-filled. The password is deliberately omitted (kept masked/blank).
     */
    protected function loadSmtp(): array
    {
        return [
            Setting::SMTP_ENABLED => filter_var(Setting::get(Setting::SMTP_ENABLED), FILTER_VALIDATE_BOOLEAN),
            Setting::SMTP_HOST => Setting::get(Setting::SMTP_HOST) ?: Setting::SMTP_HOST_DEFAULT,
            Setting::SMTP_PORT => Setting::get(Setting::SMTP_PORT) ?: Setting::SMTP_PORT_DEFAULT,
            Setting::SMTP_ENCRYPTION => Setting::get(Setting::SMTP_ENCRYPTION) ?: Setting::SMTP_ENCRYPTION_DEFAULT,
            Setting::SMTP_USERNAME => Setting::get(Setting::SMTP_USERNAME),
            Setting::SMTP_FROM_ADDRESS => Setting::get(Setting::SMTP_FROM_ADDRESS) ?: Setting::SMTP_FROM_ADDRESS_DEFAULT,
            Setting::SMTP_FROM_NAME => Setting::get(Setting::SMTP_FROM_NAME) ?: Setting::SMTP_FROM_NAME_DEFAULT,
        ];
    }

    /**
     * The editable token-rate rows for the OpenAI tab's Repeater, seeded from the
     * resolved rates (stored map layered over the seeded defaults) so a fresh
     * install already shows sensible, editable defaults.
     */
    protected function loadTokenRates(): array
    {
        $rows = [];
        foreach (\App\Models\AiUsageEvent::resolveRates() as $model => $rate) {
            $rows[] = [
                'model' => $model,
                'input_per_1k' => $rate['input_per_1k'],
                'output_per_1k' => $rate['output_per_1k'],
            ];
        }

        return $rows;
    }

    protected function loadRules(): array
    {
        try {
            $rules = ProductRule::query()->orderBy('id')->get();
        } catch (\Throwable) {
            // Table not migrated yet — show an empty editor rather than 500.
            return [];
        }

        return $rules
            ->map(fn (ProductRule $rule) => [
                'id' => $rule->id,
                'trigger_name' => $rule->trigger_name,
                'metric' => $rule->metric,
                'operator' => $rule->operator,
                'value' => $rule->value,
                'value2' => $rule->value2,
                'is_active' => $rule->is_active,
            ])
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        $this->openAiTab(),
                        $this->plansGenerationTab(),
                        $this->reportTextTab(),
                        $this->triggerRulesTab(),
                        $this->klaviyoTab(),
                        $this->smtpTab(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * The OpenAI settings tab. Each tab lives in its own method so adding
     * more tabs later is just another method + one entry in tabs().
     */
    protected function openAiTab(): Tabs\Tab
    {
        $hasKey = filled(Setting::get(Setting::OPENAI_API_KEY));
        $usage = \App\Models\AiUsageEvent::summary();

        // Cost estimate layer (read-only display over the tracked usage + the
        // editable rates below). All figures are ESTIMATES, never the OpenAI bill.
        $rates = \App\Models\AiUsageEvent::resolveRates();
        $cost = \App\Models\AiUsageEvent::costSummary($rates);
        $currentModel = OpenAiService::resolveModel();
        $guide = \App\Models\AiUsageEvent::reportEstimate(100, $rates, $currentModel);
        $symbol = Setting::currencySymbol();
        $currency = Setting::currencyCode();

        return Tabs\Tab::make('OpenAI')
            ->icon('heroicon-o-sparkles')
            ->schema([
                TextInput::make(Setting::OPENAI_API_KEY)
                    ->label('OpenAI API Key')
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->placeholder($hasKey ? '•••••••••••• (leave blank to keep current key)' : 'Not set')
                    ->helperText('Stored encrypted at rest. Leave blank when saving to keep the existing key.'),
                Select::make(Setting::OPENAI_MODEL)
                    ->label('OpenAI Model')
                    ->options(fn (): array => OpenAiService::modelOptions())
                    ->default(OpenAiService::resolveModel())
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->helperText('The OpenAI model used for report generation (both the report interpretation and the plan copy). Defaults to gpt-4o. Use a valid model — an invalid one will produce blank AI copy.'),
                Textarea::make(Setting::OPENAI_PROMPT_DIRECTIVES)
                    ->label('AI Prompt Directives (global)')
                    ->rows(6)
                    ->helperText('Free-text instructions appended to the end of the AI interpretation prompt. Applies to every generated field.'),
                Section::make('Per-section directives')
                    ->description('Optional, targeted steering injected next to the relevant field instructions. Blank = no effect. These layer on top of the global directive above.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Textarea::make(Setting::OPENAI_DIRECTIVE_SUMMARY)
                            ->label('Overall Summary directive')
                            ->rows(4)
                            ->helperText('Extra guidance for the owner-facing Overall Summary field only.'),
                        Textarea::make(Setting::OPENAI_DIRECTIVE_VET_SUMMARY)
                            ->label('Veterinary Summary directive')
                            ->rows(4)
                            ->helperText('Extra guidance for the clinical Veterinary Summary field only.'),
                        Textarea::make(Setting::OPENAI_DIRECTIVE_PHYLA)
                            ->label('Phylum Interpretations directive')
                            ->rows(4)
                            ->helperText('Applies to all phylum sections (Bacteroidetes, Firmicutes, Fusobacteria, Proteobacteria) plus the Diversity interpretation.'),
                        Textarea::make(Setting::OPENAI_DIRECTIVE_SCORES)
                            ->label('Health Scores directive')
                            ->rows(4)
                            ->helperText('Applies to the 6 categorical health-insight scores (gut wall, skin & allergy, behaviour & mood, gut barrier, gas & digestive, stress resilience).'),
                    ]),
                Textarea::make(Setting::SIGNS_OF_STABILITY)
                    ->label('Signs of Stability (report boilerplate)')
                    ->rows(6)
                    ->helperText('Shown in the report\'s "Your Dog\'s Personal Summary". Use {pet} where the pet\'s name should appear. Same text for all reports.'),
                Section::make('Usage')
                    ->description('Tokens used by report generation, recorded per call. Read-only.')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Placeholder::make('usage_interpretation_calls')
                            ->label('Report interpretation calls')
                            ->content(number_format($usage['by_type'][\App\Models\AiUsageEvent::TYPE_INTERPRETATION]['calls'])),
                        Placeholder::make('usage_plan_copy_calls')
                            ->label('Plan copy calls')
                            ->content(number_format($usage['by_type'][\App\Models\AiUsageEvent::TYPE_PLAN_COPY]['calls'])),
                        Placeholder::make('usage_total_tokens')
                            ->label('Total tokens (all time)')
                            ->content(number_format($usage['overall']['total_tokens'])),
                        Placeholder::make('usage_total_tokens_30d')
                            ->label('Total tokens (last 30 days)')
                            ->content(number_format($usage['last_30_days']['total_tokens'])),
                        Placeholder::make('usage_total_calls')
                            ->label('Total calls (all time)')
                            ->content(number_format($usage['overall']['calls'])),
                        Placeholder::make('usage_prompt_completion')
                            ->label('Prompt / completion tokens (all time)')
                            ->content(number_format($usage['overall']['prompt_tokens']).' / '.number_format($usage['overall']['completion_tokens'])),
                    ]),
                Section::make('Token rates (for cost estimate)')
                    ->description('Per-1,000-token rates in '.$currency.', used only to ESTIMATE cost — they are not sent to OpenAI. OpenAI prices differ per model and per direction (prompt/input vs completion/output) and change over time, so these are admin-maintained. Update them if OpenAI changes its pricing, and verify against OpenAI\'s current pricing at platform.openai.com/pricing.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('openai_token_rates')
                            ->label('Per-model rates')
                            ->helperText('One row per model. The input rate prices prompt tokens; the output rate prices completion tokens. A model used for generation but missing here is estimated at the gpt-4o rate and flagged below.')
                            ->schema([
                                TextInput::make('model')
                                    ->label('Model')
                                    ->required()
                                    ->maxLength(64),
                                TextInput::make('input_per_1k')
                                    ->label('Input rate ('.$symbol.' / 1K prompt tokens)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.00001')
                                    ->required(),
                                TextInput::make('output_per_1k')
                                    ->label('Output rate ('.$symbol.' / 1K completion tokens)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.00001')
                                    ->required(),
                            ])
                            ->columns(3)
                            ->itemLabel(fn (array $state): ?string => $state['model'] ?? 'New model')
                            ->addActionLabel('Add model rate')
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ]),
                Section::make('Cost estimate')
                    ->description('Estimated spend from the tracked usage above, priced with the rates you set. An ESTIMATE, not your OpenAI invoice.')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Placeholder::make('cost_estimate_disclaimer')
                            ->label('')
                            ->columnSpanFull()
                            ->content(new HtmlString(
                                '<p style="color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin:0;">'
                                .'<strong>Estimate only — not your OpenAI bill.</strong> These figures are calculated from the tokens we track and the rates set above, so they are approximate. '
                                .'Your actual charges are in OpenAI\'s billing dashboard (platform.openai.com/usage) and will differ.'
                                .'</p>',
                            )),
                        Placeholder::make('cost_all_time')
                            ->label('Estimated cost (all time)')
                            ->content($this->formatMoney($cost['all_time'], $symbol)),
                        Placeholder::make('cost_last_30_days')
                            ->label('Estimated cost (last 30 days)')
                            ->content($this->formatMoney($cost['last_30_days'], $symbol)),
                        Placeholder::make('cost_missing_rates')
                            ->label('Models without a set rate')
                            ->columnSpanFull()
                            ->visible(fn (): bool => ! empty($cost['missing_rate_models']))
                            ->content(fn (): HtmlString => new HtmlString(
                                '<span style="color:#b45309;">Rate not set for: <strong>'
                                .e(implode(', ', $cost['missing_rate_models']))
                                .'</strong>. These were estimated at the gpt-4o rate — add a row for them under “Token rates” for an accurate figure.</span>',
                            )),
                        Placeholder::make('cost_100_reports')
                            ->label('Guide: cost of ~100 reports')
                            ->columnSpanFull()
                            ->content(new HtmlString($this->reportGuideHtml($guide, $symbol))),
                    ]),
            ]);
    }

    /**
     * Render the "cost of ~100 reports" guide transparently: the per-report token
     * basis (from tracked averages or the documented baseline), the ×100 total,
     * and the priced estimate at the current model's rate — plus the regeneration
     * caveat. All read-only; the figure is clearly an estimate.
     *
     * @param  array{reports:int, model:string, rate_configured:bool, source:string, prompt_per_report:float, completion_per_report:float, tokens_per_report:float, total_tokens:float, interpretation_tokens:float, cost:float}  $guide
     */
    protected function reportGuideHtml(array $guide, string $symbol): string
    {
        $reports = $guide['reports'];
        $perReport = (int) round($guide['tokens_per_report']);
        $prompt = (int) round($guide['prompt_per_report']);
        $completion = (int) round($guide['completion_per_report']);
        $totalTokens = (int) round($guide['total_tokens']);
        $totalK = round($totalTokens / 1000).'K';
        $cost = $this->formatMoney($guide['cost'], $symbol);
        $model = e($guide['model']);
        $regen = (int) round($guide['interpretation_tokens']);

        $sourceNote = $guide['source'] === 'tracked'
            ? 'Averaged from real tracked usage.'
            : 'Baseline estimate (used until enough real usage is tracked).';

        $rateNote = $guide['rate_configured']
            ? ''
            : ' <span style="color:#b45309;">(no rate set for '.$model.' — estimated at the gpt-4o rate)</span>';

        return '<div style="color:#374151;line-height:1.6;">'
            .'<div>~'.number_format($perReport).' tokens/report ('
            .number_format($prompt).' prompt + '.number_format($completion).' completion) '
            .'× '.number_format($reports).' reports = ~'.number_format($totalTokens).' tokens (~'.$totalK.')</div>'
            .'<div style="font-size:18px;font-weight:700;margin-top:4px;">≈ '.e($cost).' at '.$model.' rates'.$rateNote.'</div>'
            .'<div style="color:#6b7280;font-size:12px;margin-top:6px;">'.$sourceNote
            .' Estimate is per generation event — each regeneration is another interpretation call (~'.number_format($regen).' tokens) and adds cost.</div>'
            .'</div>';
    }

    /**
     * Format an estimated money amount for display. Scales the decimal places to
     * the magnitude so tiny per-token costs stay legible (e.g. £0.0042) while
     * larger totals read cleanly (e.g. £5.20).
     */
    protected function formatMoney(float $amount, string $symbol): string
    {
        $decimals = match (true) {
            $amount >= 1 => 2,
            $amount >= 0.01 => 4,
            default => 6,
        };

        return $symbol.number_format($amount, $decimals);
    }

    /**
     * The Plans / Generation tab — plan-copy generation config plus the
     * platform-level plan/subscription defaults. Each field maps to a Setting
     * key constant; every consumer falls back to its own default when blank.
     */
    protected function plansGenerationTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Plans / Generation')
            ->icon('heroicon-o-clipboard-document-list')
            ->schema([
                // The model moved to the OpenAI tab (Setting::OPENAI_MODEL) — it is
                // now the single model for BOTH the interpretation and plan-copy
                // calls, so there is no separate plan model here any more.
                TextInput::make(Setting::PLAN_GENERATION_TEMPERATURE)
                    ->label('Plan Generation Temperature')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(1)
                    ->step(0.1)
                    ->default(0.4)
                    ->helperText('Creativity of the plan copy, 0–1. Blank falls back to 0.4.'),
                Textarea::make(Setting::PLAN_GENERATION_SYSTEM_PROMPT)
                    ->label('Plan Generation System Prompt')
                    ->rows(10)
                    ->default(OpenAiService::PLAN_SYSTEM_PROMPT)
                    ->helperText('System prompt steering the plan copy-writer. Blank reverts to the built-in default prompt.'),
                TextInput::make(Setting::DEFAULT_DOSE)
                    ->label('Default Dose Text')
                    ->maxLength(255)
                    ->default(Setting::DEFAULT_DOSE_FALLBACK)
                    ->helperText('Pre-filled into a new plan product\'s Dose field. Blank falls back to "'.Setting::DEFAULT_DOSE_FALLBACK.'".'),
                Toggle::make(Setting::SUBSCRIPTIONS_ENABLED)
                    ->label('Global Subscriptions')
                    ->default(true)
                    ->helperText('Master switch. When OFF, the public report hides the subscribe panel and the bottom subscribe reminder, regardless of per-plan settings.'),
                TextInput::make(Setting::CURRENCY)
                    ->label('Currency')
                    ->maxLength(8)
                    ->default('GBP')
                    ->helperText('Display currency code. Stored for display use; blank falls back to "GBP".'),
                Section::make('Reviews')
                    ->description('Social-proof figures shown on the subscribe page. Blank falls back to the defaults.')
                    ->schema([
                        TextInput::make(Setting::REVIEW_RATING)
                            ->label('Review rating shown on subscribe page')
                            ->maxLength(16)
                            ->default(Setting::REVIEW_RATING_DEFAULT)
                            ->helperText('e.g. "4.9". Blank falls back to "'.Setting::REVIEW_RATING_DEFAULT.'".'),
                        TextInput::make(Setting::REVIEW_COUNT)
                            ->label('Review count shown on subscribe page')
                            ->maxLength(32)
                            ->default(Setting::REVIEW_COUNT_DEFAULT)
                            ->helperText('e.g. "1,000+". Blank falls back to "'.Setting::REVIEW_COUNT_DEFAULT.'".'),
                    ]),
            ]);
    }

    /**
     * The Report Text tab — the static, every-report copy in the report's "Help
     * and Contacts" section. One edit here updates BOTH the web report and the
     * PDF (they read these via ReportContent), so the two can never drift. Blank
     * reverts to the original built-in default.
     */
    protected function reportTextTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Report Text')
            ->icon('heroicon-o-document-text')
            ->schema([
                Placeholder::make('report_text_explainer')
                    ->label('')
                    ->content(new HtmlString('<p style="color:#6b7280;">This copy appears on <strong>every</strong> report, identically on the web report and the downloadable PDF. Editing it here updates both. Leave a field blank to restore its original default text.</p>')),
                Textarea::make(Setting::REPORT_ABOUT_TEXT)
                    ->label('About This Report (method + disclaimer)')
                    ->rows(8)
                    ->default(Setting::REPORT_ABOUT_TEXT_DEFAULT)
                    ->helperText('The "About This Report" paragraph. Includes the 16S rRNA method description AND the compliance disclaimer ("not intended to diagnose disease… consult your veterinarian"). Plain text; line breaks are preserved.'),
                Textarea::make(Setting::REPORT_APPROACH_TEXT)
                    ->label('Our Approach (one bullet per line)')
                    ->rows(5)
                    ->default(Setting::REPORT_APPROACH_TEXT_DEFAULT)
                    ->helperText('Shown as a bulleted list under "Our Approach". Enter one bullet per line; blank lines are ignored.'),
                Textarea::make(Setting::REPORT_SUPPORT_TEXT)
                    ->label('Support & Next Steps')
                    ->rows(5)
                    ->default(Setting::REPORT_SUPPORT_TEXT_DEFAULT)
                    ->helperText('The "Support & Next Steps" contact block. Plain text; line breaks are preserved.'),

                Textarea::make(Setting::DIET_REVIEW_TEXT)
                    ->label('Nutritionist diet-review statement')
                    ->rows(5)
                    ->default(Setting::DIET_REVIEW_TEXT_DEFAULT)
                    ->helperText('Shown on a kibble-fed report whose microbiome is Imbalanced or Imbalanced & Depleted, recommending a nutritionist diet review. This is the STATEMENT text only — the "Book a microbiome diet review" button/link and the 10% loyalty note are added automatically and are not editable here. Blank restores the default wording.'),

                Section::make('Health Insight Descriptions')
                    ->description('The explanatory paragraph shown under each of the six "Microbiome-Driven Health Insights" cards, on both the web report and the PDF. These describe what each insight measures; they do NOT change any score, band or result. Leave a field blank to restore its original wording.')
                    ->collapsible()
                    ->schema(static::healthInsightDescriptionFields()),
            ]);
    }

    /**
     * One textarea per health insight, built by looping the rules config so the fields,
     * their labels and their defaults all come from that single source — add an insight
     * and it gets an editable description automatically, with no drift.
     */
    protected static function healthInsightDescriptionFields(): array
    {
        $fields = [];

        foreach (HealthInsightRules::HEALTH_INSIGHT_RULES as $field => $cfg) {
            // Naming the driver bacteria matters: two insights are both driven by
            // Firmicutes, and "Gut Wall Integrity" (Blautia) vs "Metabolic Health"
            // (Verrucomicrobia) are easy to mix up from the titles alone.
            $helper = 'Driven by '.$cfg['driver'].'. Leave blank to restore the default wording.';

            // The one insight with no new copy from the client — flag it so it is
            // obvious which is still on the original wording.
            if ($field === 'score_gut_barrier') {
                $helper = 'Driven by '.$cfg['driver'].'. NOTE: this one still uses the ORIGINAL wording — the other five were updated with the new scientific descriptions, so you may want to rewrite this one. Leave blank to restore the default wording.';
            }

            $fields[] = Textarea::make(HealthInsightRules::descriptionSettingKey($field))
                ->label($cfg['title'])
                ->rows(4)
                ->default($cfg['desc'])
                ->helperText($helper);
        }

        return $fields;
    }

    /**
     * The Trigger Rules tab — a Repeater giving full add/edit/deactivate/remove
     * over the configurable product_rules. Adding tabs later stays trivial.
     */
    protected function triggerRulesTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Trigger Rules')
            ->icon('heroicon-o-adjustments-horizontal')
            ->schema([
                // Read-only (Phase 1): how the fired triggers above resolve to ONE
                // plan, in precedence order. Mirrors ReportResource::recommendPlanId().
                Placeholder::make('plan_recommendation_explainer')
                    ->label('How plans are recommended')
                    ->columnSpanFull()
                    ->content(fn (): HtmlString => ReportResource::planRecommendationExplainerHtml()),

                Repeater::make('product_rules')
                    ->label('Product Trigger Rules')
                    ->helperText('Each rule fires its trigger when the metric meets the condition. Rules sharing a trigger name are OR-ed together.')
                    ->schema([
                        TextInput::make('trigger_name')
                            ->label('Trigger Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('metric')
                            ->label('Metric')
                            ->options(ProductRule::METRICS)
                            ->required()
                            ->native(false),
                        Select::make('operator')
                            ->label('Operator')
                            ->options(ProductRule::OPERATORS)
                            ->required()
                            ->live()
                            ->native(false),
                        TextInput::make('value')
                            ->label('Value')
                            ->numeric()
                            ->required(),
                        TextInput::make('value2')
                            ->label('Value 2')
                            ->numeric()
                            // 'between'/'outside' need a second bound.
                            ->required(fn (Get $get): bool => in_array($get('operator'), ['between', 'outside'], true))
                            ->visible(fn (Get $get): bool => in_array($get('operator'), ['between', 'outside'], true)),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                        TextInput::make('id')->hidden(),
                    ])
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => $state['trigger_name'] ?? 'New rule')
                    ->addActionLabel('Add rule')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The Klaviyo tab — server-side Events API config. The private key reuses
     * the OpenAI key pattern: masked password field, never prefilled, and only
     * overwritten on save when a new value is entered. The diagnostics save the
     * form first, so you can test the key you just typed without saving by hand.
     */
    protected function klaviyoTab(): Tabs\Tab
    {
        $hasKey = filled(Setting::get(Setting::KLAVIYO_API_KEY));

        return Tabs\Tab::make('Klaviyo')
            ->icon('heroicon-o-bolt')
            ->schema([
                TextInput::make(Setting::KLAVIYO_API_KEY)
                    ->label('Private API Key')
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->placeholder($hasKey ? '•••••••••••• (leave blank to keep current key)' : 'Not set')
                    ->helperText('Stored encrypted at rest. Sent as "Authorization: Klaviyo-API-Key <key>". Leave blank when saving to keep the existing key.'),
                Toggle::make(Setting::KLAVIYO_ENABLED)
                    ->label('Enabled')
                    ->default(false)
                    ->helperText('Master switch for the Klaviyo integration. When OFF, no events are sent (default OFF).'),
                TextInput::make(Setting::KLAVIYO_REVISION)
                    ->label('API Revision')
                    ->maxLength(32)
                    ->default(Setting::KLAVIYO_REVISION_DEFAULT)
                    ->helperText('Sent as the "revision" header. Blank falls back to '.Setting::KLAVIYO_REVISION_DEFAULT.'.'),
                TextInput::make(Setting::KLAVIYO_BASE_URL)
                    ->label('Base URL')
                    ->maxLength(255)
                    ->default(Setting::KLAVIYO_BASE_URL_DEFAULT)
                    ->helperText('Klaviyo API base URL. Blank falls back to '.Setting::KLAVIYO_BASE_URL_DEFAULT.'.'),

                // ── Diagnostics ────────────────────────────────────────────
                Placeholder::make('klaviyo_connection_status')
                    ->label('Connection status')
                    ->content(fn (): HtmlString => $this->connectionStatusContent())
                    ->helperText('Testing saves your settings first, then checks the saved key — no need to Save separately.'),

                Actions::make([
                    Action::make('test_connection')
                        ->label('Test connection')
                        ->icon('heroicon-o-signal')
                        ->color('gray')
                        ->action(function (): void {
                            // Save the current form first so the test runs against
                            // exactly what was typed — no "save before testing" trap.
                            $this->save();
                            $this->runTestConnection();
                        }),

                    Action::make('send_test_event')
                        ->label('Send test event')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->modalHeading('Send a sample "Report Published" event')
                        ->modalDescription('Fires a dummy report_published event so you can confirm it lands in Klaviyo before any template exists. Your settings are saved first.')
                        ->modalSubmitActionLabel('Send test event')
                        ->form([
                            TextInput::make('test_email')
                                ->label('Send to email')
                                ->email()
                                ->required()
                                ->default(auth()->user()?->email)
                                ->helperText('The dummy event is attached to this Klaviyo profile.'),
                        ])
                        ->action(function (array $data): void {
                            // L2: Klaviyo API call — cap per admin.
                            if (PaidActionLimiter::exceeded('klaviyo-test', 5)) {
                                return;
                            }
                            $this->save();
                            $this->runSendTestEvent($data['test_email'] ?? null);
                        }),
                ]),

                Placeholder::make('klaviyo_last_result')
                    ->label('Last Klaviyo result')
                    ->content(fn (): HtmlString => $this->lastResultContent()),
            ]);
    }

    /**
     * Run KlaviyoService::testConnection() against the SAVED settings (the test
     * action saves the form first), update the live status indicator, persist the
     * result, and notify.
     */
    public function runTestConnection(): void
    {
        $result = app(KlaviyoService::class)->testConnection();

        $this->connectionState = ['ok' => $result['ok'], 'account' => $result['account_name'] ?? null];

        $this->recordResult(
            'Test connection',
            $result['ok'],
            $result['ok']
                ? 'Connected'.($result['account_name'] ? ' to '.$result['account_name'] : '')
                : $result['message'],
        );

        Notification::make()
            ->title($result['ok'] ? 'Klaviyo connected' : 'Klaviyo connection failed')
            ->body($result['ok']
                ? ($result['account_name'] ? 'Account: '.$result['account_name'] : 'Connection succeeded.')
                : $result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * Fire a sample report_published event to the given email, persist the
     * result, and notify. unique_id is randomised per click so repeated test
     * sends each appear distinctly in the Klaviyo activity feed.
     */
    public function runSendTestEvent(?string $email): void
    {
        $email = trim((string) $email);

        if (blank($email)) {
            Notification::make()->title('Enter a test email address')->warning()->send();

            return;
        }

        $result = app(KlaviyoService::class)->sendEvent('report_published', $email, [
            'report_id' => 'test-'.uniqid(),
            'pet_name' => 'Test Pet',
            'report_url' => url('/'),
            'report_date' => now()->toDateString(),
            'client_name' => 'Test Client',
        ]);

        $this->recordResult(
            'Send test event',
            $result['ok'],
            $result['ok'] ? 'Test event sent to '.$email : $result['message'],
        );

        Notification::make()
            ->title($result['ok'] ? 'Test event sent' : 'Test event failed')
            ->body($result['ok']
                ? 'Test event sent — check your Klaviyo dashboard (Profiles → '.$email.' → Activity).'
                : $result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * Persist the most recent Klaviyo diagnostic outcome as JSON so it survives
     * reloads.
     */
    protected function recordResult(string $action, bool $ok, string $message): void
    {
        Setting::set(Setting::KLAVIYO_LAST_RESULT, json_encode([
            'at' => now()->toDateTimeString(),
            'action' => $action,
            'ok' => $ok,
            'message' => $message,
        ]));
    }

    /**
     * The live connection-status indicator. Driven by the last Test connection
     * in this session; falls back to key presence on a fresh load.
     */
    protected function connectionStatusContent(): HtmlString
    {
        if (blank(Setting::get(Setting::KLAVIYO_API_KEY))) {
            return $this->statusBadge('Key missing', 'gray');
        }

        if ($this->connectionState === null) {
            return $this->statusBadge('Unknown — click "Test connection"', 'gray');
        }

        if ($this->connectionState['ok']) {
            $account = $this->connectionState['account']
                ? '<span style="margin-left:10px;color:#374151;font-weight:600;">'.e($this->connectionState['account']).'</span>'
                : '';

            return new HtmlString($this->statusBadge('Connected', 'green')->toHtml().$account);
        }

        return $this->statusBadge('Not connected', 'red');
    }

    /**
     * The persisted Klaviyo "last result" line (timestamp + ok/fail + message).
     */
    protected function lastResultContent(): HtmlString
    {
        $raw = Setting::get(Setting::KLAVIYO_LAST_RESULT);

        if (blank($raw)) {
            return new HtmlString('<span style="color:#6b7280;">No Klaviyo events run yet.</span>');
        }

        $r = json_decode((string) $raw, true) ?: [];
        $ok = ! empty($r['ok']);

        return new HtmlString(
            $this->statusBadge($ok ? 'OK' : 'FAIL', $ok ? 'green' : 'red')->toHtml()
            .' <strong>'.e($r['action'] ?? '').'</strong>'
            .' <span style="color:#6b7280;">· '.e($r['at'] ?? '').'</span>'
            .'<br><span style="color:#374151;">'.e($r['message'] ?? '').'</span>',
        );
    }

    /**
     * Small coloured pill used by the status / last-result indicators.
     */
    private function statusBadge(string $text, string $color): HtmlString
    {
        $map = ['green' => '#16a34a', 'red' => '#dc2626', 'gray' => '#6b7280'];
        $bg = $map[$color] ?? '#6b7280';

        return new HtmlString(
            '<span style="display:inline-block;padding:2px 10px;border-radius:9999px;font-weight:600;font-size:12px;color:#fff;background:'.$bg.';">'
            .e($text).'</span>',
        );
    }

    /**
     * Email (SMTP) tab — outbound email via Amazon SES SMTP (587 + STARTTLS).
     * The password reuses the Klaviyo key pattern: masked field, never prefilled,
     * encrypted at rest, only overwritten when a new value is entered. The test
     * action saves the form first, then sends with the saved settings.
     */
    protected function smtpTab(): Tabs\Tab
    {
        $hasPassword = filled(Setting::get(Setting::SMTP_PASSWORD));

        return Tabs\Tab::make('Email (SMTP)')
            ->icon('heroicon-o-envelope')
            ->schema([
                Toggle::make(Setting::SMTP_ENABLED)
                    ->label('Enabled')
                    ->default(false)
                    ->helperText('Master switch for outbound SMTP. When OFF, the app uses its default mailer (default OFF).'),
                TextInput::make(Setting::SMTP_HOST)
                    ->label('SMTP Host')
                    ->maxLength(255)
                    ->default(Setting::SMTP_HOST_DEFAULT)
                    ->helperText('Amazon SES SMTP endpoint, e.g. '.Setting::SMTP_HOST_DEFAULT.'.'),
                TextInput::make(Setting::SMTP_PORT)
                    ->label('SMTP Port')
                    ->numeric()
                    ->default(Setting::SMTP_PORT_DEFAULT)
                    ->helperText('587 for STARTTLS (recommended), 465 for implicit TLS.'),
                Select::make(Setting::SMTP_ENCRYPTION)
                    ->label('Encryption')
                    ->options(['tls' => 'STARTTLS (TLS)', 'ssl' => 'SSL (implicit)'])
                    ->default(Setting::SMTP_ENCRYPTION_DEFAULT)
                    ->native(false)
                    ->helperText('Use STARTTLS with port 587.'),
                TextInput::make(Setting::SMTP_USERNAME)
                    ->label('SMTP Username')
                    ->maxLength(255)
                    ->autocomplete(false)
                    ->helperText('Your SES SMTP username (an IAM SMTP credential, not your AWS access key).'),
                TextInput::make(Setting::SMTP_PASSWORD)
                    ->label('SMTP Password')
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->placeholder($hasPassword ? '•••••••••••• (leave blank to keep current password)' : 'Not set')
                    ->helperText('Stored encrypted at rest. Leave blank when saving to keep the existing password.'),
                TextInput::make(Setting::SMTP_FROM_ADDRESS)
                    ->label('From Address')
                    ->email()
                    ->default(Setting::SMTP_FROM_ADDRESS_DEFAULT)
                    ->helperText('Must be a verified SES identity (address or domain).'),
                TextInput::make(Setting::SMTP_FROM_NAME)
                    ->label('From Name')
                    ->maxLength(255)
                    ->default(Setting::SMTP_FROM_NAME_DEFAULT),

                // ── Diagnostics ────────────────────────────────────────────
                Placeholder::make('smtp_diagnostics_hint')
                    ->label('Test email')
                    ->content(new HtmlString('<span style="color:#6b7280;">Sends a one-off email so you can confirm delivery.</span>'))
                    ->helperText('Sending saves your settings first, then uses them — no need to Save separately.'),

                Actions::make([
                    Action::make('send_test_email')
                        ->label('Send test email')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->modalHeading('Send a test email')
                        ->modalDescription('Sends a simple test email using your SMTP settings (saved first). The only platform email today is the password reset.')
                        ->modalSubmitActionLabel('Send test email')
                        ->form([
                            TextInput::make('test_email')
                                ->label('Send to email')
                                ->email()
                                ->required()
                                ->default(auth()->user()?->email)
                                ->helperText('In the SES sandbox this must be a verified address.'),
                        ])
                        ->action(function (array $data): void {
                            // L2: SMTP send — cap per admin.
                            if (PaidActionLimiter::exceeded('smtp-test', 5)) {
                                return;
                            }
                            $this->save();
                            $this->runSendTestEmail($data['test_email'] ?? null);
                        }),
                ]),

                Placeholder::make('smtp_last_result')
                    ->label('Last SMTP result')
                    ->content(fn (): HtmlString => $this->smtpLastResultContent()),
            ]);
    }

    /** Why a test email can't be sent yet, or null when SMTP is ready. */
    protected function smtpNotReadyReason(): ?string
    {
        if (! SmtpConfig::isEnabled()) {
            return 'Enable SMTP (toggle on) first.';
        }

        if (! SmtpConfig::isConfigured()) {
            return 'Add host, username, password and a from-address first.';
        }

        return null;
    }

    /**
     * Apply the saved SMTP settings and send a test email, reporting success or
     * the transport error clearly. Guards on readiness so it never silently sends
     * via the fallback mailer, and never 500s — transport exceptions are caught.
     */
    public function runSendTestEmail(?string $email): void
    {
        $email = trim((string) $email);

        if (blank($email)) {
            Notification::make()->title('Enter a test email address')->warning()->send();

            return;
        }

        if (! SmtpConfig::isConfigured()) {
            Notification::make()
                ->title('SMTP not ready')
                ->body($this->smtpNotReadyReason() ?? 'Complete the SMTP settings first.')
                ->warning()
                ->send();

            return;
        }

        SmtpConfig::apply();

        try {
            Mail::to($email)->send(new TestSmtpEmail);

            $this->recordSmtpResult('Send test email', true, 'Test email sent to '.$email);

            Notification::make()
                ->title('Test email sent')
                ->body('Sent to '.$email.'. Check the inbox (and spam). In the SES sandbox only verified addresses receive mail.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->recordSmtpResult('Send test email', false, $e->getMessage());

            Notification::make()
                ->title('Test email failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Persist the latest SMTP diagnostic outcome as JSON (survives reloads). */
    protected function recordSmtpResult(string $action, bool $ok, string $message): void
    {
        Setting::set(Setting::SMTP_LAST_RESULT, json_encode([
            'at' => now()->toDateTimeString(),
            'action' => $action,
            'ok' => $ok,
            'message' => $message,
        ]));
    }

    /** The persisted SMTP "last result" line (timestamp + ok/fail + message). */
    protected function smtpLastResultContent(): HtmlString
    {
        $raw = Setting::get(Setting::SMTP_LAST_RESULT);

        if (blank($raw)) {
            return new HtmlString('<span style="color:#6b7280;">No test emails sent yet.</span>');
        }

        $r = json_decode((string) $raw, true) ?: [];
        $ok = ! empty($r['ok']);

        return new HtmlString(
            $this->statusBadge($ok ? 'OK' : 'FAIL', $ok ? 'green' : 'red')->toHtml()
            .' <strong>'.e($r['action'] ?? '').'</strong>'
            .' <span style="color:#6b7280;">· '.e($r['at'] ?? '').'</span>'
            .'<br><span style="color:#374151;">'.e($r['message'] ?? '').'</span>',
        );
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Only overwrite the OpenAI key if a new value was actually entered,
        // so saving other settings never wipes the stored key.
        if (filled($data[Setting::OPENAI_API_KEY] ?? null)) {
            Setting::setEncrypted(Setting::OPENAI_API_KEY, $data[Setting::OPENAI_API_KEY]);
        }

        // The single model. The dropdown only offers known-good values, so this is
        // already safe; resolveModel() guards anyway, so a bad value can never break
        // generation (it falls back to the config default at read time).
        Setting::set(Setting::OPENAI_MODEL, $data[Setting::OPENAI_MODEL] ?? '');
        Setting::set(Setting::OPENAI_PROMPT_DIRECTIVES, $data[Setting::OPENAI_PROMPT_DIRECTIVES] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_SUMMARY, $data[Setting::OPENAI_DIRECTIVE_SUMMARY] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, $data[Setting::OPENAI_DIRECTIVE_VET_SUMMARY] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_PHYLA, $data[Setting::OPENAI_DIRECTIVE_PHYLA] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_SCORES, $data[Setting::OPENAI_DIRECTIVE_SCORES] ?? '');
        Setting::set(Setting::SIGNS_OF_STABILITY, $data[Setting::SIGNS_OF_STABILITY] ?? '');

        // Plans / Generation — store verbatim; blanks are tolerated because
        // every consumer falls back to its own default when the value is blank.
        // PLAN_GENERATION_MODEL retired — the model is saved once as OPENAI_MODEL
        // (OpenAI block above). Nothing writes the old key any more.
        Setting::set(Setting::PLAN_GENERATION_TEMPERATURE, $data[Setting::PLAN_GENERATION_TEMPERATURE] ?? '');
        Setting::set(Setting::PLAN_GENERATION_SYSTEM_PROMPT, $data[Setting::PLAN_GENERATION_SYSTEM_PROMPT] ?? '');
        Setting::set(Setting::DEFAULT_DOSE, $data[Setting::DEFAULT_DOSE] ?? '');
        Setting::set(Setting::SUBSCRIPTIONS_ENABLED, ! empty($data[Setting::SUBSCRIPTIONS_ENABLED]) ? '1' : '0');
        Setting::set(Setting::CURRENCY, $data[Setting::CURRENCY] ?? '');
        Setting::set(Setting::REVIEW_RATING, $data[Setting::REVIEW_RATING] ?? '');
        Setting::set(Setting::REVIEW_COUNT, $data[Setting::REVIEW_COUNT] ?? '');

        // Editable per-model token rates (OpenAI tab) → stored as a JSON map that
        // drives the cost estimate. Never sent to OpenAI; blank/invalid rows drop.
        $this->saveTokenRates($data['openai_token_rates'] ?? []);

        // Report Text — store verbatim; blank reverts to the built-in default at
        // render time (ReportContent::reportText), so wiping a field is safe.
        Setting::set(Setting::REPORT_ABOUT_TEXT, $data[Setting::REPORT_ABOUT_TEXT] ?? '');
        Setting::set(Setting::REPORT_APPROACH_TEXT, $data[Setting::REPORT_APPROACH_TEXT] ?? '');
        Setting::set(Setting::REPORT_SUPPORT_TEXT, $data[Setting::REPORT_SUPPORT_TEXT] ?? '');
        Setting::set(Setting::DIET_REVIEW_TEXT, $data[Setting::DIET_REVIEW_TEXT] ?? '');

        // The six health-insight descriptions — same contract: stored verbatim, and a
        // blank field reverts to the config default at render time
        // (ReportContent::insightDescription), so wiping one is safe.
        foreach (HealthInsightRules::scoreFields() as $field) {
            $key = HealthInsightRules::descriptionSettingKey($field);
            Setting::set($key, $data[$key] ?? '');
        }

        // ── Klaviyo ──────────────────────────────────────────────────────────
        // Only overwrite the key when a new value was entered (same guard as the
        // OpenAI key) so saving never wipes the stored secret.
        if (filled($data[Setting::KLAVIYO_API_KEY] ?? null)) {
            Setting::setEncrypted(Setting::KLAVIYO_API_KEY, $data[Setting::KLAVIYO_API_KEY]);
        }

        Setting::set(Setting::KLAVIYO_ENABLED, ! empty($data[Setting::KLAVIYO_ENABLED]) ? '1' : '0');
        Setting::set(Setting::KLAVIYO_REVISION, $data[Setting::KLAVIYO_REVISION] ?? '');
        Setting::set(Setting::KLAVIYO_BASE_URL, $data[Setting::KLAVIYO_BASE_URL] ?? '');

        // ── SMTP ─────────────────────────────────────────────────────────────
        // Same guard for the password as the keys above.
        if (filled($data[Setting::SMTP_PASSWORD] ?? null)) {
            Setting::setEncrypted(Setting::SMTP_PASSWORD, $data[Setting::SMTP_PASSWORD]);
        }

        Setting::set(Setting::SMTP_ENABLED, ! empty($data[Setting::SMTP_ENABLED]) ? '1' : '0');
        Setting::set(Setting::SMTP_HOST, $data[Setting::SMTP_HOST] ?? '');
        Setting::set(Setting::SMTP_PORT, $data[Setting::SMTP_PORT] ?? '');
        Setting::set(Setting::SMTP_ENCRYPTION, $data[Setting::SMTP_ENCRYPTION] ?? '');
        Setting::set(Setting::SMTP_USERNAME, $data[Setting::SMTP_USERNAME] ?? '');
        Setting::set(Setting::SMTP_FROM_ADDRESS, $data[Setting::SMTP_FROM_ADDRESS] ?? '');
        Setting::set(Setting::SMTP_FROM_NAME, $data[Setting::SMTP_FROM_NAME] ?? '');

        $this->saveRules($data['product_rules'] ?? []);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();

        // Reset the form: clear the secret fields, reload persisted values.
        $this->form->fill([
            // The single model setting shows the resolved model (the stored value
            // when valid, else the default) so the dropdown is never blank.
            Setting::OPENAI_MODEL => OpenAiService::resolveModel(),
            Setting::OPENAI_PROMPT_DIRECTIVES => Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES, ''),
            Setting::OPENAI_DIRECTIVE_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_PHYLA => Setting::get(Setting::OPENAI_DIRECTIVE_PHYLA, ''),
            Setting::OPENAI_DIRECTIVE_SCORES => Setting::get(Setting::OPENAI_DIRECTIVE_SCORES, ''),
            Setting::SIGNS_OF_STABILITY => Setting::get(Setting::SIGNS_OF_STABILITY, ''),
            ...$this->loadPlansGeneration(),
            ...$this->loadReportText(),
            ...$this->loadKlaviyo(),
            ...$this->loadSmtp(),
            'openai_token_rates' => $this->loadTokenRates(),
            'product_rules' => $this->loadRules(),
        ]);
    }

    /**
     * Encode the token-rate rows to the JSON map stored under
     * Setting::OPENAI_TOKEN_RATES. Rows without a model name or with a
     * non-numeric rate are dropped, so a malformed row can never poison the
     * estimate (resolveRates() falls back to the seeded defaults for anything
     * absent). A later-model row wins over an earlier duplicate.
     */
    protected function saveTokenRates(array $rows): void
    {
        $map = [];

        foreach ($rows as $row) {
            $model = trim((string) ($row['model'] ?? ''));
            $input = $row['input_per_1k'] ?? null;
            $output = $row['output_per_1k'] ?? null;

            if ($model === '' || ! is_numeric($input) || ! is_numeric($output)) {
                continue;
            }

            $map[$model] = [
                'input_per_1k' => (float) $input,
                'output_per_1k' => (float) $output,
            ];
        }

        Setting::set(Setting::OPENAI_TOKEN_RATES, json_encode($map));
    }

    /**
     * Sync the repeater rows back to product_rules: update existing rows,
     * create new ones, and delete rows the admin removed.
     */
    protected function saveRules(array $rows): void
    {
        $keptIds = [];

        foreach ($rows as $row) {
            $payload = [
                'trigger_name' => $row['trigger_name'],
                'metric' => $row['metric'],
                'operator' => $row['operator'],
                'value' => $row['value'],
                'value2' => in_array($row['operator'], ['between', 'outside'], true) ? ($row['value2'] ?? null) : null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            if (! empty($row['id']) && $rule = ProductRule::find($row['id'])) {
                $rule->update($payload);
                $keptIds[] = $rule->id;
            } else {
                $keptIds[] = ProductRule::create($payload)->id;
            }
        }

        ProductRule::query()->whereNotIn('id', $keptIds ?: [0])->delete();
    }
}
