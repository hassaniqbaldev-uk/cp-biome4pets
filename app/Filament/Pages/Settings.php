<?php

namespace App\Filament\Pages;

use App\Models\ProductRule;
use App\Models\Setting;
use App\Services\OpenAiService;
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

    public function mount(): void
    {
        // Never prefill the secret key into the form — it stays masked/blank.
        $this->form->fill([
            Setting::OPENAI_PROMPT_DIRECTIVES => Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES, ''),
            Setting::OPENAI_DIRECTIVE_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_PHYLA => Setting::get(Setting::OPENAI_DIRECTIVE_PHYLA, ''),
            Setting::OPENAI_DIRECTIVE_SCORES => Setting::get(Setting::OPENAI_DIRECTIVE_SCORES, ''),
            Setting::SIGNS_OF_STABILITY => Setting::get(Setting::SIGNS_OF_STABILITY, ''),
            ...$this->loadPlansGeneration(),
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

    public function save(): void
    {
        $data = $this->form->getState();

        // Only overwrite the API key if a new value was actually entered,
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

        $this->saveRules($data['product_rules'] ?? []);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();

        // Reset the form: clear the secret field, reload persisted values.
        $this->form->fill([
            Setting::OPENAI_PROMPT_DIRECTIVES => Setting::get(Setting::OPENAI_PROMPT_DIRECTIVES, ''),
            Setting::OPENAI_DIRECTIVE_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_VET_SUMMARY => Setting::get(Setting::OPENAI_DIRECTIVE_VET_SUMMARY, ''),
            Setting::OPENAI_DIRECTIVE_PHYLA => Setting::get(Setting::OPENAI_DIRECTIVE_PHYLA, ''),
            Setting::OPENAI_DIRECTIVE_SCORES => Setting::get(Setting::OPENAI_DIRECTIVE_SCORES, ''),
            Setting::SIGNS_OF_STABILITY => Setting::get(Setting::SIGNS_OF_STABILITY, ''),
            ...$this->loadPlansGeneration(),
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
