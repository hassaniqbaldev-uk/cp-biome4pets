<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ReportResource;
use App\Mail\TestSmtpEmail;
use App\Models\ProductRule;
use App\Models\Setting;
use App\Services\KlaviyoService;
use App\Services\OpenAiService;
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
            Setting::OPENAI_PROMPT_DIRECTIVES => Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES, ''),
            Setting::OPENAI_DIRECTIVE_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_PHYLA => Setting::get(Setting::OPENAI_DIRECTIVE_PHYLA, ''),
            Setting::OPENAI_DIRECTIVE_SCORES => Setting::get(Setting::OPENAI_DIRECTIVE_SCORES, ''),
            Setting::SIGNS_OF_STABILITY => Setting::get(Setting::SIGNS_OF_STABILITY, ''),
            ...$this->loadPlansGeneration(),
            ...$this->loadKlaviyo(),
            ...$this->loadSmtp(),
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
            Setting::PLAN_GENERATION_MODEL => Setting::get(Setting::PLAN_GENERATION_MODEL) ?: config('services.openai.model'),
            Setting::PLAN_GENERATION_TEMPERATURE => Setting::get(Setting::PLAN_GENERATION_TEMPERATURE, '0.4'),
            Setting::PLAN_GENERATION_SYSTEM_PROMPT => Setting::get(Setting::PLAN_GENERATION_SYSTEM_PROMPT) ?: OpenAiService::PLAN_SYSTEM_PROMPT,
            Setting::DEFAULT_DOSE => Setting::get(Setting::DEFAULT_DOSE) ?: Setting::DEFAULT_DOSE_FALLBACK,
            // Blank ⇒ default ON (sensible default for the master switch).
            Setting::SUBSCRIPTIONS_ENABLED => blank($subsRaw) ? true : filter_var($subsRaw, FILTER_VALIDATE_BOOLEAN),
            Setting::CURRENCY => Setting::get(Setting::CURRENCY) ?: 'GBP',
        ];
    }

    /**
     * The Klaviyo tab values, loaded from settings with revision / base URL
     * pre-filled to their *_DEFAULT. The API key is deliberately omitted.
     */
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
            ]);
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
                TextInput::make(Setting::PLAN_GENERATION_MODEL)
                    ->label('Plan Generation Model')
                    ->default(config('services.openai.model'))
                    ->maxLength(255)
                    ->helperText('OpenAI model used to write plan copy. Blank falls back to the default ('.config('services.openai.model').').'),
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
            ]);
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
            Mail::to($email)->send(new TestSmtpEmail());

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

        Setting::set(Setting::OPENAI_PROMPT_DIRECTIVES, $data[Setting::OPENAI_PROMPT_DIRECTIVES] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_SUMMARY, $data[Setting::OPENAI_DIRECTIVE_SUMMARY] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, $data[Setting::OPENAI_DIRECTIVE_VET_SUMMARY] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_PHYLA, $data[Setting::OPENAI_DIRECTIVE_PHYLA] ?? '');
        Setting::set(Setting::OPENAI_DIRECTIVE_SCORES, $data[Setting::OPENAI_DIRECTIVE_SCORES] ?? '');
        Setting::set(Setting::SIGNS_OF_STABILITY, $data[Setting::SIGNS_OF_STABILITY] ?? '');

        // Plans / Generation — store verbatim; blanks are tolerated because
        // every consumer falls back to its own default when the value is blank.
        Setting::set(Setting::PLAN_GENERATION_MODEL, $data[Setting::PLAN_GENERATION_MODEL] ?? '');
        Setting::set(Setting::PLAN_GENERATION_TEMPERATURE, $data[Setting::PLAN_GENERATION_TEMPERATURE] ?? '');
        Setting::set(Setting::PLAN_GENERATION_SYSTEM_PROMPT, $data[Setting::PLAN_GENERATION_SYSTEM_PROMPT] ?? '');
        Setting::set(Setting::DEFAULT_DOSE, $data[Setting::DEFAULT_DOSE] ?? '');
        Setting::set(Setting::SUBSCRIPTIONS_ENABLED, ! empty($data[Setting::SUBSCRIPTIONS_ENABLED]) ? '1' : '0');
        Setting::set(Setting::CURRENCY, $data[Setting::CURRENCY] ?? '');

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
            Setting::OPENAI_PROMPT_DIRECTIVES => Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES, ''),
            Setting::OPENAI_DIRECTIVE_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_PHYLA => Setting::get(Setting::OPENAI_DIRECTIVE_PHYLA, ''),
            Setting::OPENAI_DIRECTIVE_SCORES => Setting::get(Setting::OPENAI_DIRECTIVE_SCORES, ''),
            Setting::SIGNS_OF_STABILITY => Setting::get(Setting::SIGNS_OF_STABILITY, ''),
            ...$this->loadPlansGeneration(),
            ...$this->loadKlaviyo(),
            ...$this->loadSmtp(),
            'product_rules' => $this->loadRules(),
        ]);
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
