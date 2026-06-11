<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\KlaviyoService;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Email & Integrations settings.
 *
 * Mirrors App\Filament\Pages\Settings conventions exactly (tabbed form, one
 * Tabs\Tab per method, statePath 'data', mount()/save() shape) so the two
 * screens stay consistent. Phase 1 ships the Klaviyo tab only; the SMTP and
 * Platform Emails tabs are inert "Coming soon" placeholders, each in its own
 * method so they can be filled in later without reworking this class.
 */
class EmailIntegrations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Email & Integrations';

    protected static ?string $title = 'Email & Integrations';

    /**
     * Grouped under "System", alongside Settings.
     */
    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.pages.email-integrations';

    public ?array $data = [];

    /**
     * Live connection-test outcome for this page session: ['ok' => bool,
     * 'account' => ?string]. Null until Test connection is clicked.
     */
    public ?array $connectionState = null;

    public function mount(): void
    {
        // Never prefill the secret key into the form — it stays masked/blank.
        $this->form->fill($this->loadKlaviyo());
    }

    /**
     * The Klaviyo tab values, loaded from settings with revision / base URL
     * pre-filled to their *_DEFAULT so a fresh install shows the verified
     * defaults. The API key is deliberately omitted (kept masked/blank).
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Email & Integrations')
                    ->persistTabInQueryString()
                    ->tabs([
                        $this->klaviyoTab(),
                        $this->smtpTab(),
                        $this->platformEmailsTab(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * The Klaviyo tab — server-side Events API config. The private key reuses
     * the OpenAI key pattern: masked password field, never prefilled, and only
     * overwritten on save when a new value is entered.
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
                    ->helperText('Test connection and Send test event use the SAVED key/settings. If you just changed them, Save first.'),

                Actions::make([
                    Action::make('test_connection')
                        ->label('Test connection')
                        ->icon('heroicon-o-signal')
                        ->color('gray')
                        ->disabled(fn (): bool => ! $this->klaviyoReady())
                        ->tooltip(fn (): ?string => $this->notReadyReason())
                        ->action(fn () => $this->runTestConnection()),

                    Action::make('send_test_event')
                        ->label('Send test event')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->disabled(fn (): bool => ! $this->klaviyoReady())
                        ->tooltip(fn (): ?string => $this->notReadyReason())
                        ->modalHeading('Send a sample "Report Published" event')
                        ->modalDescription('Fires a dummy report_published event so you can confirm it lands in Klaviyo before any template exists.')
                        ->modalSubmitActionLabel('Send test event')
                        ->form([
                            TextInput::make('test_email')
                                ->label('Send to email')
                                ->email()
                                ->required()
                                ->default(auth()->user()?->email)
                                ->helperText('The dummy event is attached to this Klaviyo profile.'),
                        ])
                        ->action(fn (array $data) => $this->runSendTestEvent($data['test_email'] ?? null)),
                ]),

                Placeholder::make('klaviyo_actions_hint')
                    ->label('')
                    ->content(fn (): HtmlString => new HtmlString('<span style="color:#b45309;">'.e((string) $this->notReadyReason()).'</span>'))
                    ->visible(fn (): bool => ! $this->klaviyoReady()),

                Placeholder::make('klaviyo_last_result')
                    ->label('Last Klaviyo result')
                    ->content(fn (): HtmlString => $this->lastResultContent()),
            ]);
    }

    /**
     * True when Klaviyo is enabled AND a key is saved — i.e. the diagnostic
     * actions can actually call the API. Reads SAVED settings, not the form.
     */
    protected function klaviyoReady(): bool
    {
        return filter_var(Setting::get(Setting::KLAVIYO_ENABLED), FILTER_VALIDATE_BOOLEAN)
            && filled(Setting::get(Setting::KLAVIYO_API_KEY));
    }

    /**
     * Why the diagnostic actions are disabled, or null when they're ready.
     */
    protected function notReadyReason(): ?string
    {
        if (blank(Setting::get(Setting::KLAVIYO_API_KEY))) {
            return 'Add a Private API Key and Save first.';
        }

        if (! filter_var(Setting::get(Setting::KLAVIYO_ENABLED), FILTER_VALIDATE_BOOLEAN)) {
            return 'Enable Klaviyo (toggle on) and Save first.';
        }

        return null;
    }

    /**
     * Run KlaviyoService::testConnection() against the SAVED settings, update
     * the live status indicator, persist the result, and notify.
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
     * Persist the most recent diagnostic outcome as JSON so it survives reloads.
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
     * The persisted "last result" line (timestamp + ok/fail + message).
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
     * SMTP tab — placeholder only (Phase 1). Disabled fields show the intended
     * structure so it can be wired up later without reworking this screen.
     */
    protected function smtpTab(): Tabs\Tab
    {
        return Tabs\Tab::make('SMTP')
            ->icon('heroicon-o-server-stack')
            ->schema([
                Placeholder::make('smtp_coming_soon')
                    ->label('')
                    ->content('Coming soon — outbound SMTP configuration is not available yet.'),
                TextInput::make('smtp_host_preview')
                    ->label('SMTP Host')
                    ->placeholder('Coming soon')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('smtp_port_preview')
                    ->label('SMTP Port')
                    ->placeholder('Coming soon')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }

    /**
     * Platform Emails tab — placeholder only (Phase 1). Same inert pattern as
     * the SMTP tab.
     */
    protected function platformEmailsTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Platform Emails')
            ->icon('heroicon-o-inbox-stack')
            ->schema([
                Placeholder::make('platform_emails_coming_soon')
                    ->label('')
                    ->content('Coming soon — transactional / platform email templates are not available yet.'),
                TextInput::make('platform_from_name_preview')
                    ->label('From Name')
                    ->placeholder('Coming soon')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('platform_from_email_preview')
                    ->label('From Email')
                    ->placeholder('Coming soon')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Only overwrite the API key if a new value was actually entered, so
        // saving other settings never wipes the stored key (same guard as the
        // OpenAI key in Settings).
        if (filled($data[Setting::KLAVIYO_API_KEY] ?? null)) {
            Setting::setEncrypted(Setting::KLAVIYO_API_KEY, $data[Setting::KLAVIYO_API_KEY]);
        }

        Setting::set(Setting::KLAVIYO_ENABLED, ! empty($data[Setting::KLAVIYO_ENABLED]) ? '1' : '0');
        // Store verbatim; blanks are tolerated — consumers fall back to *_DEFAULT.
        Setting::set(Setting::KLAVIYO_REVISION, $data[Setting::KLAVIYO_REVISION] ?? '');
        Setting::set(Setting::KLAVIYO_BASE_URL, $data[Setting::KLAVIYO_BASE_URL] ?? '');

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();

        // Reset the form: clear the secret field, reload persisted values.
        $this->form->fill($this->loadKlaviyo());
    }
}
